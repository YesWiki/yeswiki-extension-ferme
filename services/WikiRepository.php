<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Wiki;

/**
 * Reads the farm. The bazar entries are the list of wikis; each wiki's own database and
 * config file supply the live details the entry does not hold.
 */
class WikiRepository
{
    /** the tables a working YesWiki always has */
    public const WIKI_TABLES = ['acls', 'links', 'nature', 'pages', 'referrers', 'triples', 'users'];

    protected $wiki;
    protected $config;
    protected $params;
    protected $entryManager;
    protected $pageManager;
    protected $tripleStore;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        ParameterBagInterface $params,
        EntryManager $entryManager,
        PageManager $pageManager,
        TripleStore $tripleStore
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->params = $params;
        $this->entryManager = $entryManager;
        $this->pageManager = $pageManager;
        $this->tripleStore = $tripleStore;
    }

    /**
     * Every wiki, sorted by title, each one enriched with its live data.
     */
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
     * One page of wikis for the admin table. Filtering and sorting stay on the bazar
     * fields so only the wikis actually shown get their database opened.
     */
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

        // Column order: 0=checkbox, 1=name, 2=referent, 3=last_update, 4=admin, 5=version, 6=actions
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

    /**
     * Scan the server for wiki folders and import the ones missing from bazar.
     *
     * @param string $adminMail email to set on auto-imported entries
     * @param bool   $checkHttp whether to fetch each wiki's home page, slow on a large farm
     */
    public function searchOnServer(string $adminMail, bool $checkHttp = true): array
    {
        $wikis = $this->entryManager->search(['formsIds' => [$this->params->get('bazar_farm_id')]]);
        $wikisFolder = array_column($wikis, 'bf_dossier-wiki');

        $wikisOnServer = glob($this->config->basePath() . '/*/wakka.config.php') ?: [];

        $results = [];
        $wikisToImport = [];

        foreach ($wikisOnServer as $path) {
            $folder = basename(dirname($path));
            $wikiExistsInBazar = in_array($folder, $wikisFolder);

            $wakkaConfig = $this->config->readWikiConfig($folder);

            $result = $this->inspectWiki($folder, $wakkaConfig, $wikiExistsInBazar, $checkHttp);

            if (!$wikiExistsInBazar) {
                $wikisToImport[] = $this->buildImportEntry($folder, $wakkaConfig, $adminMail);
            }

            $results[] = $result;
        }

        return [
            'wikisInBazar' => count($wikis),
            'wikisOnServer' => count($wikisOnServer),
            'results' => $results,
            'imported' => $this->importEntries($wikisToImport),
        ];
    }

    /**
     * Check one wiki found on disk: can we reach its database, are its tables there,
     * does its home page answer.
     */
    private function inspectWiki(string $folder, array $wakkaConfig, bool $existsInBazar, bool $checkHttp): array
    {
        $url = ($wakkaConfig['base_url'] ?? '') . ($wakkaConfig['root_page'] ?? '');
        $result = [
            'folder' => $folder,
            'url' => $url,
            'existsInBazar' => $existsInBazar,
            'sqlOk' => false,
            'tablesOk' => false,
            'httpOk' => false,
            'missingTables' => [],
        ];

        $conn = @new \mysqli(
            $wakkaConfig['mysql_host'] ?? '',
            $wakkaConfig['mysql_user'] ?? '',
            $wakkaConfig['mysql_password'] ?? '',
            $wakkaConfig['mysql_database'] ?? ''
        );
        if (!$conn->connect_error) {
            $result['sqlOk'] = true;
            foreach (self::WIKI_TABLES as $table) {
                $res = mysqli_query($conn, "SHOW TABLES LIKE \"{$wakkaConfig['table_prefix']}$table\"");
                if (mysqli_num_rows($res) === 0) {
                    $result['missingTables'][] = $table;
                }
            }
            $result['tablesOk'] = empty($result['missingTables']);
        } else {
            $result['sqlError'] = $conn->connect_error;
        }

        if ($checkHttp) {
            $headers = @get_headers($url);
            $result['httpOk'] = $headers && strpos($headers[0], '200') !== false;
        }

        return $result;
    }

    private function buildImportEntry(string $folder, array $wakkaConfig, string $adminMail): array
    {
        return [
            'id_fiche' => genere_nom_wiki($wakkaConfig['wakka_name'] ?? $folder),
            'id_typeannonce' => strval($this->params->get('bazar_farm_id')),
            'bf_titre' => $wakkaConfig['wakka_name'] ?? $folder,
            'bf_description' => $wakkaConfig['meta_description'] ?? '',
            'bf_referent' => 'À préciser (importé)',
            'bf_mail' => $adminMail,
            'bf_dossier-wiki' => $folder,
            'radioListeOuiNon' => 'oui',
            'imagebf_image' => 'wiki-imported-placeholder.png',
            'date_creation_fiche' => date('Y-m-d H:i:s'),
            'statut_fiche' => '1',
            'date_maj_fiche' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array folders of the wikis that were actually imported
     */
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
        // check id if wakka.config.php contains a bad value (like string not corresponding to a form's id)
        $bazarFarmId = (!empty($bazarFarmId) && (strval($bazarFarmId) == strval(intval($bazarFarmId)))) ? $bazarFarmId : '1100';

        return $this->entryManager->search(['formsIds' => [$bazarFarmId]]);
    }

    /**
     * Enrich a bazar entry with live wiki data (version, last modification, admin presence).
     * Returns structured data for version and admin, callers are responsible for rendering.
     */
    private function processWikiEntry(array $fiche): array
    {
        $folder = $fiche['bf_dossier-wiki'];

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

    /**
     * How a hosted wiki's version compares with the farm's own.
     */
    private function describeVersion(array $wakkaConfig, string $folder): array
    {
        $wikiVersion = $wakkaConfig['yeswiki_version'] ?? '';
        $wikiRelease = $wakkaConfig['yeswiki_release'] ?? '';

        if ($this->wiki->config['yeswiki_version'] !== $wikiVersion) {
            $status = 'different';
            $updateUrl = '';
        } elseif (empty($wikiRelease) || $wikiRelease < $this->wiki->config['yeswiki_release']) {
            $status = 'outdated';
            $updateUrl = $this->wiki->href('', $this->wiki->GetPageTag(), 'maj=' . $folder);
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

    /**
     * Presence is filled in later, once we are connected to the wiki's own database.
     */
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
