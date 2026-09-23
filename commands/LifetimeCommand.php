<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Wiki;

class LifetimeCommand extends AbstractFarmCommand
{
    protected $sweeper;
    protected $lifetime;
    protected $entryManager;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->sweeper = $wiki->services->get(LifetimeSweeper::class);
        $this->lifetime = $wiki->services->get(WikiLifetime::class);
        $this->entryManager = $wiki->services->get(EntryManager::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:lifetime')
            ->setDescription(_t('FERME_CLI_LIFETIME_DESCRIPTION'))
            ->addOption('list', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_LIFETIME_LIST'))
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $today = new \DateTimeImmutable('today');

        if ($input->getOption('list')) {
            return $this->listDeadlines($output, $today);
        }
        if (!$this->lifetime->isEnabled()) {
            $output->writeln('<comment>' . _t('FERME_CLI_LIFETIME_OFF') . '</comment>');

            return Command::SUCCESS;
        }

        $dryRun = $this->isDryRun($input);
        $report = $dryRun ? $this->sweeper->sweep($today, true) : $this->sweeper->sweepNow($today);
        if ($report === null) {
            $output->writeln('<comment>' . _t('FERME_CLI_LIFETIME_BUSY') . '</comment>');

            return Command::SUCCESS;
        }
        foreach (['reminded', 'deleted', 'archived', 'purged'] as $what) {
            foreach ($report[$what] as $name) {
                $output->writeln($this->dryRunPrefix($input) . _t('FERME_CLI_LIFETIME_' . strtoupper($what)) . ' ' . $name);
            }
        }

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_LIFETIME_DESCRIPTION'),
            [
                _t('FERME_CLI_LIFETIME_REMINDED') => count($report['reminded']),
                _t('FERME_CLI_LIFETIME_DELETED') => count($report['deleted']),
                _t('FERME_CLI_LIFETIME_ARCHIVED') => count($report['archived']),
                _t('FERME_CLI_LIFETIME_PURGED') => count($report['purged']),
                _t('FERME_CLI_FAILED') => count($report['failed']),
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $report['failed'],
            $dryRun
        );
    }

    private function listDeadlines(OutputInterface $output, \DateTimeImmutable $today): int
    {
        $farmId = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');
        $rows = [];
        foreach ($this->entryManager->search(['formsIds' => [$farmId]]) as $entry) {
            $state = $this->lifetime->describe($entry, $today);
            if ($state === null) {
                continue;
            }
            $rows[] = [
                (string)($entry['bf_dossier-wiki'] ?? $entry['id_fiche']),
                $this->lifetime->label($state['kind']),
                $state['archived'] ? '' : $state['expiresAt'],
                $state['archived'] ? $state['purgeAt'] : '',
            ];
        }
        usort($rows, function (array $left, array $right) {
            return strcmp($left[2] ?: $left[3], $right[2] ?: $right[3]);
        });

        $table = new Table($output);
        $table->setHeaders(['folder', _t('FERME_LIFETIME'), _t('FERME_CLI_LIFETIME_EXPIRES'), _t('FERME_CLI_LIFETIME_PURGE')]);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
