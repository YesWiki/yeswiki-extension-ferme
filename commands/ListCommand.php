<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\ImportFilter;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Ferme\Service\WikiRepository;
use YesWiki\Wiki;

class ListCommand extends AbstractFarmCommand
{
    private const FORMATS = ['table', 'json', 'csv'];

    protected $repository;
    protected $filter;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->repository = $wiki->services->get(WikiRepository::class);
        $this->filter = $wiki->services->get(ImportFilter::class);
    }

    protected function configure()
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
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, self::FORMATS, true)) {
            $output->writeln('<error>' . _t('FERME_CLI_UNKNOWN_FORMAT') . ' ' . $format . ' (' . implode(', ', self::FORMATS) . ')</error>');

            return Command::FAILURE;
        }

        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        $inspected = $this->repository->inspect($wikis);

        $missingFromBazar = array_values(array_filter($inspected, function ($wiki) {
            return !$wiki['existsInBazar'];
        }));

        $selection = $this->filter->apply($inspected, $this->criteria($input));
        $candidates = $selection['keep'];

        $imported = [];
        if ($input->getOption('import')) {
            $imported = $this->isDryRun($input)
                ? array_column($candidates, 'folder')
                : $this->repository->import($candidates, $this->fallbackEmail($input));
        }

        switch ($format) {
            case 'json':
                $output->writeln(json_encode(
                    ['wikis' => $inspected, 'candidates' => array_column($candidates, 'folder'), 'imported' => $imported],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ));

                return Command::SUCCESS;

            case 'csv':
                $this->writeCsv($inspected);

                return Command::SUCCESS;
        }

        $this->writeTable($output, $inspected);

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_LIST_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($inspected),
                _t('FERME_CLI_IN_BAZAR') => count($inspected) - count($missingFromBazar),
                _t('FERME_CLI_NOT_IN_BAZAR') => count($missingFromBazar),
                _t('FERME_CLI_IMPORT_CANDIDATES') => count($candidates),
                _t('FERME_CLI_LEFT_OUT') => $this->describeLeftOut($selection['left']),
                ($this->isDryRun($input) ? _t('FERME_CLI_WOULD_IMPORT') : _t('FERME_CLI_IMPORTED')) => count($imported),
                _t('FERME_CLI_DB_FAILURES') => count(array_filter($inspected, function ($wiki) {
                    return !$wiki['sqlOk'] || !$wiki['tablesOk'];
                })),
            ],
            [],
            $this->isDryRun($input) && $input->getOption('import')
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function criteria(InputInterface $input): array
    {
        $since = (string)$input->getOption('active-since');

        return [
            'minEntries' => $this->threshold($input, 'min-entries'),
            'minPages' => $this->threshold($input, 'min-pages'),
            'minUsers' => $this->threshold($input, 'min-users'),
            'activeSince' => $since === '' ? null : $this->seconds($since),
            'nameExcludes' => (string)$input->getOption('name-excludes'),
            'skipSuspect' => $input->getOption('skip-suspect')
                ? $this->wiki->services->get(SpamScore::class)->threshold()
                : null,
        ];
    }

    private function threshold(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        return $value === null || $value === '' ? null : max(0, (int)$value);
    }

    /**
     * @param array<string,array<int,string>> $left
     */
    private function describeLeftOut(array $left): string
    {
        $said = [];
        foreach ($left as $reason => $folders) {
            if (!empty($folders)) {
                $said[] = count($folders) . ' ' . _t('FERME_CLI_LEFT_' . strtoupper($reason));
            }
        }

        return empty($said) ? '-' : implode(', ', $said);
    }

    /**
     * Used on imported entries for the wikis whose own admins have no email.
     */
    private function fallbackEmail(InputInterface $input): string
    {
        $email = trim((string)$input->getOption('email'));

        return $email !== '' ? $email : $this->farmConfig->adminEmail();
    }

    private function writeTable(OutputInterface $output, array $inspected): void
    {
        $table = new Table($output);
        $table->setHeaders([
            _t('FERME_CLI_COL_FOLDER'),
            _t('FERME_CLI_COL_URL'),
            _t('FERME_CLI_COL_VERSION'),
            _t('FERME_CLI_COL_BAZAR'),
            _t('FERME_CLI_COL_PAGES'),
            _t('FERME_CLI_COL_ENTRIES'),
            _t('FERME_CLI_COL_USERS'),
            _t('FERME_CLI_COL_LAST_ACTIVITY'),
            _t('FERME_CLI_COL_DATABASE'),
            _t('FERME_CLI_COL_ADMIN'),
        ]);
        foreach ($inspected as $wiki) {
            $stats = $wiki['stats'] ?? null;
            $table->addRow([
                $wiki['folder'],
                $wiki['url'],
                trim($wiki['version'] . ' ' . $wiki['release']),
                $wiki['existsInBazar'] ? _t('FERME_CLI_YES') : _t('FERME_CLI_NO'),
                $stats === null ? '?' : (string)($stats['pages'] ?? 0),
                $stats === null ? '?' : (string)($stats['entries'] ?? 0),
                $stats === null ? '?' : (string)($stats['users'] ?? 0),
                $stats === null ? '?' : substr((string)($stats['lastActivity'] ?? ''), 0, 10),
                $this->databaseState($wiki),
                $wiki['adminEmail'] ?? '',
            ]);
        }
        $table->render();
    }

    private function writeCsv(array $inspected): void
    {
        $handle = fopen('php://output', 'w');
        // the escape argument is explicit: its default changes in php 8.4
        fputcsv($handle, ['folder', 'path', 'url', 'version', 'release', 'inBazar', 'pages', 'entries', 'users', 'files', 'lastActivity', 'sqlOk', 'tablesOk', 'missingTables', 'sqlError', 'adminEmail'], ',', '"', '');
        foreach ($inspected as $wiki) {
            $stats = $wiki['stats'] ?? null;
            fputcsv($handle, [
                $wiki['folder'],
                $wiki['path'],
                $wiki['url'],
                $wiki['version'],
                $wiki['release'],
                $wiki['existsInBazar'] ? '1' : '0',
                $stats === null ? '' : (string)($stats['pages'] ?? 0),
                $stats === null ? '' : (string)($stats['entries'] ?? 0),
                $stats === null ? '' : (string)($stats['users'] ?? 0),
                $stats === null ? '' : (string)($stats['files'] ?? 0),
                $stats === null ? '' : (string)($stats['lastActivity'] ?? ''),
                $wiki['sqlOk'] ? '1' : '0',
                $wiki['tablesOk'] ? '1' : '0',
                implode(' ', $wiki['missingTables']),
                $wiki['sqlError'] ?? '',
                $wiki['adminEmail'] ?? '',
            ], ',', '"', '');
        }
        fclose($handle);
    }

    private function databaseState(array $wiki): string
    {
        if (!$wiki['sqlOk']) {
            return '<error>' . _t('FERME_CLI_DB_ERROR') . '</error> ' . $wiki['sqlError'];
        }
        if (!$wiki['tablesOk']) {
            return '<comment>' . _t('FERME_CLI_DB_MISSING_TABLES') . ' ' . implode(', ', $wiki['missingTables']) . '</comment>';
        }

        return _t('FERME_CLI_DB_OK');
    }
}
