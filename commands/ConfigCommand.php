<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Wiki;

class ConfigCommand extends AbstractFarmCommand
{
    protected $editor;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->editor = $wiki->services->get(WikiConfigEditor::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:config')
            ->setDescription(_t('FERME_CLI_CONFIG_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_CONFIG_HELP'))
            ->addOption('set', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, _t('FERME_CLI_OPT_SET'))
            ->addOption('unset', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, _t('FERME_CLI_OPT_UNSET'))
            ->addOption('smtp', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_SMTP'))
            ->addOption('nobackup', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_NOBACKUP'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $smtp = (bool)$input->getOption('smtp');
        $unset = array_map('trim', $input->getOption('unset'));

        try {
            $set = $this->parseSet($input->getOption('set'));
        } catch (\Throwable $th) {
            $output->writeln('<error>' . $th->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (!$smtp && empty($set) && empty($unset)) {
            $output->writeln('<error>' . _t('FERME_CLI_NOTHING_TO_DO') . '</error>');

            return Command::FAILURE;
        }

        if ($smtp) {
            // fail once, not once per wiki
            try {
                $this->editor->smtpFromMaster();
            } catch (\Throwable $th) {
                $output->writeln('<error>' . $th->getMessage() . '</error>');

                return Command::FAILURE;
            }
        }

        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $dryRun = $this->isDryRun($input);
        $backup = !$input->getOption('nobackup');

        $output->writeln($this->dryRunPrefix($input) . _t('FERME_CLI_CONFIG_INTRO') . ' ' . count($wikis));

        $changed = 0;
        $unchanged = 0;
        $failed = [];

        foreach ($wikis as $wiki) {
            $label = $this->label($wiki);
            try {
                $wikiConfig = $this->editor->load($wiki['PATH']);
                $wikiSet = $smtp ? array_merge($set, $this->editor->smtpChangesFor($wikiConfig)) : $set;

                $changes = $this->editor->apply($wikiConfig, $wikiSet, $unset);
                if (empty($changes)) {
                    $unchanged++;
                    $output->writeln('  <fg=gray>- ' . $label . ': ' . _t('FERME_CLI_NOTHING_TO_CHANGE') . '</>');
                    continue;
                }

                $this->writeChanges($output, $wiki['PATH'], $label, $wikiConfig, $changes, $dryRun, $backup);
                $changed++;
            } catch (\Throwable $th) {
                $failed[] = $label;
                $output->writeln('<error>  - ' . $label . ': ' . $th->getMessage() . '</error>');
            }
        }

        $masterChanged = 0;
        if ($smtp) {
            try {
                $masterChanged = $this->updateMaster($output, $dryRun, $backup);
            } catch (\Throwable $th) {
                $failed[] = _t('FERME_CLI_MASTER_CONFIG');
                $output->writeln('<error>  - ' . _t('FERME_CLI_MASTER_CONFIG') . ': ' . $th->getMessage() . '</error>');
            }
        }

        $counters = [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_CHANGED') => $changed,
            _t('FERME_CLI_UNCHANGED') => $unchanged,
            _t('FERME_CLI_MASTER_CONFIG') => $masterChanged,
            _t('FERME_CLI_FAILED') => count($failed),
        ];
        if ($backup && !$dryRun && ($changed > 0 || $masterChanged > 0)) {
            $counters[_t('FERME_CLI_BACKUPS_IN')] = $this->farmConfig->backupDir() . DIRECTORY_SEPARATOR . 'configs';
        }

        return $this->renderSummary($output, _t('FERME_CLI_CONFIG_SUMMARY'), $counters, $failed, $dryRun);
    }

    /**
     * @param array<int,string> $pairs key=value, as many as the user repeated --set
     *
     * @return array<string,mixed>
     */
    private function parseSet(array $pairs): array
    {
        $set = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                throw new \RuntimeException(_t('FERME_CLI_BAD_SET') . ' "' . $pair . '"');
            }
            [$key, $value] = explode('=', $pair, 2);
            $set[trim($key)] = WikiConfigEditor::cast($value);
        }

        return $set;
    }

    /**
     * The master keeps the smtp settings in yeswiki-farm-extra-config too, so the
     * wikis it creates later are born with them instead of needing a second run.
     */
    private function updateMaster(OutputInterface $output, bool $dryRun, bool $backup): int
    {
        $masterRoot = $this->finder->masterRoot();
        $masterConfig = $this->editor->load($masterRoot);
        $changes = $this->editor->apply($masterConfig, $this->editor->smtpChangesForMaster(), []);

        if (empty($changes)) {
            $output->writeln('  <fg=gray>- ' . _t('FERME_CLI_MASTER_CONFIG') . ': ' . _t('FERME_CLI_NOTHING_TO_CHANGE') . '</>');

            return 0;
        }

        $this->writeChanges($output, $masterRoot, _t('FERME_CLI_MASTER_CONFIG'), $masterConfig, $changes, $dryRun, $backup);

        return 1;
    }

    private function writeChanges(
        OutputInterface $output,
        string $wikiDir,
        string $label,
        array $config,
        array $changes,
        bool $dryRun,
        bool $backup
    ): void {
        $output->writeln('  - <info>' . $label . '</info>');
        foreach ($changes as $key => $change) {
            $output->writeln('      ' . $key . ': '
                . WikiConfigEditor::mask($key, $change['old'])
                . ' -> ' . WikiConfigEditor::mask($key, $change['new']));
        }

        if ($dryRun) {
            return;
        }
        if ($backup) {
            $this->editor->backup($wikiDir);
        }
        $this->editor->write($wikiDir, $config);
    }
}
