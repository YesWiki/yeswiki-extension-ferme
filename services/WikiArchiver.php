<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\Process\Process;
use YesWiki\Ferme\Exception\WikiStatsException;

/**
 * Makes a wiki's own backup from the farm, hands it over, and takes it away once
 * it has been handed over.
 *
 * The wiki archives itself through its own console, so nothing here needs an
 * account on it. A sleeping wiki is archived like any other — and wakes up inside
 * its archive, because whoever restores it wants a wiki that works.
 */
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

    /**
     * @return array{file:string,bytes:int,replaced:int}
     */
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

    /**
     * Housekeeping, run by the statistics sweep: every wiki gets the `private`
     * folder it is supposed to have, and the archives nobody came to fetch are
     * thrown out. No lock is taken — making a missing folder and deleting a
     * week-old file are both harmless whoever else is working on the wiki.
     *
     * @return array{created:bool,removed:int,bytes:int}
     */
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

    /**
     * The `private` folder every wiki is supposed to have, and the one core wants
     * its archives in — which it refuses to create itself.
     */
    public function ensurePrivate(string $folder): bool
    {
        $dir = rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            return false;
        }

        $created = false;
        foreach ([$dir . DIRECTORY_SEPARATOR . 'private', $this->folderOf($folder)] as $path) {
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

    /**
     * The archives a wiki holds, newest first.
     *
     * @return array<int,array{file:string,path:string,bytes:int,time:int}>
     */
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

    /**
     * @param array<int,string> $files
     *
     * @return int how many were removed
     */
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

    /**
     * The archive of a wiki, by name, or null when the name does not belong to it.
     */
    public function pathOf(string $folder, string $file): ?string
    {
        $file = basename(trim($file));
        if ($file === '' || !str_ends_with(strtolower($file), '.zip')) {
            return null;
        }

        $path = $this->folderOf($folder) . DIRECTORY_SEPARATOR . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * A wiki put to sleep comes back awake from its archive: `wiki_status` is taken
     * out of the copy of the configuration the zip carries, and out of that copy
     * only — the wiki itself goes on sleeping.
     */
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

    /**
     * A wiki refuses to archive itself when the folder its archives go in does not
     * exist yet, and a wiki the farm made has an empty `private/`. One mkdir.
     */
    private function makeRoomFor(string $folder): void
    {
        $dir = $this->folderOf($folder);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new WikiStatsException($folder, _t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $dir);
        }
    }

    private function folderOf(string $folder): string
    {
        $dir = rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR);
        $wakkaConfig = $this->config->readWikiConfig($folder);
        $private = (string)($wakkaConfig['archive']['privatePath'] ?? '');
        if ($private === '' || $private === '%TMP') {
            $private = self::PRIVATE_FOLDER;
        }

        return str_starts_with($private, DIRECTORY_SEPARATOR)
            ? rtrim($private, DIRECTORY_SEPARATOR)
            : $dir . DIRECTORY_SEPARATOR . rtrim($private, DIRECTORY_SEPARATOR);
    }
}
