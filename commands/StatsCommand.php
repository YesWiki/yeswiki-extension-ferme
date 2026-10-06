<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Files\Service\RuntimeLock;

/** `ferme:stats`: measures the farm's wikis, what has moved first, and does the farm's housekeeping on the way. */
class StatsCommand extends FarmCommand
{
    private const LOCK = 'cache/ferme-stats.lock';
    private const INDEX_AGE = 604800;

    protected function configure(): void
    {
        $this
            ->setName('ferme:stats')
            ->setDescription(_t('FERME_CLI_STATS_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_STATS_HELP'))
            ->addOption('max', 'm', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_MAX'))
            ->addOption('stale', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_STALE'), '24h')
            ->addOption('force', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_FORCE_STATS'))
            ->addOption('no-disk', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_NO_DISK'))
            ->addOption('check', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_CHECK'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);
        if ($input->getOption('check')) {
            return $this->check($input, $output, $started);
        }

        $locks = $this->service(RuntimeLock::class);
        $handle = $dryRun ? null : $locks->acquire(self::LOCK);
        if (!$dryRun && !$locks->tryLock($handle)) {
            $output->writeln('<comment>' . _t('FERME_CLI_STATS_ALREADY_RUNNING') . '</comment>');

            return Command::SUCCESS;
        }

        try {
            $wikis = $this->selectWikis($input);
            if ($wikis === []) {
                $this->warnNothingFound($input, $output);

                return Command::SUCCESS;
            }
            $campaigns = $dryRun ? 0 : $this->refreshIndex($output);
            $orphans = $this->sweepOrphans($input, $output, $wikis);
            $counters = $this->refresh($input, $output, $wikis);
            $swept = $dryRun ? 0 : $this->tidy($wikis);
            $lifetime = $dryRun ? null : $this->service(LifetimeSweeper::class)->sweepIfDue();
        } finally {
            $locks->release($handle);
        }

        return $this->renderSummary($output, _t('FERME_CLI_STATS_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_STATS_PROBED') => $counters['probed'],
            _t('FERME_CLI_STATS_COUNTED') => $counters['counted'],
            _t('FERME_CLI_STATS_WALKED') => $counters['walked'],
            _t('FERME_CLI_STATS_UNCHANGED') => $counters['unchanged'],
            _t('FERME_CLI_STATS_ORPHANS') => count($orphans),
            _t('FERME_CLI_INDEX_KEPT') => $campaigns,
            _t('FERME_CLI_STATS_ARCHIVES_SWEPT') => $swept,
            _t('FERME_CLI_LIFETIME_REMINDED') => count($lifetime['reminded'] ?? []),
            _t('FERME_CLI_LIFETIME_DELETED') => count($lifetime['deleted'] ?? []),
            _t('FERME_CLI_LIFETIME_ARCHIVED') => count($lifetime['archived'] ?? []),
            _t('FERME_CLI_LIFETIME_PURGED') => count($lifetime['purged'] ?? []),
            _t('FERME_CLI_FAILED') => count($counters['failed']),
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], array_merge($counters['failed'], $lifetime['failed'] ?? []), $dryRun);
    }

    /**
     * @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis
     *
     * @return array{probed: int, counted: int, walked: int, unchanged: int, failed: list<string>}
     */
    private function refresh(InputInterface $input, OutputInterface $output, array $wikis): array
    {
        $refresher = $this->service(StatsRefresher::class);
        $dryRun = $this->isDryRun($input);
        $force = (bool)$input->getOption('force');
        $stale = $this->seconds((string)$input->getOption('stale'));
        $max = (int)$input->getOption('max');
        $counters = ['probed' => 0, 'counted' => 0, 'walked' => 0, 'unchanged' => 0, 'failed' => []];
        $stored = $this->service(WikiStatsStore::class)->readMany(array_column($wikis, 'FOLDER'));
        usort($wikis, static fn (array $a, array $b): int => strcmp((string)($stored[$a['FOLDER']]['checkedAt'] ?? ''), (string)($stored[$b['FOLDER']]['checkedAt'] ?? '')));

        foreach ($wikis as $wiki) {
            if ($max > 0 && $counters['probed'] >= $max) {
                break;
            }
            $known = $stored[$wiki['FOLDER']] ?? null;
            if (!$force && !$refresher->isStale($known, $stale)) {
                continue;
            }
            $counters['probed']++;
            if ($dryRun) {
                $output->writeln($this->dryRunPrefix($input) . $this->label($wiki) . ': ' . _t('FERME_CLI_STATS_WOULD_MEASURE'));
                continue;
            }
            $result = $refresher->refresh($wiki['FOLDER'], ['force' => $force, 'withDisk' => !$input->getOption('no-disk'), 'known' => $known]);
            $counters['counted'] += $result['counted'] ? 1 : 0;
            $counters['walked'] += $result['walked'] ? 1 : 0;
            if ($result['failed'] !== null) {
                $counters['failed'][] = $this->label($wiki);
                $output->writeln('<error>  ' . $this->label($wiki) . ': ' . $result['failed'] . '</error>');
            } elseif (!$result['counted'] && !$result['walked']) {
                $counters['unchanged']++;
            }
        }
        $refresher->close();

        return $counters;
    }

    /** Measures nothing: says whether the farm is being measured at all, and fails when it is not. */
    private function check(InputInterface $input, OutputInterface $output, float $started): int
    {
        $refresher = $this->service(StatsRefresher::class);
        $olderThan = $this->seconds((string)$input->getOption('check'));
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }
        $stored = $this->service(WikiStatsStore::class)->readMany(array_column($wikis, 'FOLDER'));
        $late = [];
        $failing = 0;
        $never = 0;
        $oldest = null;
        foreach ($wikis as $wiki) {
            $known = $stored[$wiki['FOLDER']] ?? null;
            if ($known === null || empty($known['checkedAt'])) {
                $never++;
                $late[] = $this->label($wiki);
                continue;
            }
            $failing += ($known['status'] ?? '') === WikiStatsStore::STATUS_ERROR ? 1 : 0;
            if ($oldest === null || (string)$known['checkedAt'] < $oldest['checkedAt']) {
                $oldest = ['checkedAt' => (string)$known['checkedAt'], 'label' => $this->label($wiki)];
            }
            if ($refresher->isStale($known, $olderThan)) {
                $late[] = $this->label($wiki);
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_STATS_CHECK_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_STATS_NEVER') => $never,
            _t('FERME_CLI_STATS_OLDEST') => $oldest === null ? '-' : $oldest['checkedAt'] . ' (' . $oldest['label'] . ')',
            _t('FERME_CLI_STATS_LATE') => count($late),
            _t('FERME_CLI_STATS_FAILING') => $failing,
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], $late);
    }

    /**
     * Wikis deleted by something other than the farm leave their numbers behind.
     *
     * @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis
     *
     * @return list<string>
     */
    private function sweepOrphans(InputInterface $input, OutputInterface $output, array $wikis): array
    {
        if ($input->getOption('wiki') || $input->getOption('path') || $this->isDryRun($input)) {
            return [];
        }
        $orphans = $this->service(WikiStatsStore::class)->forgetOrphans(array_column($wikis, 'FOLDER'));
        foreach ($orphans as $folder) {
            $output->writeln('  <comment>' . $folder . ': ' . _t('FERME_CLI_STATS_ORPHAN_DROPPED') . '</comment>');
        }

        return $orphans;
    }

    /** The campaign index is rebuilt once a week: reading every wiki is too long for every sweep. */
    private function refreshIndex(OutputInterface $output): int
    {
        $fingerprints = $this->service(SpamFingerprints::class);
        $about = $fingerprints->about();
        if ($about !== null && (int)strtotime($about['builtAt']) > time() - self::INDEX_AGE) {
            return $about['kept'];
        }
        $output->writeln('<comment>' . _t('FERME_CLI_INDEX_SUMMARY') . '</comment>');

        return $fingerprints->build($this->service(SpamCleaner::class)->hosts())['kept'];
    }

    /**
     * Each wiki loses the archives nobody came to fetch.
     *
     * @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis
     */
    private function tidy(array $wikis): int
    {
        $keep = (int)$this->farmConfig->text('yeswiki-farm-archive-keep', (string)WikiArchiver::KEEP_ARCHIVES);
        if ($keep <= 0) {
            return 0;
        }
        $archiver = $this->service(WikiArchiver::class);
        $swept = 0;
        foreach ($wikis as $wiki) {
            try {
                $swept += $archiver->tidy($wiki['FOLDER'], $keep)['removed'];
            } catch (\Throwable $throwable) {
                continue;
            }
        }

        return $swept;
    }
}
