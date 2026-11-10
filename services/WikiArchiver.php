<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\Process\Process;
use YesWiki\Ferme\Exception\WikiStatsException;

// Makes a wiki's own backup from the farm, hands it over, and takes it away once it has been handed over
class WikiArchiver
{
    public const PRIVATE_FOLDER = 'private/backups';
    public const TIMEOUT = 1800;
    public const KEEP_ARCHIVES = 604800;
    public const CONFIG_IN_ZIP = 'wakka.config.php';

    private $config;
    private $lock;
    private $hibernator;
    private $refresher;

    public function __construct(FarmConfig $config, FolderLock $lock, WikiHibernator $hibernator, StatsRefresher $refresher)
    {
        $this->config = $config;
        $this->lock = $lock;
        $this->hibernator = $hibernator;
        $this->refresher = $refresher;
    }

    public function create(string $folder): array
    {
        $dir = rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR);
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'yeswicli')) {
            throw new WikiStatsException($folder, _t('FERME_ARCHIVE_NO_CONSOLE'));
        }

        return $this->lock->during($dir, _t('FERME_LOCK_ARCHIVE'), function () use ($folder, $dir) {
            $this->makeRoomFor($folder);

            $before = $this->archives($folder);
            $replaced = $this->forget($folder, array_column($before, 'file'));

            $asleep = WikiHibernator::isAsleep((string)($this->config->readWikiConfig($folder)['wiki_status'] ?? ''));
            if ($asleep) {
                $this->hibernator->wake($folder);
            }

            try {
                $process = new Process(['php', 'includes/commands/console', 'core:archive'], $dir);
                $process->setTimeout(self::TIMEOUT);
                $process->run();
            } finally {
                if ($asleep) {
                    $this->hibernator->hibernate($folder);
                }
            }

            if (!$process->isSuccessful() || str_contains($process->getOutput(), 'STOP')) {
                throw new WikiStatsException($folder, trim($process->getErrorOutput() . ' ' . $process->getOutput()));
            }

            $made = $this->archives($folder);
            if ($made === []) {
                throw new WikiStatsException($folder, _t('FERME_ARCHIVE_NOT_FOUND'));
            }

            $latest = $made[0];
            $this->wakeInside($latest['path']);
            $this->refresher->remeasure($folder);

            return ['file' => $latest['file'], 'bytes' => (int)filesize($latest['path']), 'replaced' => $replaced];
        });
    }

    // Housekeeping for the statistics sweep: makes the missing `private` folders and drops archives nobody fetched.
    public function tidy(string $folder, int $olderThan = self::KEEP_ARCHIVES): array
    {
        $created = $this->ensurePrivate($folder);

        $removed = 0;
        $bytes = 0;
        $deadline = time() - max(3600, $olderThan);
        foreach ($this->archives($folder) as $archive) {
            if ($archive['time'] > $deadline) {
                continue;
            }
            if (@unlink($archive['path'])) {
                $removed++;
                $bytes += $archive['bytes'];
            }
        }

        return ['created' => $created, 'removed' => $removed, 'bytes' => $bytes];
    }

    // Gives a farm wiki the `private` folder and the archive folder core needs; true if it made one
    public function ensurePrivate(string $folder): bool
    {
        $dir = rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            return false;
        }

        return self::ensurePrivateIn($dir, $this->config->readWikiConfig($folder));
    }

    // Same as ensurePrivate() for a wiki given by its directory and its wakka config
    public static function ensurePrivateIn(string $dir, array $wakkaConfig): bool
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $created = false;
        foreach ([$dir . DIRECTORY_SEPARATOR . 'private', self::archiveFolderIn($dir, $wakkaConfig)] as $path) {
            if (!is_dir($path) && @mkdir($path, 0755, true)) {
                $created = true;
            }
        }

        $htaccess = $dir . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_dir(dirname($htaccess)) && !is_file($htaccess)) {
            @file_put_contents($htaccess, "DENY FROM ALL\n");
        }

        return $created;
    }

    // The archives a wiki holds, newest first
    public function archives(string $folder): array
    {
        $dir = $this->folderOf($folder);
        $found = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) {
            $found[] = [
                'file' => basename($path),
                'path' => $path,
                'bytes' => (int)filesize($path),
                'time' => (int)filemtime($path),
            ];
        }

        usort($found, function (array $left, array $right) {
            return $right['time'] <=> $left['time'];
        });

        return $found;
    }

    public function forget(string $folder, array $files): int
    {
        $gone = 0;
        foreach ($files as $file) {
            $path = $this->pathOf($folder, $file);
            if ($path !== null && @unlink($path)) {
                $gone++;
            }
        }

        return $gone;
    }

    // The archive of a wiki, by name, or null when the name does not belong to it
    public function pathOf(string $folder, string $file): ?string
    {
        $file = basename(trim($file));
        if ($file === '' || !str_ends_with(strtolower($file), '.zip')) {
            return null;
        }

        $path = $this->folderOf($folder) . DIRECTORY_SEPARATOR . $file;

        return is_file($path) ? $path : null;
    }

    // A wiki put to sleep comes back awake from its archive.
    private function wakeInside(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        $config = $zip->getFromName(self::CONFIG_IN_ZIP);
        if (!is_string($config) || $config === '') {
            $zip->close();

            return false;
        }

        $awake = preg_replace('/^\s*[\'"]wiki_status[\'"]\s*=>.*,\s*$\n?/mi', '', $config);
        if ($awake === null || $awake === $config) {
            $zip->close();

            return false;
        }

        $zip->addFromString(self::CONFIG_IN_ZIP, $awake);
        $zip->close();

        return true;
    }

    // Creates the archive folder before archiving: core refuses to archive into a missing one.
    private function makeRoomFor(string $folder): void
    {
        $dir = $this->folderOf($folder);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new WikiStatsException($folder, _t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $dir);
        }
    }

    private function folderOf(string $folder): string
    {
        return self::archiveFolderIn(rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR), $this->config->readWikiConfig($folder));
    }

    // The folder core archives a wiki into: its archive.privatePath, private/backups by default.
    private static function archiveFolderIn(string $dir, array $wakkaConfig): string
    {
        $private = (string)($wakkaConfig['archive']['privatePath'] ?? '');
        if ($private === '' || $private === '%TMP') {
            $private = self::PRIVATE_FOLDER;
        }

        return str_starts_with($private, DIRECTORY_SEPARATOR)
            ? rtrim($private, DIRECTORY_SEPARATOR)
            : $dir . DIRECTORY_SEPARATOR . rtrim($private, DIRECTORY_SEPARATOR);
    }
}
