<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Wiki;

class SpamIndexCommand extends AbstractFarmCommand
{
    protected $fingerprints;
    protected $cleaner;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->fingerprints = $wiki->services->get(SpamFingerprints::class);
        $this->cleaner = $wiki->services->get(SpamCleaner::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:spam-index')
            ->setDescription(_t('FERME_CLI_INDEX_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_INDEX_HELP'))
            ->addOption('min', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_INDEX_MIN'), (string)SpamFingerprints::WIKIS_FOR_CAMPAIGN)
            ->addOption('show', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_INDEX_SHOW'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('show')) {
            return $this->show($output);
        }

        $started = microtime(true);
        $min = max(2, (int)$input->getOption('min'));

        $report = $this->fingerprints->build($this->cleaner->hosts(), $min, function (string $folder, int $read) use ($output) {
            if ($read % 250 === 0) {
                $output->writeln('  ' . $read . ' ' . _t('FERME_CLI_WIKIS_FOUND'));
            }
        });

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_INDEX_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => $report['wikis'],
                _t('FERME_CLI_INDEX_LINES') => $report['lines'],
                _t('FERME_CLI_INDEX_KEPT') => $report['kept'],
                _t('FERME_CLI_INDEX_DROPPED') => $report['dropped'],
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            [],
            false
        );
    }

    private function show(OutputInterface $output): int
    {
        $about = $this->fingerprints->about();
        if ($about === null) {
            $output->writeln('<comment>' . _t('FERME_CLI_INDEX_NONE') . '</comment>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%s</info> %s — %d %s, %d %s, %s >= %d',
            _t('FERME_CLI_INDEX_SUMMARY'),
            $about['builtAt'],
            $about['wikis'],
            _t('FERME_CLI_WIKIS_FOUND'),
            $about['kept'],
            _t('FERME_CLI_INDEX_KEPT'),
            _t('FERME_CLI_OPT_INDEX_MIN'),
            $about['threshold']
        ));

        foreach ($this->fingerprints->widest(20) as $row) {
            $output->writeln(sprintf('  %4d  %s', $row['wikis'], $row['line'] !== '' ? $row['line'] : $row['print']));
        }

        return Command::SUCCESS;
    }
}
