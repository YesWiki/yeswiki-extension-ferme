<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Wiki;

class CleanSpamCommand extends AbstractFarmCommand
{
    protected $cleaner;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->cleaner = $wiki->services->get(SpamCleaner::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:clean-spam')
            ->setDescription(_t('FERME_CLI_CLEAN_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_CLEAN_HELP'))
            ->addOption('list', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_CLEAN_LIST'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);

        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $touched = 0;
        $deleted = 0;
        $stripped = 0;
        $failed = [];

        foreach ($wikis as $wiki) {
            try {
                $report = $this->cleaner->clean($wiki['FOLDER'], $dryRun);
            } catch (\Throwable $throwable) {
                $failed[] = $wiki['FOLDER'] . ' : ' . $throwable->getMessage();

                continue;
            }

            if ($report['deleted'] === 0 && $report['stripped'] === 0) {
                continue;
            }

            $touched++;
            $deleted += $report['deleted'];
            $stripped += $report['stripped'];

            $output->writeln($this->dryRunPrefix($input) . '<info>' . $wiki['FOLDER'] . '</info> '
                . $report['deleted'] . ' / ' . $report['stripped']);
            if ($input->getOption('list')) {
                foreach ($report['pages'] as $page) {
                    $output->writeln('      ' . $page['action'] . ' ' . $page['tag']);
                }
            }
        }

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_CLEAN_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                _t('FERME_CLI_CLEAN_TOUCHED') => $touched,
                _t('FERME_CLI_CLEAN_DELETED') => $deleted,
                _t('FERME_CLI_CLEAN_STRIPPED') => $stripped,
                _t('FERME_CLI_FAILED') => count($failed),
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $failed,
            $dryRun
        );
    }
}
