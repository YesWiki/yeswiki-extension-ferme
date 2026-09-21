<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Wiki;

class StatsCommand extends AbstractFarmCommand
{
    private const LOCK_FILE = 'cache/ferme-stats.lock';

    protected $store;
    protected $refresher;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->store = $wiki->services->get(WikiStatsStore::class);
        $this->refresher = $wiki->services->get(StatsRefresher::class);
    }

    protected function configure()
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);

        if ($input->getOption('check')) {
            return $this->check($input, $output, $started);
        }

        $lock = $dryRun ? null : $this->lock();
        if ($lock === false) {
            $output->writeln('<comment>' . _t('FERME_CLI_STATS_ALREADY_RUNNING') . '</comment>');

            return Command::SUCCESS;
        }

        try {
            $wikis = $this->selectWikis($input);
            if (empty($wikis)) {
                $this->warnNothingFound($input, $output);

                return Command::SUCCESS;
            }

            $orphans = $this->sweepOrphans($input, $output, $wikis);
            $counters = $this->refresh($input, $output, $wikis);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_STATS_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                _t('FERME_CLI_STATS_PROBED') => $counters['probed'],
                _t('FERME_CLI_STATS_COUNTED') => $counters['counted'],
                _t('FERME_CLI_STATS_WALKED') => $counters['walked'],
                _t('FERME_CLI_STATS_UNCHANGED') => $counters['unchanged'],
                _t('FERME_CLI_STATS_ORPHANS') => count($orphans),
                _t('FERME_CLI_FAILED') => count($counters['failed']),
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $counters['failed'],
            $dryRun
        );
    }

    /**
     * @return array{probed:int,counted:int,walked:int,unchanged:int,failed:array<int,string>}
     */
    private function refresh(InputInterface $input, OutputInterface $output, array $wikis): array
    {
        $dryRun = $this->isDryRun($input);
        $force = (bool)$input->getOption('force');
        $withDisk = !$input->getOption('no-disk');
        $stale = $this->seconds((string)$input->getOption('stale'));
        $max = (int)$input->getOption('max');

        $counters = ['probed' => 0, 'counted' => 0, 'walked' => 0, 'unchanged' => 0, 'failed' => []];
        $stored = $this->store->readMany(array_column($wikis, 'FOLDER'));
        $done = 0;

        foreach ($this->oldestFirst($wikis, $stored) as $wiki) {
            if ($max > 0 && $done >= $max) {
                break;
            }

            $folder = $wiki['FOLDER'];
            $known = $stored[$folder] ?? null;
            if (!$force && !$this->refresher->isStale($known, $stale)) {
                continue;
            }
            if (!$this->isInsideFarm($wiki)) {
                $counters['failed'][] = $this->label($wiki);
                $output->writeln('<error>  ' . $this->label($wiki) . ': ' . _t('FERME_CLI_STATS_OUTSIDE_FARM') . '</error>');
                continue;
            }

            $done++;
            $counters['probed']++;

            if ($dryRun) {
                $output->writeln($this->dryRunPrefix($input) . $this->label($wiki) . ': ' . _t('FERME_CLI_STATS_WOULD_MEASURE'));
                continue;
            }

            $result = $this->refresher->refresh($folder, [
                'force' => $force,
                'withDisk' => $withDisk,
                'known' => $known,
            ]);

            $counters['counted'] += $result['counted'] ? 1 : 0;
            $counters['walked'] += $result['walked'] ? 1 : 0;
            if ($result['failed'] !== null) {
                $counters['failed'][] = $this->label($wiki);
                $output->writeln('<error>  ' . $this->label($wiki) . ': ' . $result['failed'] . '</error>');
            } elseif (!$result['counted'] && !$result['walked']) {
                $counters['unchanged']++;
            }
        }

        $this->refresher->close();

        return $counters;
    }

    /**
     * Measures nothing: says whether the farm is being measured at all, and fails
     * when it is not, so a supervision can watch a cron that stopped.
     */
    private function check(InputInterface $input, OutputInterface $output, float $started): int
    {
        $olderThan = $this->seconds((string)$input->getOption('check'));
        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $stored = $this->store->readMany(array_column($wikis, 'FOLDER'));
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
            if (($known['status'] ?? '') === WikiStatsStore::STATUS_ERROR) {
                $failing++;
            }
            if ($oldest === null || $known['checkedAt'] < $oldest['checkedAt']) {
                $oldest = ['checkedAt' => $known['checkedAt'], 'label' => $this->label($wiki)];
            }
            if ($this->refresher->isStale($known, $olderThan)) {
                $late[] = $this->label($wiki);
            }
        }

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_STATS_CHECK_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                _t('FERME_CLI_STATS_NEVER') => $never,
                _t('FERME_CLI_STATS_OLDEST') => $oldest === null
                    ? '-'
                    : $oldest['checkedAt'] . ' (' . $oldest['label'] . ')',
                _t('FERME_CLI_STATS_LATE') => count($late),
                _t('FERME_CLI_STATS_FAILING') => $failing,
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $late
        );
    }

    /**
     * Wikis deleted by something other than the farm leave their stats behind, so a
     * full run drops what no longer matches a folder. A narrowed run cannot tell an
     * absent wiki from one it was not asked about, so it sweeps nothing.
     *
     * @return array<int,string>
     */
    private function sweepOrphans(InputInterface $input, OutputInterface $output, array $wikis): array
    {
        if ($input->getOption('wiki') || $input->getOption('path') || $this->isDryRun($input)) {
            return [];
        }

        $orphans = $this->store->forgetOrphans(array_column($wikis, 'FOLDER'));
        foreach ($orphans as $folder) {
            $output->writeln('  <comment>' . $folder . ': ' . _t('FERME_CLI_STATS_ORPHAN_DROPPED') . '</comment>');
        }

        return $orphans;
    }

    /**
     * Oldest check first, so a run capped by --max walks the farm round robin.
     */
    private function oldestFirst(array $wikis, array $stored): array
    {
        usort($wikis, function (array $a, array $b) use ($stored) {
            $left = $stored[$a['FOLDER']]['checkedAt'] ?? '';
            $right = $stored[$b['FOLDER']]['checkedAt'] ?? '';

            return strcmp($left, $right);
        });

        return $wikis;
    }

    private function isInsideFarm(array $wiki): bool
    {
        $expected = realpath($this->farmConfig->wikiDir($wiki['FOLDER']));

        return $expected !== false && $expected === realpath($wiki['PATH']);
    }

    /**
     * @return resource|false
     */
    private function lock()
    {
        $handle = fopen(self::LOCK_FILE, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }
}
