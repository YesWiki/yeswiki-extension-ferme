<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Admin\Service\ArchiveService;
use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Files\Service\LocalFiles;

/** Makes a wiki's own archive with its `core:archive`, hands it over, and throws it away once nobody came for it. */
class WikiArchiver
{
    public const KEEP_ARCHIVES = 604800;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceConsole $console,
        private readonly FolderLock $lock,
        private readonly WikiHibernator $hibernator,
        private readonly StatsRefresher $refresher,
        private readonly LocalFiles $localFiles
    ) {
    }

    /** @return array{file: string, bytes: int, replaced: int} */
    public function create(string $folder, bool $replace = true): array
    {
        if (!$this->config->hasWiki($folder)) {
            throw new WikiStatsException($folder, _t('FERME_NO_WIKI_IN') . ' ' . $folder);
        }
        $dir = $this->config->wikiDir($folder);

        return $this->lock->during($dir, _t('FERME_LOCK_ARCHIVE'), function () use ($folder, $dir, $replace): array {
            $replaced = $replace ? $this->forget($folder, array_column($this->archives($folder), 'file')) : 0;
            $this->localFiles->makeDirectory($this->folderOf($folder));
            $output = $this->hibernator->whileAwake($folder, fn (): string => $this->console->mustRun($dir, ['core:archive']));
            if (str_contains($output, 'STOP')) {
                throw new WikiStatsException($folder, $output);
            }
            $made = $this->archives($folder);
            if ($made === []) {
                throw new WikiStatsException($folder, _t('FERME_ARCHIVE_NOT_FOUND'));
            }
            $this->refresher->remeasure($folder);

            return ['file' => $made[0]['file'], 'bytes' => $made[0]['bytes'], 'replaced' => $replaced];
        });
    }

    /**
     * Throw out the archives nobody came to fetch.
     *
     * @return array{removed: int, bytes: int}
     */
    public function tidy(string $folder, int $olderThan = self::KEEP_ARCHIVES): array
    {
        $removed = 0;
        $bytes = 0;
        $deadline = time() - max(3600, $olderThan);
        foreach ($this->archives($folder) as $archive) {
            if ($archive['time'] <= $deadline && $this->localFiles->remove($archive['path'])) {
                $removed++;
                $bytes += $archive['bytes'];
            }
        }

        return ['removed' => $removed, 'bytes' => $bytes];
    }

    /**
     * The archives a wiki holds, newest first.
     *
     * @return list<array{file: string, path: string, bytes: int, time: int}>
     */
    public function archives(string $folder): array
    {
        $found = [];
        foreach ($this->localFiles->matching($this->folderOf($folder) . '/*.zip') as $path) {
            $found[] = ['file' => basename($path), 'path' => $path, 'bytes' => $this->localFiles->size($path), 'time' => $this->localFiles->modifiedAt($path)];
        }
        usort($found, static fn (array $left, array $right): int => [$right['time'], $right['file']] <=> [$left['time'], $left['file']]);

        return $found;
    }

    /**
     * @param list<string> $files
     *
     * @return int how many were removed
     */
    public function forget(string $folder, array $files): int
    {
        $gone = 0;
        foreach ($files as $file) {
            $path = $this->pathOf($folder, $file);
            if ($path !== null && $this->localFiles->remove($path)) {
                $gone++;
            }
        }

        return $gone;
    }

    /** The archive of a wiki by name, or null when the name is not one of its archives. */
    public function pathOf(string $folder, string $file): ?string
    {
        $file = basename(trim($file));
        if ($file === '' || !str_ends_with(strtolower($file), '.zip')) {
            return null;
        }
        $path = $this->folderOf($folder) . '/' . $file;

        return $this->localFiles->isFile($path) ? $path : null;
    }

    private function folderOf(string $folder): string
    {
        return $this->config->wikiDir($folder) . '/' . ArchiveService::PRIVATE_FOLDER_NAME_IN_ZIP;
    }
}
