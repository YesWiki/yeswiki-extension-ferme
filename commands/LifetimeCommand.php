<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiRepository;

/** `ferme:lifetime`: sends the reminders, deletes and archives the wikis whose time ran out. */
class LifetimeCommand extends FarmCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ferme:lifetime')
            ->setDescription(_t('FERME_CLI_LIFETIME_DESCRIPTION'))
            ->addOption('list', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_LIFETIME_LIST'))
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = microtime(true);
        $today = new \DateTimeImmutable('today');
        $lifetime = $this->service(WikiLifetime::class);
        if ($input->getOption('list')) {
            return $this->listDeadlines($output, $lifetime, $today);
        }
        if (!$lifetime->isEnabled()) {
            $output->writeln('<comment>' . _t('FERME_CLI_LIFETIME_OFF') . '</comment>');

            return Command::SUCCESS;
        }
        $sweeper = $this->service(LifetimeSweeper::class);
        $dryRun = $this->isDryRun($input);
        $report = $dryRun ? $sweeper->sweep($today, true) : $sweeper->sweepNow($today);
        if ($report === null) {
            $output->writeln('<comment>' . _t('FERME_CLI_LIFETIME_BUSY') . '</comment>');

            return Command::SUCCESS;
        }
        foreach (['reminded', 'deleted', 'archived', 'purged'] as $what) {
            foreach ($report[$what] as $name) {
                $output->writeln($this->dryRunPrefix($input) . _t('FERME_CLI_LIFETIME_' . strtoupper($what)) . ' ' . $name);
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_LIFETIME_DESCRIPTION'), [
            _t('FERME_CLI_LIFETIME_REMINDED') => count($report['reminded']),
            _t('FERME_CLI_LIFETIME_DELETED') => count($report['deleted']),
            _t('FERME_CLI_LIFETIME_ARCHIVED') => count($report['archived']),
            _t('FERME_CLI_LIFETIME_PURGED') => count($report['purged']),
            _t('FERME_CLI_FAILED') => count($report['failed']),
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], $report['failed'], $dryRun);
    }

    private function listDeadlines(OutputInterface $output, WikiLifetime $lifetime, \DateTimeImmutable $today): int
    {
        $rows = [];
        foreach ($this->service(WikiRepository::class)->entries() as $entry) {
            $state = $lifetime->describe($entry, $today);
            if ($state === null) {
                continue;
            }
            $rows[] = [(string)($entry['bf_dossier-wiki'] ?? $entry['tag'] ?? ''), $lifetime->label($state['kind']), $state['archived'] ? '' : $state['expiresAt'], $state['archived'] ? $state['purgeAt'] : ''];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left[2] !== '' ? $left[2] : $left[3], $right[2] !== '' ? $right[2] : $right[3]));
        (new Table($output))->setHeaders(['folder', _t('FERME_LIFETIME'), _t('FERME_CLI_LIFETIME_EXPIRES'), _t('FERME_CLI_LIFETIME_PURGE')])->setRows($rows)->render();

        return Command::SUCCESS;
    }
}
