<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\WikiMigrator;

/** `ferme:migrate`: runs every wiki's own `migrate`, which is what a farm owes its wikis after the shared Program was updated. */
class MigrateCommand extends FarmCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ferme:migrate')
            ->setDescription(_t('FERME_CLI_MIGRATE_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_MIGRATE_HELP'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = microtime(true);
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }
        $migrator = $this->service(WikiMigrator::class);
        $migrated = 0;
        $failed = [];
        foreach ($wikis as $wiki) {
            try {
                $result = $migrator->migrate($wiki['FOLDER'], $this->isDryRun($input));
                $migrated++;
                $output->writeln($this->dryRunPrefix($input) . '<info>' . $this->label($wiki) . '</info>');
                foreach ($result['messages'] as $line) {
                    $output->writeln('      ' . $line, OutputInterface::VERBOSITY_VERBOSE);
                }
            } catch (\Throwable $throwable) {
                $failed[] = $this->label($wiki) . ' : ' . $throwable->getMessage();
            }
        }

        return $this->renderSummary($output, _t('FERME_CLI_MIGRATE_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
            _t('FERME_CLI_MIGRATED') => $migrated,
            _t('FERME_CLI_FAILED') => count($failed),
            _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
        ], $failed, $this->isDryRun($input));
    }
}
