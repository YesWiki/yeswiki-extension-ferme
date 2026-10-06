<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\FarmCommand;
use YesWiki\Ferme\Service\FarmDashboard;
use YesWiki\Ferme\Service\ImportFilter;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Ferme\Service\WikiRepository;

/** `ferme:list`: lists the wikis on disk, and gives an entry to those that have none. */
class ListCommand extends FarmCommand
{
    private const FORMATS = ['table', 'json', 'csv'];

    protected function configure(): void
    {
        $this
            ->setName('ferme:list')
            ->setDescription(_t('FERME_CLI_LIST_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_LIST_HELP'))
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_FORMAT'), 'table')
            ->addOption('import', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_IMPORT'))
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_EMAIL'))
            ->addOption('min-entries', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_MIN_ENTRIES'))
            ->addOption('min-pages', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_MIN_PAGES'))
            ->addOption('min-users', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_MIN_USERS'))
            ->addOption('active-since', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_ACTIVE_SINCE'))
            ->addOption('name-excludes', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_NAME_EXCLUDES'))
            ->addOption('skip-suspect', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_SKIP_SUSPECT'))
            ->addOption('never-edited', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_NEVER_EDITED'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, self::FORMATS, true)) {
            $output->writeln('<error>' . _t('FERME_CLI_UNKNOWN_FORMAT') . ' ' . $format . ' (' . implode(', ', self::FORMATS) . ')</error>');

            return Command::FAILURE;
        }
        $wikis = $this->selectWikis($input);
        if ($wikis === []) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $repository = $this->service(WikiRepository::class);
        $inspected = $repository->inspect($wikis);
        if ($input->getOption('never-edited')) {
            $dashboard = $this->service(FarmDashboard::class);
            $inspected = array_values(array_filter($inspected, static fn (array $wiki): bool => is_array($wiki['stats'] ?? null) && $dashboard->wasNeverEdited($wiki['stats'])));
        }
        $missing = array_values(array_filter($inspected, static fn (array $wiki): bool => !$wiki['existsInBazar']));
        $selection = $this->service(ImportFilter::class)->apply($inspected, $this->criteria($input));
        $candidates = $selection['keep'];

        $imported = [];
        if ($input->getOption('import')) {
            $email = trim((string)$input->getOption('email'));
            $imported = $this->isDryRun($input) ? array_map('strval', array_column($candidates, 'folder')) : $repository->import($candidates, $email !== '' ? $email : $this->farmConfig->adminEmail());
        }

        if ($format === 'json') {
            $output->writeln((string)json_encode(['wikis' => $inspected, 'candidates' => array_column($candidates, 'folder'), 'imported' => $imported], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }
        if ($format === 'csv') {
            $this->writeCsv($output, $inspected);

            return Command::SUCCESS;
        }

        $this->writeTable($output, $inspected);

        return $this->renderSummary($output, _t('FERME_CLI_LIST_SUMMARY'), [
            _t('FERME_CLI_WIKIS_FOUND') => count($inspected),
            _t('FERME_CLI_IN_BAZAR') => count($inspected) - count($missing),
            _t('FERME_CLI_NOT_IN_BAZAR') => count($missing),
            _t('FERME_CLI_IMPORT_CANDIDATES') => count($candidates),
            _t('FERME_CLI_LEFT_OUT') => $this->describeLeftOut($selection['left']),
            ($this->isDryRun($input) ? _t('FERME_CLI_WOULD_IMPORT') : _t('FERME_CLI_IMPORTED')) => count($imported),
            _t('FERME_CLI_DB_FAILURES') => count(array_filter($inspected, static fn (array $wiki): bool => !$wiki['sqlOk'] || !$wiki['tablesOk'])),
        ], [], $this->isDryRun($input) && $input->getOption('import'));
    }

    /** @return array<string, mixed> */
    private function criteria(InputInterface $input): array
    {
        $since = (string)$input->getOption('active-since');

        return [
            'minEntries' => $this->threshold($input, 'min-entries'),
            'minPages' => $this->threshold($input, 'min-pages'),
            'minUsers' => $this->threshold($input, 'min-users'),
            'activeSince' => $since === '' ? null : $this->seconds($since),
            'nameExcludes' => (string)$input->getOption('name-excludes'),
            'skipSuspect' => $input->getOption('skip-suspect') ? $this->service(SpamScore::class)->threshold() : null,
        ];
    }

    private function threshold(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        return $value === null || $value === '' ? null : max(0, (int)$value);
    }

    /** @param array<string, list<string>> $left */
    private function describeLeftOut(array $left): string
    {
        $said = [];
        foreach ($left as $reason => $folders) {
            if ($folders !== []) {
                $said[] = count($folders) . ' ' . _t('FERME_CLI_LEFT_' . strtoupper($reason));
            }
        }

        return $said === [] ? '-' : implode(', ', $said);
    }

    /** @param list<array<string, mixed>> $inspected */
    private function writeTable(OutputInterface $output, array $inspected): void
    {
        $table = new Table($output);
        $table->setHeaders([_t('FERME_CLI_COL_FOLDER'), _t('FERME_CLI_COL_URL'), _t('FERME_CLI_COL_VERSION'), _t('FERME_CLI_COL_BAZAR'), _t('FERME_CLI_COL_PAGES'), _t('FERME_CLI_COL_ENTRIES'), _t('FERME_CLI_COL_USERS'), _t('FERME_CLI_COL_LAST_ACTIVITY'), _t('FERME_CLI_COL_DATABASE'), _t('FERME_CLI_COL_ADMIN')]);
        foreach ($inspected as $wiki) {
            $stats = is_array($wiki['stats'] ?? null) ? $wiki['stats'] : null;
            $table->addRow([
                (string)$wiki['folder'],
                (string)$wiki['url'],
                trim($wiki['version'] . ' ' . $wiki['release']),
                $wiki['existsInBazar'] ? _t('FERME_CLI_YES') : _t('FERME_CLI_NO'),
                $stats === null ? '?' : (string)($stats['pages'] ?? 0),
                $stats === null ? '?' : (string)($stats['entries'] ?? 0),
                $stats === null ? '?' : (string)($stats['users'] ?? 0),
                $stats === null ? '?' : substr((string)($stats['lastActivity'] ?? ''), 0, 10),
                $this->databaseState($wiki),
                (string)($wiki['adminEmail'] ?? ''),
            ]);
        }
        $table->render();
    }

    /** @param list<array<string, mixed>> $inspected */
    private function writeCsv(OutputInterface $output, array $inspected): void
    {
        $handle = fopen('php://memory', 'w+');
        if ($handle === false) {
            return;
        }
        fputcsv($handle, ['folder', 'path', 'url', 'version', 'release', 'inBazar', 'pages', 'entries', 'users', 'files', 'lastActivity', 'sqlOk', 'tablesOk', 'missingTables', 'sqlError', 'adminEmail'], ',', '"', '');
        foreach ($inspected as $wiki) {
            $stats = is_array($wiki['stats'] ?? null) ? $wiki['stats'] : null;
            fputcsv($handle, [
                (string)$wiki['folder'], (string)$wiki['path'], (string)$wiki['url'], (string)$wiki['version'], (string)$wiki['release'],
                $wiki['existsInBazar'] ? '1' : '0',
                $stats === null ? '' : (string)($stats['pages'] ?? 0),
                $stats === null ? '' : (string)($stats['entries'] ?? 0),
                $stats === null ? '' : (string)($stats['users'] ?? 0),
                $stats === null ? '' : (string)($stats['files'] ?? 0),
                $stats === null ? '' : (string)($stats['lastActivity'] ?? ''),
                $wiki['sqlOk'] ? '1' : '0',
                $wiki['tablesOk'] ? '1' : '0',
                implode(' ', (array)$wiki['missingTables']),
                (string)($wiki['sqlError'] ?? ''),
                (string)($wiki['adminEmail'] ?? ''),
            ], ',', '"', '');
        }
        rewind($handle);
        $output->write((string)stream_get_contents($handle));
        fclose($handle);
    }

    /** @param array<string, mixed> $wiki */
    private function databaseState(array $wiki): string
    {
        if (!$wiki['sqlOk']) {
            return '<error>' . _t('FERME_CLI_DB_ERROR') . '</error> ' . (string)$wiki['sqlError'];
        }
        if (!$wiki['tablesOk']) {
            return '<comment>' . _t('FERME_CLI_DB_MISSING_TABLES') . ' ' . implode(', ', (array)$wiki['missingTables']) . '</comment>';
        }

        return _t('FERME_CLI_DB_OK');
    }
}
