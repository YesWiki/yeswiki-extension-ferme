<?php

namespace YesWiki\Ferme\Service;

/**
 * Puts a wiki's custom/ folder out of the way while its migrations run, so they
 * see a stock wiki, and makes sure it comes back: at the end of the update, on a
 * fatal, on a signal, or on the next run of the farm over that wiki.
 */
class CustomAside
{
    public const ASIDE = 'custom.temp';
    public const SENTINEL = '.ferme-aside';

    private $config;
    private $files;
    private $pending = [];
    private $watching = false;

    public function __construct(FarmConfig $config, FileSystem $files)
    {
        $this->config = $config;
        $this->files = $files;
    }

    /**
     * @return bool false when the wiki has no custom/ to hide
     */
    public function hide(string $wikiDir): bool
    {
        $custom = $this->customPath($wikiDir);
        if (!file_exists($custom) && !is_link($custom)) {
            return false;
        }

        $aside = $this->asidePath($wikiDir);
        if (file_exists($aside) || is_link($aside)) {
            throw new \RuntimeException(_t('FERME_CLI_CUSTOM_ASIDE_IN_THE_WAY') . ' ' . $aside);
        }
        if (!rename($custom, $aside)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_MOVE') . ' ' . $custom);
        }

        if (is_dir($aside) && !is_link($aside)) {
            file_put_contents($aside . DIRECTORY_SEPARATOR . self::SENTINEL, json_encode([
                'wiki' => $this->key($wikiDir),
                'since' => date('c'),
                'pid' => getmypid(),
            ]));
        }

        $this->pending[$this->key($wikiDir)] = true;
        $this->watch();

        return true;
    }

    /**
     * @return string|null where a custom/ found back in its place has been moved
     */
    public function reveal(string $wikiDir): ?string
    {
        unset($this->pending[$this->key($wikiDir)]);

        $aside = $this->asidePath($wikiDir);
        if (!file_exists($aside) && !is_link($aside)) {
            return null;
        }

        $custom = $this->customPath($wikiDir);
        $displaced = (file_exists($custom) || is_link($custom)) ? $this->displace($wikiDir, $custom) : null;

        if (is_file($aside . DIRECTORY_SEPARATOR . self::SENTINEL)) {
            unlink($aside . DIRECTORY_SEPARATOR . self::SENTINEL);
        }
        if (!rename($aside, $custom)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_RESTORE_CUSTOM') . ' ' . $aside);
        }

        return $displaced;
    }

    /**
     * Put back an aside an interrupted run left behind, and say so. A wiki with no
     * custom/ left has nothing to lose, so the aside goes back even unmarked, which
     * is how the runs that predate the mark are picked up.
     */
    public function recover(string $wikiDir): ?string
    {
        if (!$this->isAside($wikiDir)) {
            return null;
        }

        $custom = $this->customPath($wikiDir);
        $inPlace = file_exists($custom) || is_link($custom);
        if ($inPlace && !$this->isOurs($this->asidePath($wikiDir))) {
            throw new \RuntimeException(_t('FERME_CLI_CUSTOM_ASIDE_FOREIGN') . ' ' . $this->asidePath($wikiDir));
        }

        $displaced = $this->reveal($wikiDir);

        return _t('FERME_CLI_CUSTOM_RECOVERED')
            . ($displaced === null ? '' : ' (' . _t('FERME_CLI_CUSTOM_CONFLICT_MOVED') . ' ' . $displaced . ')');
    }

    public function isAside(string $wikiDir): bool
    {
        $aside = $this->asidePath($wikiDir);

        return file_exists($aside) || is_link($aside);
    }

    private function isOurs(string $aside): bool
    {
        if (is_link($aside)) {
            return true;
        }

        return is_file($aside . DIRECTORY_SEPARATOR . self::SENTINEL);
    }

    /**
     * A folder found where custom/ belongs is never deleted: an empty one goes,
     * anything else waits in the farm backups.
     */
    private function displace(string $wikiDir, string $custom): ?string
    {
        if (!is_link($custom) && is_dir($custom) && count((array)scandir($custom)) <= 2) {
            $this->files->remove($custom);

            return null;
        }

        $slug = str_replace(DIRECTORY_SEPARATOR, '-', trim($this->key($wikiDir), DIRECTORY_SEPARATOR));
        $target = $this->config->ensureBackupDir('customs') . DIRECTORY_SEPARATOR . $slug . '-' . date('Ymd-His');

        if (rename($custom, $target)) {
            return $target;
        }
        if ($this->files->copyRecursive($custom, $target) === true) {
            $this->files->remove($custom);

            return $target;
        }

        throw new \RuntimeException(_t('FERME_CLI_CANNOT_MOVE') . ' ' . $custom);
    }

    /**
     * Restore on the way out, whichever way the process is leaving.
     */
    private function watch(): void
    {
        if ($this->watching) {
            return;
        }
        $this->watching = true;

        register_shutdown_function(function () {
            $this->revealPending();
        });

        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function (int $received) {
                $this->revealPending();

                exit(128 + $received);
            });
        }
    }

    private function revealPending(): void
    {
        foreach (array_keys($this->pending) as $wikiDir) {
            try {
                $this->reveal($wikiDir);
            } catch (\Throwable $th) {
                error_log('ferme: ' . $th->getMessage());
            }
        }
    }

    private function customPath(string $wikiDir): string
    {
        return $this->key($wikiDir) . DIRECTORY_SEPARATOR . 'custom';
    }

    private function asidePath(string $wikiDir): string
    {
        return $this->key($wikiDir) . DIRECTORY_SEPARATOR . self::ASIDE;
    }

    private function key(string $wikiDir): string
    {
        return rtrim($wikiDir, DIRECTORY_SEPARATOR);
    }
}
