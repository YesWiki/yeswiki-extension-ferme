<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Entity\PageBody;
use YesWiki\Content\Entity\PageType;

/** The pages another wiki shows, read from its database: what the spam rules look at. */
class WikiPages
{
    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceDatabase $database
    ) {
    }

    /**
     * The current pages, its home page first then the most recently written.
     *
     * @return list<array{tag: string, content: string, owner: string, revisions: int}>
     */
    public function current(string $folder, ?int $limit = null, bool $withRevisions = false): array
    {
        [$db, $pages, $wikiConfig] = $this->open($folder);
        $type = InstanceDatabase::quote($db, 'type');
        $time = InstanceDatabase::quote($db, 'time');
        $revisions = $withRevisions ? ", (SELECT COUNT(*) FROM {$pages} r WHERE r.tag = p.tag) AS revisions" : ', 1 AS revisions';
        $sql = "SELECT p.tag, p.body, p.owner{$revisions} FROM {$pages} p WHERE p.latest = 'Y' AND p.{$type} IN (?, ?)"
            . " ORDER BY CASE WHEN p.tag = ? THEN 0 ELSE 1 END, p.{$time} DESC" . ($limit === null ? '' : ' LIMIT ' . max(1, $limit));

        $read = [];
        foreach (InstanceDatabase::rows($db, $sql, [PageType::PAGE, PageType::COMMENT, (string)($wikiConfig['root_page'] ?? 'PagePrincipale')]) as $row) {
            $read[] = [
                'tag' => (string)$row['tag'],
                'content' => self::contentOf($row['body'] ?? null),
                'owner' => (string)($row['owner'] ?? ''),
                'revisions' => (int)($row['revisions'] ?? 1),
            ];
        }

        return $read;
    }

    /** The current text of one page, or null when the wiki has no such page. */
    public function content(string $folder, string $tag): ?string
    {
        [$db, $pages] = $this->open($folder);
        $rows = InstanceDatabase::rows($db, "SELECT body FROM {$pages} WHERE latest = 'Y' AND tag = ?", [$tag]);

        return $rows === [] ? null : self::contentOf($rows[0]['body'] ?? null);
    }

    /**
     * Every revision of a page, newest first, as it is stored.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(string $folder, string $tag): array
    {
        [$db, $pages] = $this->open($folder);
        $time = InstanceDatabase::quote($db, 'time');
        $user = InstanceDatabase::quote($db, 'user');

        return InstanceDatabase::rows($db, "SELECT tag, {$time} AS time, owner, {$user} AS user, body, metadata FROM {$pages} WHERE tag = ? ORDER BY {$time} DESC", [$tag]);
    }

    public static function contentOf(mixed $body): string
    {
        return PageBody::content(PageBody::decode(is_string($body) ? $body : null));
    }

    /** @return array{0: \PDO, 1: string, 2: array<string, mixed>} */
    private function open(string $folder): array
    {
        $wikiConfig = $this->config->readWikiConfig($folder);
        if ($wikiConfig === []) {
            throw new \RuntimeException(_t('FERME_CLI_NO_CONFIG_FILE') . ' ' . $folder);
        }
        $db = $this->database->connect($wikiConfig, $this->config->wikiDir($folder));

        return [$db, InstanceDatabase::table($db, InstanceDatabase::prefix($wikiConfig), 'pages'), $wikiConfig];
    }
}
