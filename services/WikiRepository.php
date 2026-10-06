<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Kernel\Service\ProgramVersion;

/** The farm's wikis as its entries describe them, joined to their numbers, and the wikis on disk that have no entry yet. */
class WikiRepository
{
    public function __construct(
        private readonly FarmConfig $config,
        private readonly EntryManager $entryManager,
        private readonly WikiFinder $finder,
        private readonly InstanceDatabase $database,
        private readonly WikiStatsStore $statsStore,
        private readonly FarmDashboard $dashboard,
        private readonly SpamScore $spamScore,
        private readonly WikiLifetime $lifetime
    ) {
    }

    /** @return list<array<string, mixed>> every entry of the farm, by title */
    public function getAll(): array
    {
        $fiches = $this->entries();
        usort($fiches, static fn (array $a, array $b): int => strcasecmp(FarmMailer::titleOf($a), FarmMailer::titleOf($b)));

        return array_map(fn (array $fiche): array => $this->processWikiEntry($fiche), $fiches);
    }

    /**
     * One page of the admin table, with the summary shown above it.
     *
     * @return array{fiches: list<array<string, mixed>>, total: int, filtered: int, counts: array<string, int>, totals: array<string, int>}
     */
    public function getPaginated(int $start, int $length, string $search, string $sort, string $direction, string $filter = '', string $lifetime = ''): array
    {
        $fiches = $this->entries();
        $page = $this->dashboard->select($fiches, $this->statsStore->readAll(), $this->context($fiches), [
            'search' => $search,
            'filter' => $filter,
            'lifetime' => $lifetime,
            'sort' => $sort,
            'direction' => $direction,
            'start' => $start,
            'length' => $length,
        ]);
        $page['fiches'] = array_map(fn (array $fiche): array => $this->processWikiEntry($fiche), $page['fiches']);

        return $page;
    }

    /**
     * Just enough of every wiki a filter keeps to select them all.
     *
     * @return array{wikis: list<array<string, string>>, total: int}
     */
    public function listForSelection(string $search, string $filter = '', string $lifetime = ''): array
    {
        $fiches = $this->entries();
        $page = $this->dashboard->select($fiches, $this->statsStore->readAll(), $this->context($fiches), [
            'search' => $search,
            'filter' => $filter,
            'lifetime' => $lifetime,
            'sort' => 'title',
            'direction' => 'asc',
            'start' => 0,
            'length' => max(1, count($fiches)),
        ]);
        $wikis = [];
        foreach ($page['fiches'] as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder !== '') {
                $wikis[] = ['folder' => $folder, 'tag' => (string)($fiche['tag'] ?? ''), 'title' => FarmMailer::titleOf($fiche), 'mail' => (string)($fiche['bf_mail'] ?? '')];
            }
        }

        return ['wikis' => $wikis, 'total' => $page['filtered']];
    }

    /**
     * What each wiki found on disk is, for the command that lists and imports them.
     *
     * @param list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> $wikis
     *
     * @return list<array<string, mixed>>
     */
    public function inspect(array $wikis): array
    {
        $known = array_map('strval', array_column($this->entries(), 'bf_dossier-wiki'));
        $stats = $this->statsStore->readMany(array_column($wikis, 'FOLDER'));
        $results = [];
        foreach ($wikis as $wiki) {
            $inspected = $this->inspectWiki($wiki, in_array($wiki['FOLDER'], $known, true));
            $inspected['stats'] = $stats[$wiki['FOLDER']] ?? null;
            $results[] = $inspected;
        }

        return $results;
    }

    /**
     * Give an entry to each inspected wiki that has none.
     *
     * @param list<array<string, mixed>> $inspected
     *
     * @return list<string> the folders imported
     */
    public function import(array $inspected, string $fallbackEmail = ''): array
    {
        $imported = [];
        foreach ($inspected as $wiki) {
            if (!empty($wiki['existsInBazar'])) {
                continue;
            }
            $this->entryManager->create($this->config->farmId(), [
                'bf_titre' => (string)$wiki['name'],
                'bf_description' => (string)$wiki['description'],
                'bf_referent' => _t('FERME_IMPORTED_REFERENT'),
                'bf_mail' => (string)($wiki['adminEmail'] ?? $fallbackEmail),
                'bf_dossier-wiki' => (string)$wiki['folder'],
                'bf_dossier-wiki_exists' => '1',
                'ListeOuiNon' => 'oui',
                'antispam' => 1,
            ]);
            $imported[] = (string)$wiki['folder'];
        }

        return $imported;
    }

    /**
     * The wikis on the server the farm does not list, with what they hold.
     *
     * @return array<string, mixed>
     */
    public function searchOnServer(): array
    {
        $wikis = $this->finder->find();
        $known = array_map('strval', array_column($this->entries(), 'bf_dossier-wiki'));
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
                'spamPages' => $measured === null ? null : (int)($measured['spamPages'] ?? 0),
                'lastActivity' => $measured['lastActivity'] ?? null,
            ];
        }
        usort($results, static fn (array $a, array $b): int => strcmp((string)$b['lastActivity'], (string)$a['lastActivity']));

        return ['wikisInBazar' => count($known), 'wikisOnServer' => count($wikis), 'missing' => count($results), 'unmeasured' => $unmeasured, 'results' => $results];
    }

    /**
     * Give a farm entry to the wikis an operator picked, and to nobody else.
     *
     * @param list<string> $folders
     *
     * @return list<string>
     */
    public function importFolders(array $folders, string $fallbackEmail = ''): array
    {
        $known = array_map('strval', array_column($this->entries(), 'bf_dossier-wiki'));
        $wanted = array_values(array_unique(array_filter($folders, fn (string $folder): bool => FarmConfig::isSafeName($folder, true) && !in_array($folder, $known, true) && $this->config->hasWiki($folder))));
        if ($wanted === []) {
            return [];
        }

        return $this->import($this->inspect(array_map(fn (string $folder): array => $this->finder->findOne($folder), $wanted)), $fallbackEmail);
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return array_values(array_filter($this->entryManager->search(['formsIds' => [$this->config->farmId()]]), 'is_array'));
    }

    /**
     * What the dashboard needs besides the entries and their numbers.
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return array<string, mixed>
     */
    private function context(array $fiches): array
    {
        $today = new \DateTimeImmutable('today');
        $program = new ProgramVersion();
        $onDisk = [];
        $statuses = [];
        foreach ($fiches as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder === '' || isset($onDisk[$folder]) || !FarmConfig::isSafeName($folder, true)) {
                continue;
            }
            $file = $this->config->wikiConfigFile($folder);
            $onDisk[$folder] = is_file($file);
            $statuses[$folder] = $onDisk[$folder] ? (WikiFinder::extractConfig($file)['wiki_status'] ?? '') : '';
        }

        return [
            'current' => ['version' => $program->releaseLine(), 'release' => $program->version()],
            'onDisk' => $onDisk,
            'statuses' => $statuses,
            'spamThreshold' => $this->spamScore->threshold(),
            'lifetime' => fn (array $fiche): ?array => $this->lifetime->describe($fiche, $today),
        ];
    }

    /**
     * @param array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string} $wiki
     *
     * @return array<string, mixed>
     */
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

        $wikiConfig = $this->config->readConfigIn($wiki['PATH']);
        if ($wikiConfig === []) {
            $result['sqlError'] = _t('FERME_CLI_NO_CONFIG_FILE');

            return $result;
        }
        $result['name'] = (string)($wikiConfig['yeswiki_name'] ?? $wikiConfig['wakka_name'] ?? $wiki['FOLDER']);
        $result['description'] = (string)($wikiConfig['meta_description'] ?? '');
        $result['url'] = (string)($wikiConfig['base_url'] ?? '') . (string)($wikiConfig['root_page'] ?? '');

        try {
            $db = $this->database->connect($wikiConfig, $wiki['PATH']);
            $result['sqlOk'] = true;
            $prefix = InstanceDatabase::prefix($wikiConfig);
            $tables = $this->database->ownTables($db, $prefix);
            $result['missingTables'] = array_values(array_diff(['pages', 'triples'], array_map(static fn (string $table): string => substr($table, strlen($prefix)), $tables)));
            $result['tablesOk'] = $result['missingTables'] === [];
            if ($result['tablesOk']) {
                $result['adminEmail'] = $this->firstAdminEmail($db, $prefix);
            }
        } catch (\Throwable $th) {
            $result['sqlError'] = $th->getMessage();
        }

        return $result;
    }

    /** The email of a wiki's first administrator, so an imported entry reaches whoever runs it. */
    private function firstAdminEmail(\PDO $db, string $prefix): ?string
    {
        $members = (string)InstanceDatabase::scalar($db, 'SELECT value FROM ' . InstanceDatabase::table($db, $prefix, 'triples') . ' WHERE resource = ? AND property = ?', [GROUP_PREFIX . 'admins', WIKINI_VOC_ACLS_URI]);
        $type = InstanceDatabase::quote($db, 'type');
        foreach (array_filter(array_map('trim', preg_split('/[\r\n]+/', $members) ?: [])) as $member) {
            $body = InstanceDatabase::scalar($db, 'SELECT body FROM ' . InstanceDatabase::table($db, $prefix, 'pages') . " WHERE latest = 'Y' AND {$type} = ? AND tag = ?", [PageType::USER, $member]);
            $decoded = is_string($body) ? json_decode($body, true) : null;
            $email = is_array($decoded) ? trim((string)($decoded['email'] ?? '')) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fiche
     *
     * @return array<string, mixed>
     */
    private function processWikiEntry(array $fiche): array
    {
        $folder = is_string($fiche['bf_dossier-wiki'] ?? null) ? $fiche['bf_dossier-wiki'] : '';
        if (!FarmConfig::isSafeName($folder, true)) {
            $fiche['error'] = _t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"';

            return $fiche;
        }
        if (!empty($fiche['lifetimeState']['archived'])) {
            return $fiche;
        }
        if (!$this->config->hasWiki($folder)) {
            $fiche['error'] = _t('FERME_NO_WIKI_IN') . ' ' . $folder;

            return $fiche;
        }
        $wikiConfig = WikiFinder::extractConfig($this->config->wikiConfigFile($folder));
        $fiche['url'] = ($wikiConfig['base_url'] ?? $this->config->wikiUrl($folder) . '?') . ($wikiConfig['root_page'] ?? '');
        $fiche['version'] = $this->describeVersion($wikiConfig);
        $adminName = $this->config->text('yeswiki-farm-admin-name');
        $fiche['admin'] = $adminName === '' || $this->config->text('yeswiki-farm-admin-pass') === '' ? null : [
            'name' => $adminName,
            'present' => ($fiche['stats']['adminPresent'] ?? '') === 'yes',
            'folder' => $folder,
        ];

        return $fiche;
    }

    /**
     * @param array<string, string> $wikiConfig
     *
     * @return array<string, string>
     */
    private function describeVersion(array $wikiConfig): array
    {
        $program = new ProgramVersion();
        $version = $wikiConfig['yeswiki_version'] ?? '';
        $release = $wikiConfig['yeswiki_release'] ?? '';
        $status = strtolower($version) !== strtolower($program->releaseLine()) ? 'different' : ($release !== $program->version() ? 'outdated' : 'up-to-date');

        return ['version' => $version, 'release' => $release, 'status' => $status, 'source_version' => $program->releaseLine(), 'source_release' => $program->version()];
    }
}
