<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\SpamCleaner;

/** `ferme:clean-spam`: takes the robots' pages out of the farm's wikis, or lists what it would take. */
class CleanSpamCommand extends FarmCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ferme:clean-spam')
            ->setDescription(_t('FERME_CLI_CLEAN_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_CLEAN_HELP'))
            ->addOption('list', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_CLEAN_LIST'))
            ->addOption('stuck', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_CLEAN_STUCK'))
            ->addOption('approved', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_CLEAN_APPROVED'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = microtime(true);
        $dryRun = $this->isDryRun($input);
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }
        if ($input->getOption('stuck')) {
            return $this->stuck($output, $wikis, $started);
        }
        if ($input->getOption('approved')) {
            return $this->approved($output, $wikis, $started);
        }

        $cleaner = $this->service(SpamCleaner::class);
        $touched = 0;
        $deleted = 0;
        $stripped = 0;
        $failed = [];
        foreach ($wikis as $wiki) {
            try {
                $report = $cleaner->clean($wiki['FOLDER'], $dryRun);
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
            $output->writeln($this->dryRunPrefix($input) . '<info>' . $wiki['FOLDER'] . '</info> ' . $report['deleted'] . ' / ' . $report['stripped']);
            if ($input->getOption('list')) {
                foreach ($report['pages'] as $page) {
                    $output->writeln('      ' . $page['action'] . ' ' . $page['tag']);
                }
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_CLEAN_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_CLEAN_TOUCHED') => $touched,
            _t('FERME_CLI_CLEAN_DELETED') => $deleted,
            _t('FERME_CLI_CLEAN_STRIPPED') => $stripped,
            _t('FERME_CLI_FAILED') => count($failed),
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], $failed, $dryRun);
    }

    /** @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis */
    private function approved(OutputInterface $output, array $wikis, float $started): int
    {
        $approvals = $this->service(SpamApprovals::class);
        $cleaner = $this->service(SpamCleaner::class);
        $pages = 0;
        $stale = 0;
        $touched = 0;
        foreach ($wikis as $wiki) {
            $vouched = $approvals->all($wiki['FOLDER']);
            if ($vouched === []) {
                continue;
            }
            $touched++;
            $output->writeln('<info>' . $wiki['FOLDER'] . '</info>');
            foreach ($vouched as $tag => $mark) {
                $pages++;
                try {
                    $body = $cleaner->bodyOf($wiki['FOLDER'], (string)$tag);
                } catch (\Throwable $throwable) {
                    $body = null;
                }
                $still = $body !== null && SpamApprovals::mark($body) === $mark;
                $stale += $still ? 0 : 1;
                $output->writeln('      ' . $tag . ($still ? '' : '  <comment>' . _t('FERME_CLI_CLEAN_APPROVED_STALE') . '</comment>'));
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_CLEAN_APPROVED_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_CLEAN_TOUCHED') => $touched,
            _t('FERME_CLI_CLEAN_APPROVED_PAGES') => $pages,
            _t('FERME_CLI_CLEAN_APPROVED_STALE') => $stale,
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ]);
    }

    /** @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis */
    private function stuck(OutputInterface $output, array $wikis, float $started): int
    {
        $cleaner = $this->service(SpamCleaner::class);
        $pages = 0;
        $touched = 0;
        $failed = [];
        foreach ($wikis as $wiki) {
            try {
                $report = $cleaner->stuck($wiki['FOLDER']);
            } catch (\Throwable $throwable) {
                $failed[] = $wiki['FOLDER'] . ' : ' . $throwable->getMessage();
                continue;
            }
            if ($report === []) {
                continue;
            }
            $touched++;
            $pages += count($report);
            $output->writeln('<info>' . $wiki['FOLDER'] . '</info>');
            foreach ($report as $page) {
                $hosts = [];
                foreach ($page['hosts'] as $host => $count) {
                    $hosts[] = $host . ' x' . $count;
                }
                $output->writeln(sprintf('      %-28s %5d %s, %d%% %s: %s', $page['tag'], $page['links'], _t('FERME_CLI_CLEAN_STUCK_LINKS'), (int)round(100 * $page['share']), _t('FERME_CLI_CLEAN_STUCK_SHARE'), implode(', ', $hosts)));
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_CLEAN_STUCK_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_CLEAN_TOUCHED') => $touched,
            _t('FERME_CLI_CLEAN_STUCK_PAGES') => $pages,
            _t('FERME_CLI_FAILED') => count($failed),
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], $failed);
    }
}
