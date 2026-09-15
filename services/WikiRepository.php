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

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        ParameterBagInterface $params,
        EntryManager $entryManager,
        PageManager $pageManager,
        TripleStore $tripleStore,
        WikiFinder $finder,
        WikiConfigEditor $configEditor,
        WikiDatabase $database
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

    public function getPaginated(int $start, int $length, string $search, int $orderCol, string $orderDir): array
    {
        $fiches = $this->getAllWikiFiches();
        $total = count($fiches);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $fiches = array_values(array_filter($fiches, function ($f) use ($needle) {
                return strpos(mb_strtolower($f['bf_titre'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_referent'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_mail'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_dossier-wiki'] ?? ''), $needle) !== false;
            }));
        }
        $filtered = count($fiches);

        $sortFields = [1 => 'bf_titre', 2 => 'bf_referent', 3 => 'date_maj_fiche'];
        $sortField = $sortFields[$orderCol] ?? 'bf_titre';
        usort($fiches, function ($a, $b) use ($sortField, $orderDir) {
            $cmp = strcasecmp($a[$sortField] ?? '', $b[$sortField] ?? '');

            return $orderDir === 'desc' ? -$cmp : $cmp;
        });

        $fiches = array_slice($fiches, $start, $length);
        foreach ($fiches as $i => $fiche) {
            $fiches[$i] = $this->processWikiEntry($fiche);
        }

        return ['total' => $total, 'filtered' => $filtered, 'fiches' => $fiches];
    }

    /** @param array<int,array> $wikis as WikiFinder describes them */
    public function inspect(array $wikis): array
    {
        $known = array_column($this->getAllWikiFiches(), 'bf_dossier-wiki');

        $results = [];
        foreach ($wikis as $wiki) {
            $results[] = $this->inspectWiki($wiki, in_array($wiki['FOLDER'], $known, true));
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
    public function searchOnServer(string $fallbackEmail = ''): array
    {
        $wikis = $this->finder->find();
        $inspected = $this->inspect($wikis);

        return [
            'wikisInBazar' => count($this->getAllWikiFiches()),
            'wikisOnServer' => count($wikis),
            'results' => $inspected,
            'imported' => $this->import($inspected, $fallbackEmail),
        ];
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
