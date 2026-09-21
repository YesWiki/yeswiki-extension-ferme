<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Wiki;

class StatsCommand extends AbstractFarmCommand
{
    private const LOCK_FILE = 'cache/ferme-stats.lock';
    private const RECOMPUTE_AFTER = 604800;

    protected $stats;
    protected $store;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->stats = $wiki->services->get(WikiStats::class);
        $this->store = $wiki->services->get(WikiStatsStore::class);
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
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);

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
            if (!$force && !$this->isStale($known, $stale)) {
                continue;
            }
            if (!$this->isInsideFarm($wiki)) {
                $counters['failed'][] = $this->label($wiki);
                $output->writeln('<error>  ' . $this->label($wiki) . ': ' . _t('FERME_CLI_STATS_OUTSIDE_FARM') . '</error>');
                continue;
            }

            $done++;
            $counters['probed']++;

            $countAgain = $force || $this->databaseMoved($folder, $known);
            $walkAgain = $withDisk && ($force || $this->diskMoved($folder, $known));

            if (!$countAgain && !$walkAgain) {
                $counters['unchanged']++;
                if (!$dryRun) {
                    $this->store->touch($folder);
                }
                continue;
            }

            if ($dryRun) {
                $counters['counted'] += $countAgain ? 1 : 0;
                $counters['walked'] += $walkAgain ? 1 : 0;
                $output->writeln($this->dryRunPrefix($input) . $this->label($wiki) . ': '
                    . ($countAgain ? _t('FERME_CLI_STATS_WOULD_COUNT') : '')
                    . ($countAgain && $walkAgain ? ' + ' : '')
                    . ($walkAgain ? _t('FERME_CLI_STATS_WOULD_WALK') : ''));
                continue;
            }

            try {
                $measured = [];
                if ($countAgain) {
                    $measured = $this->stats->fromDatabase($folder);
                    $counters['counted']++;
                }
                if ($walkAgain) {
                    $measured = array_merge($measured, $this->stats->fromDisk($folder), [
                        'filesMtime' => $this->stats->diskProbe($folder),
                    ]);
                    $counters['walked']++;
                }
                $this->store->save($folder, $measured);
            } catch (WikiStatsException $exception) {
                $this->store->fail($folder, $exception->getReason());
                $counters['failed'][] = $this->label($wiki);
                $output->writeln('<error>  ' . $this->label($wiki) . ': ' . $exception->getReason() . '</error>');
            }
        }

        $this->stats->close();

        return $counters;
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

    private function isStale(?array $known, int $stale): bool
    {
        if ($known === null || empty($known['checkedAt'])) {
            return true;
        }

        return strtotime($known['checkedAt']) < time() - $stale;
    }

    private function databaseMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['lastPageId']) || $this->tooOld($known)) {
            return true;
        }

        $probe = $this->stats->probe($folder);

        return $probe === null || $probe !== $known['lastPageId'];
    }

    private function diskMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['filesMtime']) || $this->tooOld($known)) {
            return true;
        }

        $probe = $this->stats->diskProbe($folder);

        return $probe === null || $probe !== $known['filesMtime'];
    }

    /**
     * Deleting an account moves no page id and touches no folder, so a week without
     * a full count is enough to recount whatever the probes say.
     */
    private function tooOld(array $known): bool
    {
        return empty($known['computedAt']) || strtotime($known['computedAt']) < time() - self::RECOMPUTE_AFTER;
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

    private function seconds(string $duration): int
    {
        if (!preg_match('/^(\d+)([smhd]?)$/', trim($duration), $matches)) {
            throw new \InvalidArgumentException(_t('FERME_CLI_OPT_STALE') . ': ' . $duration);
        }

        $units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

        return (int)$matches[1] * $units[$matches[2]];
    }
}
