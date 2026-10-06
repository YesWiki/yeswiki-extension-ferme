<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmAdminAccount;
use YesWiki\Ferme\Service\FarmCommand;

/** `ferme:admin`: creates or removes an account in each wiki of the farm, through each wiki's own `user:*` commands. */
class AdminCommand extends FarmCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ferme:admin')
            ->setDescription(_t('FERME_CLI_ADMIN_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_ADMIN_HELP'))
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_USER'))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_PASSWORD'))
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_USER_EMAIL'))
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_GROUP'), FarmAdminAccount::ADMIN_GROUP)
            ->addOption('remove', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_REMOVE'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $remove = (bool)$input->getOption('remove');
        $name = trim((string)($input->getOption('user') ?: $this->farmConfig->text('yeswiki-farm-admin-name')));
        $password = (string)($input->getOption('password') ?: $this->farmConfig->text('yeswiki-farm-admin-pass'));
        $group = trim((string)$input->getOption('group'));
        $email = trim((string)($input->getOption('email') ?: $this->farmConfig->adminEmail()));
        $email = $email === '' ? null : $email;

        $error = $this->validate($name, $password, $group, $email, $remove);
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');

            return Command::FAILURE;
        }
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $account = $this->service(FarmAdminAccount::class);
        $dryRun = $this->isDryRun($input);
        $output->writeln($this->dryRunPrefix($input) . _t($remove ? 'FERME_CLI_ADMIN_REMOVE_INTRO' : 'FERME_CLI_ADMIN_ADD_INTRO') . ' "' . $name . '" / "' . $group . '" : ' . count($wikis));
        $rows = [];
        $counts = [];
        $failed = [];
        foreach ($wikis as $wiki) {
            $label = $this->label($wiki);
            try {
                $result = $remove ? $account->removeUser($wiki['PATH'], $name, $group, $dryRun) : $account->ensureUser($wiki['PATH'], $name, $password, $email, $group, $dryRun);
                $counts['user:' . $result['user']] = ($counts['user:' . $result['user']] ?? 0) + 1;
                $counts['group:' . $result['group']] = ($counts['group:' . $result['group']] ?? 0) + 1;
                $rows[] = [$label, _t('FERME_CLI_STATE_' . strtoupper($result['user'])), _t('FERME_CLI_STATE_' . strtoupper($result['group']))];
            } catch (\Throwable $th) {
                $failed[] = $label;
                $rows[] = [$label, '<error>' . _t('FERME_CLI_FAILED') . '</error>', $th->getMessage()];
            }
        }
        (new Table($output))->setHeaders([_t('FERME_CLI_COL_URL'), _t('FERME_CLI_COL_USER'), _t('FERME_CLI_COL_GROUP')])->setRows($rows)->render();

        $counters = [_t('FERME_CLI_WIKIS_FOUND') => count($wikis)];
        foreach ($counts as $key => $value) {
            $counters[_t('FERME_CLI_COUNT_' . strtoupper(str_replace(':', '_', $key)))] = $value;
        }
        $counters[_t('FERME_CLI_FAILED')] = count($failed);

        return $this->renderSummary($output, _t('FERME_CLI_ADMIN_SUMMARY'), $counters, $failed, $dryRun);
    }

    /** The same rule as the installer's, so a name this accepts is one the wiki lets log in. */
    private function validate(string $name, string $password, string $group, ?string $email, bool $remove): ?string
    {
        if ($name === '' || strlen($name) > 80 || preg_match('/^[^!#@<>\\\\\/][^<>\\\\\/]{2,}$/', $name) !== 1) {
            return _t('FERME_CLI_BAD_USER_NAME') . ' "' . $name . '"';
        }
        if (!$remove && $password === '') {
            return _t('FERME_CLI_EMPTY_PASSWORD');
        }
        if ($group === '') {
            return _t('FERME_CLI_EMPTY_GROUP');
        }
        if (!$remove && $email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return _t('FERME_CLI_BAD_EMAIL') . ' "' . $email . '"';
        }

        return null;
    }
}
