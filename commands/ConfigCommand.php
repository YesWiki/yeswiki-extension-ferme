<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\WikiConfigEditor;

/** `ferme:config`: sets or removes settings in each wiki of the farm, through core's ConfigurationService. */
class ConfigCommand extends FarmCommand
{
    protected function configure(): void
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $editor = $this->service(WikiConfigEditor::class);
        $smtp = (bool)$input->getOption('smtp');
        $unset = array_values(array_map(static fn ($key): string => trim((string)$key), (array)$input->getOption('unset')));

        try {
            $set = $this->parseSet(array_values(array_map('strval', (array)$input->getOption('set'))));
            if ($smtp) {
                $editor->smtpFromFarm();
            }
        } catch (\Throwable $th) {
            $output->writeln('<error>' . $th->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if (!$smtp && $set === [] && $unset === []) {
            $output->writeln('<error>' . _t('FERME_CLI_NOTHING_TO_DO') . '</error>');

            return Command::FAILURE;
        }
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
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
                $wikiConfig = $editor->load($wiki['PATH']);
                $changes = $editor->apply($wikiConfig, $smtp ? array_merge($set, $editor->smtpChangesFor($wikiConfig)) : $set, $unset);
                if ($changes === []) {
                    $unchanged++;
                    $output->writeln('  <fg=gray>- ' . $label . ': ' . _t('FERME_CLI_NOTHING_TO_CHANGE') . '</>');
                    continue;
                }
                $output->writeln('  - <info>' . $label . '</info>');
                foreach ($changes as $key => $change) {
                    $output->writeln('      ' . $key . ': ' . WikiConfigEditor::mask($key, $change['old']) . ' -> ' . WikiConfigEditor::mask($key, $change['new']));
                }
                if (!$dryRun) {
                    if ($backup) {
                        $editor->backup($wiki['PATH']);
                    }
                    $editor->write($wiki['PATH'], $wikiConfig);
                }
                $changed++;
            } catch (\Throwable $th) {
                $failed[] = $label;
                $output->writeln('<error>  - ' . $label . ': ' . $th->getMessage() . '</error>');
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_CONFIG_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_CHANGED') => $changed,
            _t('FERME_CLI_UNCHANGED') => $unchanged,
            _t('FERME_CLI_FAILED') => count($failed),
        ], $failed, $dryRun);
    }

    /**
     * @param list<string> $pairs key=value, as many as --set was repeated
     *
     * @return array<string, mixed>
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
}
