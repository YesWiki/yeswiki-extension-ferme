<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;

/** `ferme:spam-index`: learns the lines of links pasted across several wikis of the farm. */
class SpamIndexCommand extends FarmCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ferme:spam-index')
            ->setDescription(_t('FERME_CLI_INDEX_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_INDEX_HELP'))
            ->addOption('min', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_INDEX_MIN'), (string)SpamFingerprints::WIKIS_FOR_CAMPAIGN)
            ->addOption('show', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_INDEX_SHOW'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fingerprints = $this->service(SpamFingerprints::class);
        if ($input->getOption('show')) {
            return $this->show($output, $fingerprints);
        }
        $started = microtime(true);
        $report = $fingerprints->build($this->service(SpamCleaner::class)->hosts(), max(2, (int)$input->getOption('min')), static function (string $folder, int $read) use ($output): void {
            if ($read % 250 === 0) {
                $output->writeln('  ' . $read . ' ' . _t('FERME_CLI_WIKIS_FOUND'));
            }
        });

        return $this->renderSummary($output, _t('FERME_CLI_INDEX_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => $report['wikis'],
            _t('FERME_CLI_INDEX_LINES') => $report['lines'],
            _t('FERME_CLI_INDEX_KEPT') => $report['kept'],
            _t('FERME_CLI_INDEX_DROPPED') => $report['dropped'],
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ]);
    }

    private function show(OutputInterface $output, SpamFingerprints $fingerprints): int
    {
        $about = $fingerprints->about();
        if ($about === null) {
            $output->writeln('<comment>' . _t('FERME_CLI_INDEX_NONE') . '</comment>');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<info>%s</info> %s: %d %s, %d %s, %s >= %d', _t('FERME_CLI_INDEX_SUMMARY'), $about['builtAt'], $about['wikis'], _t('FERME_CLI_WIKIS_FOUND'), $about['kept'], _t('FERME_CLI_INDEX_KEPT'), _t('FERME_CLI_OPT_INDEX_MIN'), $about['threshold']));
        foreach ($fingerprints->widest(20) as $row) {
            $output->writeln(sprintf('  %4d  %s', $row['wikis'], $row['line'] !== '' ? $row['line'] : $row['print']));
        }

        return Command::SUCCESS;
    }
}
