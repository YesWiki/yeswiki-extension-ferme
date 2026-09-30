<?php
// Brings a farm wiki to the state of a source tree: the master by default, or an unpacked release.

namespace YesWiki\Ferme\Service;

use Symfony\Component\Process\Process;
use YesWiki\Wiki;

class WikiUpdater
{
    private const REMOVED_TOOLS = ['tools/despam', 'tools/hashcash', 'tools/ipblock', 'tools/nospam'];
    private const CONSOLE = 'includes/commands/console';
    private const PROCESS_TIMEOUT = 600;

    protected $wiki;
    protected $config;
    protected $files;
    protected $editor;
    protected $database;
    protected $aside;
    protected $extensions;
    protected $lock;
    protected $hibernator;
    protected $refresher;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        FileSystem $files,
        WikiConfigEditor $editor,
        WikiDatabase $database,
        CustomAside $aside,
        ExtensionVersions $extensions,
        FolderLock $lock,
        WikiHibernator $hibernator,
        StatsRefresher $refresher
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->editor = $editor;
        $this->database = $database;
        $this->aside = $aside;
        $this->extensions = $extensions;
        $this->lock = $lock;
        $this->hibernator = $hibernator;
        $this->refresher = $refresher;
    }

    // brings one wiki to the state of the source tree: core files, lent folders as symlinks, extensions and migrations
    public function update(string $wikiDir, array $options = []): array
    {
        $wikiDir = rtrim($wikiDir, DIRECTORY_SEPARATOR);
        $sourceDir = rtrim($options['sourceDir'] ?? getcwd(), DIRECTORY_SEPARATOR);
        $backup = $options['backup'] ?? true;
        $dryRun = $options['dryRun'] ?? false;
        $migrateOnly = $options['migrateOnly'] ?? false;

        if ($wikiDir === rtrim((string)realpath(getcwd()), DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED'));
        }

        if (!$dryRun) {
            $this->hibernator->refuseIfBusyIn($wikiDir);
        }

        return $this->lock->during($wikiDir, _t('FERME_LOCK_UPDATE'), function () use ($wikiDir, $sourceDir, $backup, $dryRun, $migrateOnly, $options) {
            $messages = [];
            $wakkaConfig = $this->editor->load($wikiDir);
            $replace = $migrateOnly ? [] : $this->entriesToReplace();
            $symlink = $migrateOnly ? [] : $this->symlinkedEntries();
            $extras = $this->extraExtensions($sourceDir, $wikiDir);
            [$version, $release] = $this->sourceVersion($sourceDir);
            $sameVersion = strtolower((string)($wakkaConfig['yeswiki_version'] ?? '')) === strtolower($version);
            $skipExtensions = $migrateOnly || ($options['ignoreExtensions'] ?? false);
            $toUpgrade = $skipExtensions ? [] : $this->extensions->toUpgrade($wikiDir, $extras, $version, (string)($wakkaConfig['yeswiki_version'] ?? ''));
            $unpublished = $skipExtensions ? [] : $this->extensions->unpublished($extras, $version);

            if ($dryRun) {
                return ['status' => 'updated', 'messages' => $this->plan($wikiDir, $sourceDir, $replace, $symlink, $toUpgrade, $unpublished, $backup, $migrateOnly)];
            }

            foreach ($unpublished as $extension) {
                $messages[] = $extension . ' ' . _t('FERME_CLI_EXT_NOT_PUBLISHED') . ' ' . $version;
            }

            if (!$migrateOnly) {
                $recovered = $this->aside->recover($wikiDir);
                if ($recovered !== null) {
                    $messages[] = $recovered;
                }
            }

            $backupDir = null;
            if ($backup) {
                $backupDir = $this->prepareBackupDir($wikiDir);
                $messages[] = _t('FERME_CLI_BACKUP_IN') . ' ' . $backupDir;
                try {
                    $messages[] = $this->dumpDatabase($wakkaConfig, $backupDir);
                } catch (\Throwable $th) {
                    $this->files->remove($backupDir);

                    throw $th;
                }
            }

            if ($sameVersion) {
                $messages = array_merge($messages, $this->upgradeExtensions($wikiDir, $toUpgrade));
            }

            foreach ($migrateOnly ? [] : self::REMOVED_TOOLS as $entry) {
                $this->displace($wikiDir, $entry, $backupDir);
            }
            $copied = 0;
            foreach ($replace as $entry) {
                $source = $sourceDir . DIRECTORY_SEPARATOR . $entry;
                if (!file_exists($source)) {
                    $messages[] = _t('FERME_EXTRA_MISSING') . ' ' . $entry;

                    continue;
                }
                $this->displace($wikiDir, $entry, $backupDir);
                if ($this->files->copyRecursive($source, $wikiDir . DIRECTORY_SEPARATOR . $entry) !== true) {
                    throw new \RuntimeException(_t('FERME_COPY_INCOMPLETE') . ' ' . $entry . ' : ' . $this->files->failureSummary());
                }
                $copied++;
            }
            foreach ($symlink as $entry) {
                $this->displace($wikiDir, $entry, $backupDir);
                symlink($sourceDir . DIRECTORY_SEPARATOR . $entry, $wikiDir . DIRECTORY_SEPARATOR . $entry);
            }
            if (!$migrateOnly) {
                $messages[] = _t('FERME_CLI_FILES_REPLACED') . ' ' . ($copied + count($symlink));
            }

            $hibernating = ($wakkaConfig['wiki_status'] ?? '') === 'hibernate';
            $this->patch($wikiDir, $hibernating
                ? ['wiki_status' => 'running', 'yeswiki_version' => $version]
                : ['yeswiki_version' => $version]);

            try {
                $messages = array_merge($messages, $this->runMigrations($wikiDir, $sameVersion ? [] : $toUpgrade));
            } finally {
                if ($hibernating) {
                    $this->patch($wikiDir, ['wiki_status' => 'hibernate']);
                }
            }

            $this->patch($wikiDir, ['yeswiki_release' => $release]);
            $messages[] = _t('FERME_CLI_STAMPED') . ' ' . $version . ' ' . $release;
            $this->refresher->remeasure(basename($wikiDir));

            if ($backupDir !== null) {
                $this->files->remove($backupDir);
            }

            return ['status' => 'updated', 'messages' => $messages];
        });
    }

    // brings the extensions of a wiki to the release published for the version it runs, leaving the core alone
    public function updateExtensions(string $wikiDir, array $options = []): array
    {
        $wikiDir = rtrim($wikiDir, DIRECTORY_SEPARATOR);
        $sourceDir = rtrim($options['sourceDir'] ?? getcwd(), DIRECTORY_SEPARATOR);

        if ($wikiDir === rtrim((string)realpath(getcwd()), DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED'));
        }

        $wakkaConfig = $this->editor->load($wikiDir);
        $version = (string)($wakkaConfig['yeswiki_version'] ?? '');
        if ($version === '') {
            $version = (string)$this->wiki->config['yeswiki_version'];
        }

        $extras = $this->extraExtensions($sourceDir, $wikiDir);
        $toUpgrade = $this->extensions->toUpgrade($wikiDir, $extras, $version, $version);

        $messages = [];
        foreach ($this->extensions->unpublished($extras, $version) as $extension) {
            $messages[] = $extension . ' ' . _t('FERME_CLI_EXT_NOT_PUBLISHED') . ' ' . $version;
        }

        if (empty($toUpgrade)) {
            $messages[] = _t('FERME_EXT_ALL_CURRENT');

            return ['status' => 'uptodate', 'messages' => $messages];
        }

        if ($options['dryRun'] ?? false) {
            $messages[] = _t('FERME_CLI_WOULD_UPGRADE_EXTENSIONS') . ' ' . implode(', ', $toUpgrade);

            return ['status' => 'updated', 'messages' => $messages];
        }

        $recovered = $this->aside->recover($wikiDir);
        if ($recovered !== null) {
            $messages[] = $recovered;
        }

        return [
            'status' => 'updated',
            'messages' => array_merge($messages, $this->runMigrations($wikiDir, $toUpgrade)),
        ];
    }

    // returns the version and release of a source tree, from the master config or a release constants.php
    public function sourceVersion(string $sourceDir): array
    {
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        if ($sourceDir === rtrim((string)realpath(getcwd()), DIRECTORY_SEPARATOR)) {
            return [$this->wiki->config['yeswiki_version'], $this->wiki->config['yeswiki_release']];
        }

        $constants = @file_get_contents($sourceDir . '/includes/constants.php');
        if ($constants === false) {
            throw new \RuntimeException(_t('FERME_CLI_NO_CONSTANTS') . ' ' . $sourceDir);
        }

        $read = function (string $name) use ($constants, $sourceDir) {
            if (!preg_match('/define\([\'"]' . $name . '[\'"],\s*[\'"]([^\'"]+)[\'"]\)/', $constants, $matches)) {
                throw new \RuntimeException($name . ' ' . _t('FERME_CLI_NOT_FOUND_IN') . ' ' . $sourceDir);
            }

            return $matches[1];
        };

        return [$read('YESWIKI_VERSION'), $read('YESWIKI_RELEASE')];
    }

    // returns the files of yeswiki_files and the farm extra tools, minus what is symlinked
    private function entriesToReplace(): array
    {
        $symlinked = $this->symlinkedEntries();

        $entries = $this->wiki->config['yeswiki_files'] ?? [];
        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] ?? [] as $tool) {
            $entries[] = 'tools/' . $tool;
        }

        return array_values(array_diff($entries, $symlinked));
    }

    // returns the folders lent to the wikis as symlinks
    private function symlinkedEntries(): array
    {
        $symlinked = $this->wiki->config['yeswiki_symlinked_files'] ?? [];

        return is_array($symlinked) ? $symlinked : [];
    }

    // returns the extensions a wiki has that the source does not ship
    private function extraExtensions(string $sourceDir, string $wikiDir): array
    {
        $names = function (string $dir) {
            return array_map('basename', array_filter(glob($dir . '/tools/*') ?: [], 'is_dir'));
        };

        return array_values(array_diff($names($wikiDir), $names($sourceDir)));
    }

    // moves an entry out of the way, into the backup folder when there is one, without following symlinks
    private function displace(string $wikiDir, string $entry, ?string $backupDir): void
    {
        $target = $wikiDir . DIRECTORY_SEPARATOR . $entry;
        if (!file_exists($target) && !is_link($target)) {
            return;
        }
        if (in_array($entry, $this->wiki->config['yeswiki_empty_folders'] ?? [], true)) {
            return;
        }

        if ($backupDir === null || is_link($target)) {
            $this->files->remove($target);

            return;
        }

        $moved = $backupDir . DIRECTORY_SEPARATOR . $entry;
        $parent = dirname($moved);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $parent);
        }
        if (!rename($target, $moved)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_MOVE') . ' ' . $target);
        }
    }

    // creates a backup folder on the same filesystem as the wiki, so moving files aside stays a rename
    private function prepareBackupDir(string $wikiDir): string
    {
        $slug = str_replace('/', '-', trim((string)(realpath($wikiDir) ?: $wikiDir), '/'));
        $backupDir = $this->config->ensureBackupDir('wikis' . DIRECTORY_SEPARATOR . $slug . '-' . date('Ymd-His'));

        $wikiDevice = @stat($wikiDir)['dev'] ?? null;
        $backupDevice = @stat($backupDir)['dev'] ?? null;
        if ($wikiDevice !== null && $backupDevice !== null && $wikiDevice !== $backupDevice) {
            throw new \RuntimeException(_t('FERME_CLI_BACKUP_OTHER_FILESYSTEM') . ' ' . $this->config->backupDir());
        }

        return $backupDir;
    }

    private function dumpDatabase(array $wakkaConfig, string $backupDir): string
    {
        $prefix = $wakkaConfig['table_prefix'] ?? '';
        $tables = array_map(function ($table) use ($prefix) {
            return $prefix . $table;
        }, WikiDatabase::WIKI_TABLES);

        $host = $wakkaConfig['mysql_host'] ?? 'localhost';
        $port = '';
        if (str_contains($host, ':') && !str_starts_with($host, '/')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $defaults = tempnam(sys_get_temp_dir(), 'ferme-my-');
        chmod($defaults, 0600);
        file_put_contents($defaults, "[client]\n"
            . 'host=' . $this->iniValue($host) . "\n"
            . ($port === '' ? '' : 'port=' . $this->iniValue($port) . "\n")
            . 'user=' . $this->iniValue($wakkaConfig['mysql_user'] ?? '') . "\n"
            . 'password=' . $this->iniValue($wakkaConfig['mysql_password'] ?? '') . "\n");

        $dumpFile = $backupDir . DIRECTORY_SEPARATOR . 'database.sql';
        $command = 'mysqldump --defaults-extra-file=' . escapeshellarg($defaults)
            . ' ' . escapeshellarg($wakkaConfig['mysql_database'] ?? '')
            . ' ' . implode(' ', array_map('escapeshellarg', $tables))
            . ' > ' . escapeshellarg($dumpFile);

        try {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(self::PROCESS_TIMEOUT);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException(_t('FERME_CLI_DUMP_FAILED') . ' ' . trim($process->getErrorOutput()));
            }
        } finally {
            unlink($defaults);
        }

        return _t('FERME_CLI_DUMP_IN') . ' ' . $dumpFile;
    }

    private function iniValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    // upgrades the extensions first, so their migrations are on disk, then runs migrate
    private function runMigrations(string $wikiDir, array $extensions): array
    {
        $messages = [];
        $hidden = $this->aside->hide($wikiDir);

        try {
            $messages = array_merge($messages, $this->upgradeExtensions($wikiDir, $extensions));
            $messages[] = $this->runConsole($wikiDir, ['migrate']);
        } finally {
            if ($hidden) {
                $displaced = $this->aside->reveal($wikiDir);
                if ($displaced !== null) {
                    $messages[] = _t('FERME_CLI_CUSTOM_CONFLICT_MOVED') . ' ' . $displaced;
                }
            }
        }

        return $messages;
    }

    private function runConsole(string $wikiDir, array $arguments): string
    {
        $process = new Process(
            array_merge([PHP_BINARY, self::CONSOLE], $arguments),
            $wikiDir,
            [FarmMigrationWatch::RUNNING => '1']
        );
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(implode(' ', $arguments) . ': ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return implode(' ', $arguments) . ': ' . trim(preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $process->getOutput()));
    }

    // upgrades each extension through the wiki own console
    private function upgradeExtensions(string $wikiDir, array $extensions): array
    {
        $messages = [];
        foreach ($extensions as $extension) {
            $messages[] = $this->runConsole($wikiDir, ['upgrade', $extension]);
        }

        return $messages;
    }

    private function patch(string $wikiDir, array $set): void
    {
        $config = $this->editor->load($wikiDir);
        if (!empty($this->editor->apply($config, $set, []))) {
            $this->editor->write($wikiDir, $config);
        }
    }

    // describes what an update would do, for --dry-run
    private function plan(
        string $wikiDir,
        string $sourceDir,
        array $replace,
        array $symlink,
        array $toUpgrade,
        array $unpublished,
        bool $backup,
        bool $migrateOnly = false
    ): array {
        [$version, $release] = $this->sourceVersion($sourceDir);

        $messages = [];
        if (!$migrateOnly) {
            $messages[] = _t('FERME_CLI_WOULD_COPY') . ' ' . count($replace) . ' ' . _t('FERME_CLI_FROM') . ' ' . $sourceDir;
        }
        if (!empty($symlink)) {
            $messages[] = _t('FERME_CLI_WOULD_SYMLINK') . ' ' . implode(', ', $symlink);
        }
        if ($backup) {
            $messages[] = _t('FERME_CLI_WOULD_BACKUP') . ' ' . $this->config->backupDir();
        }
        $messages[] = _t('FERME_CLI_WOULD_MIGRATE')
            . (empty($toUpgrade) ? '' : ' + ' . _t('FERME_CLI_WOULD_UPGRADE') . ' ' . implode(', ', $toUpgrade));
        $messages[] = _t('FERME_CLI_WOULD_STAMP') . ' ' . $version . ' ' . $release;
        foreach ($unpublished as $extension) {
            $messages[] = $extension . ' ' . _t('FERME_CLI_EXT_NOT_PUBLISHED') . ' ' . $version;
        }
        if (!$migrateOnly && $this->aside->isAside($wikiDir)) {
            $messages[] = _t('FERME_CLI_WOULD_RECOVER_CUSTOM');
        }

        return $messages;
    }
}
