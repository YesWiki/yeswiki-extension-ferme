<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/** Keeps the statistics moving on a farm where nobody installed a cron line, by refreshing a few wikis after a page of the master has been served. */
class StatsScheduler
{
    public const LOCK_FILE = 'cache/ferme-stats-visit.lock';
    public const INTERVAL = 300;
    public const PER_VISIT = 100;
    public const EXPIRE_PER_VISIT = 5;

    private $wiki;
    private $finder;
    private $store;
    private $refresher;
    private $sweeper;
    private $triggered = false;

    public function __construct(Wiki $wiki, WikiFinder $finder, WikiStatsStore $store, StatsRefresher $refresher, LifetimeSweeper $sweeper)
    {
        $this->sweeper = $sweeper;
        $this->wiki = $wiki;
        $this->finder = $finder;
        $this->store = $store;
        $this->refresher = $refresher;
    }

    /** Called from every page view. */
    public function triggerAfterResponse(): void
    {
        if ($this->triggered || !$this->isDue()) {
            return;
        }
        $this->triggered = true;

        register_shutdown_function(function () {
            $this->runAfterResponse();
        });
    }

    public function isEnabled(): bool
    {
        return filter_var($this->wiki->config['yeswiki-farm-stats-on-visit'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /** The wikis to measure now: the ones checked longest ago, the farm's own master excluded by the finder. */
    public function due(int $howMany): array
    {
        $wikis = $this->finder->find();
        if (empty($wikis)) {
            return [];
        }

        $folders = array_column($wikis, 'FOLDER');
        $known = $this->store->readMany($folders);

        usort($folders, function (string $left, string $right) use ($known) {
            return strcmp($known[$left]['checkedAt'] ?? '', $known[$right]['checkedAt'] ?? '');
        });

        return array_slice($folders, 0, max(0, $howMany));
    }

    private function isDue(): bool
    {
        if (PHP_SAPI === 'cli' || !$this->isEnabled()) {
            return false;
        }

        $lastRun = @filemtime(self::LOCK_FILE) ?: 0;
        if (time() - $lastRun < $this->interval()) {
            return false;
        }

        if (!is_dir(dirname(self::LOCK_FILE))) {
            @mkdir(dirname(self::LOCK_FILE), 0777, true);
        }

        return @touch(self::LOCK_FILE);
    }

    /** The visitor has their page: let go of their connection where php-fpm allows it, and measure. */
    private function runAfterResponse(): void
    {
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @set_time_limit(0);

        try {
            foreach ($this->due($this->perVisit()) as $folder) {
                $this->refresher->refresh($folder);
            }
            $this->refresher->close();
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }

        try {
            $this->sweeper->sweepIfDue(self::EXPIRE_PER_VISIT);
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
    }

    private function interval(): int
    {
        return max(60, (int)($this->wiki->config['yeswiki-farm-stats-interval'] ?? self::INTERVAL));
    }

    private function perVisit(): int
    {
        return max(1, (int)($this->wiki->config['yeswiki-farm-stats-per-visit'] ?? self::PER_VISIT));
    }
}
