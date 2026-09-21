<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\StatsPresenter;
use YesWiki\Ferme\Service\WikiSymlinker;
use YesWiki\Wiki;

class SymlinkCommand extends AbstractFarmCommand
{
    protected $symlinker;
    protected $presenter;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->symlinker = $wiki->services->get(WikiSymlinker::class);
        $this->presenter = $wiki->services->get(StatsPresenter::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:symlink')
            ->setDescription(_t('FERME_CLI_SYMLINK_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_SYMLINK_HELP'))
            ->addOption('undo', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_SYMLINK_UNDO'))
            ->addOption('list', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_SYMLINK_LIST'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);
        $undo = (bool)$input->getOption('undo');

        if ($this->symlinker->entries() === []) {
            $output->writeln('<comment>' . _t('FERME_CLI_SYMLINK_NOTHING_LENT') . '</comment>');

            return Command::SUCCESS;
        }

        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $touched = 0;
        $links = 0;
        $freed = 0;
        $kept = 0;
        $failed = [];

        foreach ($wikis as $wiki) {
            try {
                $report = $undo
                    ? $this->symlinker->unlink($wiki['PATH'], $dryRun)
                    : $this->symlinker->link($wiki['PATH'], $dryRun);
            } catch (\Throwable $throwable) {
                $failed[] = $wiki['FOLDER'] . ' : ' . $throwable->getMessage();

                continue;
            }

            $links += $report['linked'];
            $freed += $report['freed'];
            $kept += $report['kept'];
            if ($report['linked'] === 0) {
                continue;
            }

            $touched++;
            $output->writeln($this->dryRunPrefix($input) . '<info>' . $wiki['FOLDER'] . '</info> '
                . $report['linked'] . ' · ' . $this->presenter->size($report['freed']));
            if ($input->getOption('list')) {
                foreach ($report['steps'] as $step) {
                    $output->writeln('      ' . str_pad($step['action'], 7) . $step['entry']
                        . ' — ' . _t($step['why']));
                }
            }
        }

        return $this->renderSummary(
            $output,
            _t($undo ? 'FERME_CLI_SYMLINK_UNDO_SUMMARY' : 'FERME_CLI_SYMLINK_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                _t('FERME_CLI_SYMLINK_TOUCHED') => $touched,
                _t('FERME_CLI_SYMLINK_LINKS') => $links,
                _t('FERME_CLI_SYMLINK_KEPT') => $kept,
                _t('FERME_CLI_SYMLINK_FREED') => $this->presenter->size($freed),
                _t('FERME_CLI_FAILED') => count($failed),
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $failed,
            $dryRun
        );
    }
}
