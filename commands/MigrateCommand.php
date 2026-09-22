<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmMigrationWatch;

/**
 * The core's `migrate`, followed by the farm's own when the master just changed
 * version. The console loads the tools in alphabetical order and a command keeps
 * the last name registered, so this one stands in for autoupdate's.
 *
 * Nothing is done in the background here: whoever typed the command is watching,
 * and would rather see the wikis go by than find out from a log.
 */
if (class_exists(\YesWiki\AutoUpdate\Commands\MigrateCommand::class)) {
    class MigrateCommand extends \YesWiki\AutoUpdate\Commands\MigrateCommand
    {
        protected function execute(InputInterface $input, OutputInterface $output)
        {
            $status = parent::execute($input, $output);

            try {
                $watch = $this->wiki->services->get(FarmMigrationWatch::class);
                if (!$watch->isDue()) {
                    return $status;
                }
                $output->writeln(_t('FERME_MIGRATE_FARM_TOO') . ' ' . $watch->release());
            } catch (\Throwable $throwable) {
                $output->writeln('<comment>ferme: ' . $throwable->getMessage() . '</comment>');

                return $status;
            }

            putenv(FarmMigrationWatch::RUNNING . '=1');
            $farm = $this->getApplication()->find('ferme:update');
            $farmStatus = $farm->run(new ArrayInput(['command' => 'ferme:update', '--migrate-only' => true]), $output);

            return $status === Command::SUCCESS ? $farmStatus : $status;
        }
    }
}
