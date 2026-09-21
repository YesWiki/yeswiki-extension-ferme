<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Wiki;

class WikiRepository
{
    public const WIKI_TABLES = WikiDatabase::WIKI_TABLES;

    protected $wiki;
    protected $config;
    protected $params;
    protected $entryManager;
    protected $pageManager;
    protected $tripleStore;
    protected $finder;
    protected $configEditor;
    protected $database;
    protected $statsStore;
    protected $dashboard;
    protected $spamScore;
    protected $aside;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        ParameterBagInterface $params,
        EntryManager $entryManager,
        PageManager $pageManager,
        TripleStore $tripleStore,
        WikiFinder $finder,
        WikiConfigEditor $configEditor,
        WikiDatabase $database,
        WikiStatsStore $statsStore,
        FarmDashboard $dashboard,
        SpamScore $spamScore,
        CustomAside $aside
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->params = $params;
        $this->entryManager = $entryManager;
        $this->pageManager = $pageManager;
        $this->tripleStore = $tripleStore;
        $this->finder = $finder;
        $this->configEditor = $configEditor;
        $this->database = $database;
        $this->statsStore = $statsStore;
        $this->dashboard = $dashboard;
        $this->spamScore = $spamScore;
        $this->aside = $aside;
    }

    public function getAll(): array
    {
        $fiches = $this->getAllWikiFiches();
        usort($fiches, function ($a, $b) {
            return strcasecmp($a['bf_titre'] ?? '', $b['bf_titre'] ?? '');
        });

        foreach ($fiches as $i => $fiche) {
            $fiches[$i] = $this->processWikiEntry($fiche);
        }

        return $fiches;
    }

    /**
     * One page of the admin table: the wikis a chip and a search leave, in the
     * order asked for, with the summary the page shows above them.
     *
     * @return array{total:int,filtered:int,fiches:array,counts:array<string,int>,totals:array<string,int>}
     */
    public function getPaginated(
        int $start,
        int $length,
        string $search,
        string $sort,
        string $direction,
        string $filter = ''
    ): array {
        $fiches = $this->getAllWikiFiches();

        $page = $this->dashboard->select(
            $fiches,
            $this->statsStore->readAll(),
            [
                'current' => [
                    'version' => (string)$this->wiki->config['yeswiki_version'],
                    'release' => (string)$this->wiki->config['yeswiki_release'],
                ],
                'onDisk' => $this->wikisOnDisk($fiches),
                'statuses' => $this->statuses($fiches),
                'spamThreshold' => $this->spamScore->threshold(),
            ],
            [
                'search' => $search,
                'filter' => $filter,
                'sort' => $sort,
                'direction' => $direction,
                'start' => $start,
                'length' => $length,
            ]
        );

        foreach ($page['fiches'] as $index => $fiche) {
            $page['fiches'][$index] = $this->processWikiEntry($fiche);
        }

        return $page;
    }

    /**
     * Just enough of every wiki the filter keeps to select them all: the page is
     * limited to a hundred rows, and an operator cleaning a farm needs the lot.
     * None of the per-wiki work of a page is done here.
     *
     * @return array{wikis:array<int,array<string,string>>,total:int}
     */
    public function listForSelection(string $search, string $filter = ''): array
    {
        $fiches = $this->getAllWikiFiches();

        $page = $this->dashboard->select(
            $fiches,
            $this->statsStore->readAll(),
            [
                'current' => [
                    'version' => (string)$this->wiki->config['yeswiki_version'],
                    'release' => (string)$this->wiki->config['yeswiki_release'],
                ],
                'onDisk' => $this->wikisOnDisk($fiches),
                'spamThreshold' => $this->spamScore->threshold(),
            ],
            [
                'search' => $search,
                'filter' => $filter,
                'sort' => 'title',
                'direction' => 'asc',
                'start' => 0,
                'length' => count($fiches) ?: 1,
            ]
        );

        $wikis = [];
        foreach ($page['fiches'] as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder === '') {
                continue;
            }
            $wikis[] = [
                'folder' => $folder,
                'id_fiche' => (string)($fiche['id_fiche'] ?? ''),
                'title' => (string)($fiche['bf_titre'] ?? $folder),
                'mail' => (string)($fiche['bf_mail'] ?? ''),
            ];
        }

        return ['wikis' => $wikis, 'total' => $page['filtered']];
    }

    /**
     * The `wiki_status` of every wiki, read from its configuration without including
     * it. Counting the sleeping ones means knowing it for all of them, not only for
     * the hundred a page shows — reading three thousand of these costs 60 ms.
     *
     * @param array<int,array<string,mixed>> $fiches
     *
     * @return array<string,string>
     */
    private function statuses(array $fiches): array
    {
        $statuses = [];
        foreach ($fiches as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder === '' || isset($statuses[$folder]) || !FarmConfig::isSafeName($folder, true)) {
                continue;
            }
            $path = $this->config->wikiConfigFile($folder);
            if (!is_file($path)) {
                continue;
            }
            $content = (string)@file_get_contents($path);
            $statuses[$folder] = preg_match('/[\'"]wiki_status[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $matches) === 1
                ? $matches[1]
                : '';
        }

        return $statuses;
    }

    /**
     * Which of the farm entries still have a wiki behind them. One stat per entry,
     * about 4 ms for a farm of 2 700, so an entry left over from a deleted wiki is
     * counted with the broken ones instead of passing for one nobody measured yet.
     *
     * @param array<int,array<string,mixed>> $fiches
     *
     * @return array<string,bool>
     */
    private function wikisOnDisk(array $fiches): array
    {
        $onDisk = [];
        foreach ($fiches as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder === '' || isset($onDisk[$folder]) || !FarmConfig::isSafeName($folder, true)) {
                continue;
            }
            $onDisk[$folder] = file_exists($this->config->wikiConfigFile($folder));
        }

        return $onDisk;
    }

    /** @param array<int,array> $wikis as WikiFinder describes them */
    public function inspect(array $wikis): array
    {
        $known = array_column($this->getAllWikiFiches(), 'bf_dossier-wiki');
        $stats = $this->statsStore->readMany(array_column($wikis, 'FOLDER'));

        $results = [];
        foreach ($wikis as $wiki) {
            $inspected = $this->inspectWiki($wiki, in_array($wiki['FOLDER'], $known, true));
            $inspected['stats'] = $stats[$wiki['FOLDER']] ?? null;
            $results[] = $inspected;
        }

        return $results;
    }

    /** @return array<int,string> the folders that got a farm entry */
    public function import(array $inspected, string $fallbackEmail = ''): array
    {
        $toImport = [];
        foreach ($inspected as $wiki) {
            if ($wiki['existsInBazar']) {
                continue;
            }
            $toImport[] = $this->buildImportEntry($wiki, $fallbackEmail);
        }

        return $this->importEntries($toImport);
    }

    /** What the AdminWikis search button calls: inspect the farm root, then import. */
    /**
     * The wikis sitting on the server that the farm does not list, with what they
     * hold, so an operator can see what they would be importing.
     *
     * It opens no wiki database: on a farm of a few thousand, one connection per
     * wiki is minutes of work inside a web request, which is what used to make this
     * time out. The numbers come from the statistics already measured, and nothing
     * is imported here: that is a separate, deliberate step.
     *
     * @return array{wikisInBazar:int,wikisOnServer:int,missing:int,unmeasured:int,results:array<int,array<string,mixed>>}
     */
    public function searchOnServer(): array
    {
        $wikis = $this->finder->find();
        $known = array_column($this->getAllWikiFiches(), 'bf_dossier-wiki');
        $stats = $this->statsStore->readMany(array_column($wikis, 'FOLDER'));

        $results = [];
        $unmeasured = 0;
        foreach ($wikis as $wiki) {
            if (in_array($wiki['FOLDER'], $known, true)) {
                continue;
            }
            $measured = $stats[$wiki['FOLDER']] ?? null;
            $unmeasured += $measured === null ? 1 : 0;
            $results[] = [
                'folder' => $wiki['FOLDER'],
                'url' => $wiki['URL'] === 'KO' ? '' : $wiki['URL'],
                'version' => trim($wiki['VERSION'] . ' ' . $wiki['RELEASE']),
                'pages' => $measured === null ? null : (int)($measured['pages'] ?? 0),
                'entries' => $measured === null ? null : (int)($measured['entries'] ?? 0),
                'users' => $measured === null ? null : (int)($measured['users'] ?? 0),
                'lastActivity' => $measured['lastActivity'] ?? null,
            ];
        }

        usort($results, function (array $a, array $b) {
            return strcmp((string)$b['lastActivity'], (string)$a['lastActivity']);
        });

        return [
            'wikisInBazar' => count($known),
            'wikisOnServer' => count($wikis),
            'missing' => count($results),
            'unmeasured' => $unmeasured,
            'results' => $results,
        ];
    }

    /**
     * Give a farm entry to the wikis an operator picked, and to nobody else.
     *
     * @param array<int,string> $folders
     *
     * @return array<int,string> the folders that got one
     */
    public function importFolders(array $folders, string $fallbackEmail = ''): array
    {
        $known = array_column($this->getAllWikiFiches(), 'bf_dossier-wiki');
        $wanted = array_values(array_unique(array_filter($folders, function ($folder) use ($known) {
            return is_string($folder)
                && FarmConfig::isSafeName($folder, true)
                && !in_array($folder, $known, true)
                && is_file($this->config->wikiConfigFile($folder));
        })));
        if (empty($wanted)) {
            return [];
        }

        return $this->import($this->inspect(array_map(function (string $folder) {
            return $this->finder->findOne($folder);
        }, $wanted)), $fallbackEmail);
    }

    private function inspectWiki(array $wiki, bool $existsInBazar): array
    {
        $result = [
            'folder' => $wiki['FOLDER'],
            'path' => $wiki['PATH'],
            'url' => $wiki['URL'] === 'KO' ? '' : $wiki['URL'],
            'version' => $wiki['VERSION'],
            'release' => $wiki['RELEASE'],
            'name' => $wiki['FOLDER'],
            'description' => '',
            'existsInBazar' => $existsInBazar,
            'sqlOk' => false,
            'tablesOk' => false,
            'missingTables' => [],
            'sqlError' => null,
            'adminEmail' => null,
        ];

        try {
            $wakkaConfig = $this->configEditor->load($wiki['PATH']);
        } catch (\Throwable $th) {
            $result['sqlError'] = $th->getMessage();

            return $result;
        }

        $result['name'] = $wakkaConfig['wakka_name'] ?? $wiki['FOLDER'];
        $result['description'] = $wakkaConfig['meta_description'] ?? '';
        $result['url'] = ($wakkaConfig['base_url'] ?? '') . ($wakkaConfig['root_page'] ?? '');
        $prefix = $wakkaConfig['table_prefix'] ?? '';

        try {
            $db = $this->database->connect($wakkaConfig);
        } catch (\Throwable $th) {
            $result['sqlError'] = $th->getMessage();

            return $result;
        }

        try {
            $result['sqlOk'] = true;
            $result['missingTables'] = $this->database->missingTables($db, $prefix);
            $result['tablesOk'] = empty($result['missingTables']);
            if ($result['tablesOk']) {
                $result['adminEmail'] = $this->database->firstAdminEmail($db, $prefix);
            }
        } catch (\Throwable $th) {
            $result['sqlError'] = $th->getMessage();
        } finally {
            $db->close();
        }

        return $result;
    }

    private function buildImportEntry(array $wiki, string $fallbackEmail): array
    {
        return [
            'id_fiche' => genere_nom_wiki($wiki['name']),
            'id_typeannonce' => strval($this->params->get('bazar_farm_id')),
            'bf_titre' => $wiki['name'],
            'bf_description' => $wiki['description'],
            'bf_referent' => _t('FERME_IMPORTED_REFERENT'),
            'bf_mail' => $wiki['adminEmail'] ?? $fallbackEmail,
            'bf_dossier-wiki' => $wiki['folder'],
            'radioListeOuiNon' => 'oui',
            'imagebf_image' => 'wiki-imported-placeholder.png',
            'date_creation_fiche' => date('Y-m-d H:i:s'),
            'statut_fiche' => '1',
            'date_maj_fiche' => date('Y-m-d H:i:s'),
        ];
    }

    private function importEntries(array $wikisToImport): array
    {
        if (empty($wikisToImport)) {
            return [];
        }

        if (!file_exists('files/wiki-imported-placeholder.png')) {
            copy('tools/ferme/images/wiki-imported-placeholder.png', 'files/wiki-imported-placeholder.png');
        }

        $imported = [];
        foreach ($wikisToImport as $w) {
            $saved = $this->pageManager->save($w['id_fiche'], json_encode($w), '', true);
            if ($saved == 0) {
                $this->tripleStore->create($w['id_fiche'], TripleStore::TYPE_URI, 'fiche_bazar', '', '');
                $imported[] = $w['bf_dossier-wiki'];
            }
        }

        return $imported;
    }

    private function getAllWikiFiches(): array
    {
        $bazarFarmId = $this->params->get('bazar_farm_id');

        $bazarFarmId = (!empty($bazarFarmId) && (strval($bazarFarmId) == strval(intval($bazarFarmId)))) ? $bazarFarmId : '1100';

        return $this->entryManager->search(['formsIds' => [$bazarFarmId]]);
    }

    private function processWikiEntry(array $fiche): array
    {
        $folder = is_string($fiche['bf_dossier-wiki'] ?? null) ? $fiche['bf_dossier-wiki'] : '';

        if (!FarmConfig::isSafeName($folder, true)) {
            $fiche['error'] = _t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"';

            return $fiche;
        }

        if (!file_exists($this->config->wikiConfigFile($folder))) {
            $fiche['error'] = _t('FERME_FILE') . $folder . '/wakka.config.php' . _t('FERME_NOT_FOUND');

            return $fiche;
        }

        $wakkaConfig = $this->config->readWikiConfig($folder);
        if (empty($wakkaConfig['table_prefix'])) {
            return $fiche;
        }

        $fiche['custom_aside'] = $this->aside->isAside($this->config->wikiDir($folder));
        $fiche['url'] = $wakkaConfig['base_url'] . $wakkaConfig['root_page'];
        $fiche['version'] = $this->describeVersion($wakkaConfig, $folder);
        $fiche['admin'] = $this->describeAdmin($folder);

        $this->wiki->query('USE ' . $wakkaConfig['mysql_database'] . ';');
        try {
            if ($fiche['admin'] !== null) {
                $sql = 'SELECT name FROM ' . $wakkaConfig['table_prefix'] . 'users WHERE name="'
                    . addslashes($fiche['admin']['name']) . '"';
                $fiche['admin']['present'] = count($this->wiki->LoadAll($sql)) > 0;
            }

            $wikiresults = $this->wiki->LoadAll('SELECT time FROM `' . $wakkaConfig['table_prefix'] . 'pages` WHERE latest="Y" ORDER BY time DESC LIMIT 1');
            $fiche['last_modification_iso'] = $wikiresults[0]['time'] ?? '';
            if (!empty($fiche['last_modification_iso'])) {
                $date = new \DateTime($fiche['last_modification_iso']);
                $fiche['last_modification'] = $date->format('d.m.Y H:i:s');
            }
        } finally {
            $this->wiki->query('USE ' . $this->wiki->config['mysql_database'] . ';');
        }

        $fiche['dashboard_link'] = $wakkaConfig['base_url'] . 'TableauDeBord';

        return $fiche;
    }

    private function describeVersion(array $wakkaConfig, string $folder): array
    {
        $wikiVersion = $wakkaConfig['yeswiki_version'] ?? '';
        $wikiRelease = $wakkaConfig['yeswiki_release'] ?? '';

        if ($this->wiki->config['yeswiki_version'] !== $wikiVersion) {
            $status = 'different';
            $updateUrl = '';
        } elseif (empty($wikiRelease) || $wikiRelease < $this->wiki->config['yeswiki_release']) {
            $status = 'outdated';
            $token = $this->wiki->services->get(CsrfTokenManager::class)->getToken('main')->getValue();
            $updateUrl = $this->wiki->href(
                '',
                $this->wiki->GetPageTag(),
                ['maj' => $folder, 'csrf-token' => $token],
                false
            );
        } else {
            $status = 'up-to-date';
            $updateUrl = '';
        }

        return [
            'version' => $wikiVersion,
            'release' => $wikiRelease,
            'status' => $status,
            'update_url' => $updateUrl,
            'source_version' => $this->wiki->config['yeswiki_version'],
        ];
    }

    private function describeAdmin(string $folder): ?array
    {
        $adminName = $this->wiki->config['yeswiki-farm-admin-name'];
        $adminPass = $this->wiki->config['yeswiki-farm-admin-pass'];

        if (empty($adminName) || empty($adminPass)) {
            return null;
        }

        return [
            'name' => $adminName,
            'present' => false,
            'folder' => $folder,
        ];
    }
}
