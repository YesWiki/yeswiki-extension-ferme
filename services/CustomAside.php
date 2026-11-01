<?php

namespace YesWiki\Ferme\Service;

// Puts a wiki's custom/ out of the way while its migrations run, so they see a stock wiki,
// and brings it back at the end, on a fatal, on a signal, or on the next run over that wiki.
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

    // moves custom/ aside and marks it as ours, false when the wiki has none
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
            file_put_contents($this->sentinelPath($wikiDir), json_encode([
                'wiki' => $this->key($wikiDir),
                'since' => date('c'),
                'pid' => getmypid(),
            ]));
        }

        $this->pending[$this->key($wikiDir)] = true;
        $this->watch();

        return true;
    }

    // puts custom/ back, and returns where a custom/ found back in its place has been moved
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
        if (is_file($this->sentinelPath($wikiDir))) {
            unlink($this->sentinelPath($wikiDir));
        }

        return $displaced;
    }

    // puts back an aside an interrupted run left, unmarked too when the wiki has no custom/ left
    public function recover(string $wikiDir): ?string
    {
        $this->unnest($wikiDir);

        if (!$this->isAside($wikiDir)) {
            if (is_file($this->sentinelPath($wikiDir))) {
                unlink($this->sentinelPath($wikiDir));
            }

            return null;
        }

        $custom = $this->customPath($wikiDir);
        $inPlace = file_exists($custom) || is_link($custom);
        if ($inPlace && !$this->isOurs($wikiDir, $this->asidePath($wikiDir))) {
            throw new \RuntimeException(_t('FERME_CLI_CUSTOM_ASIDE_FOREIGN') . ' ' . $this->asidePath($wikiDir));
        }

        $displaced = $this->reveal($wikiDir);

        return _t('FERME_CLI_CUSTOM_RECOVERED')
            . ($displaced === null ? '' : ' (' . _t('FERME_CLI_CUSTOM_CONFLICT_MOVED') . ' ' . $displaced . ')');
    }

    public function isAside(string $wikiDir): bool
    {
        foreach ([$this->asidePath($wikiDir), $this->nestedPath($wikiDir)] as $path) {
            if ($path !== null && (file_exists($path) || is_link($path))) {
                return true;
            }
        }

        return false;
    }

    // lifts out an aside a shell mv dropped inside an existing custom/
    private function unnest(string $wikiDir): void
    {
        $nested = $this->nestedPath($wikiDir);
        if ($nested === null || (!file_exists($nested) && !is_link($nested))) {
            return;
        }

        $aside = $this->asidePath($wikiDir);
        if (file_exists($aside) || is_link($aside)) {
            throw new \RuntimeException(_t('FERME_CLI_CUSTOM_ASIDE_IN_THE_WAY') . ' ' . $aside);
        }
        if (!$this->isOurs($wikiDir, $nested)) {
            throw new \RuntimeException(_t('FERME_CLI_CUSTOM_ASIDE_NESTED') . ' ' . $nested);
        }
        if (!rename($nested, $aside)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_MOVE') . ' ' . $nested);
        }
    }

    private function isOurs(string $wikiDir, string $aside): bool
    {
        if (is_link($aside)) {
            return true;
        }

        return is_file($this->sentinelPath($wikiDir)) || is_file($aside . DIRECTORY_SEPARATOR . self::SENTINEL);
    }

    // clears the place of custom/: an empty folder goes, anything else waits in the farm backups
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

        $summary = $this->files->failureSummary();
        $this->files->remove($target);

        throw new \RuntimeException(trim(_t('FERME_CLI_CANNOT_MOVE') . ' ' . $custom . ' ' . $summary));
    }

    // restores on the way out, whichever way the process is leaving
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

    // the mark sits beside the aside, as a custom/ owned by another user can be moved but not written into
    private function sentinelPath(string $wikiDir): string
    {
        return $this->asidePath($wikiDir) . self::SENTINEL;
    }

    // null when custom/ is a link or absent, as anything inside then belongs to what it points at
    private function nestedPath(string $wikiDir): ?string
    {
        $custom = $this->customPath($wikiDir);
        if (is_link($custom) || !is_dir($custom)) {
            return null;
        }

        return $custom . DIRECTORY_SEPARATOR . self::ASIDE;
    }

    private function key(string $wikiDir): string
    {
        return rtrim($wikiDir, DIRECTORY_SEPARATOR);
    }
}
