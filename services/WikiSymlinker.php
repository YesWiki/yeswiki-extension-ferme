<?php

namespace YesWiki\Ferme\Service;

/**
 * Replaces a wiki's copy of the farm's code by a link to the master's, and puts it
 * back the other way round.
 *
 * A copy is only replaced when it holds exactly what the master holds, same paths
 * and same sizes: a wiki somebody has patched keeps its files and is reported. The
 * wiki's own folders — custom, files, private, cache and the extensions it installed
 * itself — are never touched.
 */
class WikiSymlinker
{
    public const GUARD_LINK = 'tools/ferme-client';
    public const GUARD_SOURCE = 'tools/ferme/client';

    /**
     * What a package install writes and nobody edits: the release marker, the
     * autoloader composer regenerates, and the serialized definitions HTMLPurifier
     * once left inside its own library. A wiki created before the master gained
     * them is not a modified wiki, and there are three thousand of those.
     */
    public const GENERATED = '#^(infos\.json|(vendor/)?autoload\.php|(vendor/)?composer/)|DefinitionCache/Serializer/#';

    private $wiki;
    private $config;
    private $files;
    private $lock;
    private $hibernator;
    private $refresher;

    public function __construct(\YesWiki\Wiki $wiki, FarmConfig $config, FileSystem $files, FolderLock $lock, WikiHibernator $hibernator, StatsRefresher $refresher)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->lock = $lock;
        $this->hibernator = $hibernator;
        $this->refresher = $refresher;
    }

    /**
     * The entries the farm lends, from its own configuration.
     *
     * @return array<int,string>
     */
    public function entries(): array
    {
        $entries = $this->wiki->config['yeswiki-farm-lent-files'] ?? [];

        return is_array($entries) ? array_values(array_filter(array_map('strval', $entries))) : [];
    }

    /**
     * What linking this wiki would do, entry by entry, without touching anything.
     *
     * @return array<int,array{entry:string,action:string,bytes:int,why:string}>
     */
    public function inspect(string $wikiDir): array
    {
        $source = rtrim((string)realpath(getcwd()), DIRECTORY_SEPARATOR);
        $wikiDir = rtrim($wikiDir, DIRECTORY_SEPARATOR);
        $plan = [];

        foreach ($this->entries() as $entry) {
            $plan[] = $this->look($source, $wikiDir, $entry);
        }

        $plan[] = $this->lookGuard($wikiDir);

        return $plan;
    }

    /**
     * @return array{linked:int,freed:int,kept:int,steps:array<int,array<string,mixed>>}
     */
    public function link(string $wikiDir, bool $dryRun = true): array
    {
        return $this->apply($wikiDir, $dryRun, 'link');
    }

    /**
     * @return array{linked:int,freed:int,kept:int,steps:array<int,array<string,mixed>>}
     */
    public function unlink(string $wikiDir, bool $dryRun = true): array
    {
        return $this->apply($wikiDir, $dryRun, 'unlink');
    }

    /**
     * @return array{linked:int,freed:int,kept:int,steps:array<int,array<string,mixed>>}
     */
    private function apply(string $wikiDir, bool $dryRun, string $way): array
    {
        $source = rtrim((string)realpath(getcwd()), DIRECTORY_SEPARATOR);
        $wikiDir = rtrim($wikiDir, DIRECTORY_SEPARATOR);

        if ($wikiDir === $source) {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED'));
        }

        if (!$dryRun) {
            $this->hibernator->refuseIfAsleepIn($wikiDir);
        }

        return $this->lock->during($wikiDir, _t('FERME_LOCK_SYMLINK'), function () use ($source, $wikiDir, $dryRun, $way) {
            $steps = $way === 'link' ? $this->inspect($wikiDir) : $this->inspectUnlink($source, $wikiDir);
            $linked = $freed = $kept = 0;

            foreach ($steps as $step) {
                if ($step['action'] === 'keep') {
                    $kept++;

                    continue;
                }
                $linked++;
                $freed += $step['bytes'];
                if (!$dryRun) {
                    $this->run($source, $wikiDir, $step);
                }
            }

            if (!$dryRun && $linked > 0) {
                $this->refresher->remeasure(basename($wikiDir));
            }

            return ['linked' => $linked, 'freed' => $freed, 'kept' => $kept, 'steps' => $steps];
        });
    }

    /**
     * @param array<string,mixed> $step
     */
    private function run(string $source, string $wikiDir, array $step): void
    {
        $entry = (string)$step['entry'];
        $from = $source . DIRECTORY_SEPARATOR . ($entry === self::GUARD_LINK ? self::GUARD_SOURCE : $entry);
        $to = $wikiDir . DIRECTORY_SEPARATOR . $entry;

        if ($step['action'] === 'drop') {
            $this->files->remove($to);

            return;
        }

        if ($step['action'] === 'unlink') {
            $this->files->remove($to);
            if (!$this->files->copyRecursive($from, $to)) {
                throw new \RuntimeException(_t('FERME_COPY_INCOMPLETE') . ' ' . $entry . ' : ' . $this->files->failureSummary());
            }

            return;
        }

        $this->files->remove($to);
        if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . dirname($to));
        }
        if (!symlink($from, $to)) {
            throw new \RuntimeException(_t('FERME_SYMLINK_FAILED') . ' ' . $to);
        }
    }

    /**
     * @return array{entry:string,action:string,bytes:int,why:string}
     */
    private function look(string $source, string $wikiDir, string $entry): array
    {
        $master = $source . DIRECTORY_SEPARATOR . $entry;
        $theirs = $wikiDir . DIRECTORY_SEPARATOR . $entry;

        if (is_link($theirs)) {
            return $this->step($entry, 'keep', 0, 'FERME_SYMLINK_ALREADY');
        }
        if (!file_exists($master)) {
            return $this->step($entry, 'keep', 0, 'FERME_EXTRA_MISSING');
        }
        if (!file_exists($theirs)) {
            return $this->step($entry, 'link', 0, 'FERME_SYMLINK_ABSENT');
        }

        $mine = self::inventory($master);
        $its = self::inventory($theirs);
        if (self::comparable($mine) !== self::comparable($its)) {
            return $this->step($entry, 'keep', 0, 'FERME_SYMLINK_DIFFERENT');
        }

        return $this->step($entry, 'link', array_sum($its), 'FERME_SYMLINK_SAME');
    }

    /**
     * @return array{entry:string,action:string,bytes:int,why:string}
     */
    private function lookGuard(string $wikiDir): array
    {
        $link = $wikiDir . DIRECTORY_SEPARATOR . self::GUARD_LINK;

        return is_link($link)
            ? $this->step(self::GUARD_LINK, 'keep', 0, 'FERME_SYMLINK_ALREADY')
            : $this->step(self::GUARD_LINK, 'link', 0, 'FERME_SYMLINK_GUARD');
    }

    /**
     * @return array<int,array{entry:string,action:string,bytes:int,why:string}>
     */
    private function inspectUnlink(string $source, string $wikiDir): array
    {
        $steps = [];
        foreach (array_merge($this->entries(), [self::GUARD_LINK]) as $entry) {
            $theirs = $wikiDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_link($theirs)) {
                $steps[] = $this->step($entry, 'keep', 0, 'FERME_SYMLINK_NOT_LINKED');

                continue;
            }
            if ($entry === self::GUARD_LINK) {
                $steps[] = $this->step($entry, 'drop', 0, 'FERME_SYMLINK_GUARD');

                continue;
            }
            $steps[] = $this->step($entry, 'unlink', 0, 'FERME_SYMLINK_COPY_BACK');
        }

        return $steps;
    }

    /**
     * Every file under a path with its size, relative to it, sorted: two folders
     * holding the same names and the same sizes are the same release.
     *
     * @return array<string,int>
     */
    public static function inventory(string $path): array
    {
        if (is_file($path)) {
            return [basename($path) => (int)filesize($path)];
        }
        if (!is_dir($path)) {
            return [];
        }

        $found = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $cut = strlen(rtrim($path, DIRECTORY_SEPARATOR)) + 1;
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $found[substr($file->getPathname(), $cut)] = (int)$file->getSize();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The inventory without what a package install generates.
     *
     * @param array<string,int> $inventory
     *
     * @return array<string,int>
     */
    public static function comparable(array $inventory): array
    {
        return array_filter($inventory, function (string $path) {
            return preg_match(self::GENERATED, $path) !== 1;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{entry:string,action:string,bytes:int,why:string}
     */
    private function step(string $entry, string $action, int $bytes, string $why): array
    {
        return ['entry' => $entry, 'action' => $action, 'bytes' => $bytes, 'why' => $why];
    }
}
