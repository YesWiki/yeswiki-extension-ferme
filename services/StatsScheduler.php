<?php

namespace YesWiki\Ferme\Service;

/** Keeps the statistics and the lifetimes moving on the farm wiki's own housekeeping cadence, without a cron line. */
class StatsScheduler
{
    public const PER_VISIT = 100;
    public const EXPIRE_PER_VISIT = 5;

    private bool $scheduled = false;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly WikiFinder $finder,
        private readonly WikiStatsStore $store,
        private readonly StatsRefresher $refresher,
        private readonly LifetimeSweeper $sweeper
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->flag('yeswiki-farm-stats-on-visit', true);
    }

    /** Called after the farm wiki's housekeeping: a page view leaves the work for after its response, a cron run does it at once. */
    public function onMaintenance(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        if (PHP_SAPI === 'cli') {
            $this->run();

            return;
        }
        register_shutdown_function(function (): void {
            @ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @set_time_limit(0);
            $this->run();
        });
    }

    /**
     * The wikis to measure now: those checked longest ago.
     *
     * @return list<string>
     */
    public function due(int $howMany): array
    {
        $folders = array_column($this->finder->find(), 'FOLDER');
        if ($folders === []) {
            return [];
        }
        $known = $this->store->readMany($folders);
        usort($folders, static fn (string $left, string $right): int => strcmp((string)($known[$left]['checkedAt'] ?? ''), (string)($known[$right]['checkedAt'] ?? '')));

        return array_slice($folders, 0, max(0, $howMany));
    }

    private function run(): void
    {
        if ($this->isEnabled()) {
            try {
                foreach ($this->due(max(1, (int)$this->config->text('yeswiki-farm-stats-per-visit', (string)self::PER_VISIT))) as $folder) {
                    $this->refresher->refresh($folder);
                }
                $this->refresher->close();
            } catch (\Throwable $throwable) {
                error_log('ferme: ' . $throwable->getMessage());
            }
        }

        try {
            $this->sweeper->sweepIfDue(self::EXPIRE_PER_VISIT);
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
    }
}
