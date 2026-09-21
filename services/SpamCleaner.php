<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\WikiStatsException;

/**
 * Takes the robots' pages out of a wiki they filled.
 *
 * On a farm left open to writing, most of the damage is pages the robot created
 * whole — the same dozen names in hundreds of wikis — and those simply go. The
 * few real pages it edited keep their history and lose only the lines carrying
 * the spam, because reverting is no help when the revisions behind are spam too.
 */
class SpamCleaner
{
    public const SKELETON = ['pageprincipale', 'pageheader', 'pagefooter', 'pagetitre', 'pagemenu', 'pagerapide', 'pagecss', 'pagecolor', 'bacasable', 'tableaudebord', 'gerersite', 'accueil'];
    public const WRITERS_AFTER_CLEANING = '@admins';
    public const WORDS_FOR_SPAM = 3;
    public const LINKS_FOR_SPAM = 50;
    public const LINKS_FOR_SPAM_LINE = 5;

    private $wiki;
    private $config;
    private $database;
    private $lock;

    public function __construct(\YesWiki\Wiki $wiki, FarmConfig $config, WikiDatabase $database, FolderLock $lock)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->database = $database;
        $this->lock = $lock;
    }

    /**
     * The hosts one campaign keeps pointing at, as a regular expression fragment the
     * farm sets for itself: a page linking to one of them is spam however short it is.
     */
    public function hosts(): string
    {
        return trim((string)($this->wiki->config['yeswiki-farm-spam-hosts'] ?? ''));
    }

    /**
     * What the cleaning would do, page by page, without touching anything.
     *
     * @return array<int,array{tag:string,action:string,words:int,links:int,revisions:int,owner:string}>
     */
    public function inspect(string $folder): array
    {
        $wakkaConfig = $this->config->readWikiConfig($folder);
        if (empty($wakkaConfig['table_prefix'])) {
            throw new WikiStatsException($folder, _t('FERME_CLI_NO_CONFIG_FILE'));
        }

        $db = $this->database->connect($wakkaConfig);

        try {
            return $this->look($db, (string)$wakkaConfig['table_prefix']);
        } finally {
            $db->close();
        }
    }

    /**
     * @return array{deleted:int,stripped:int,kept:int,pages:array<int,array<string,mixed>>,dump:?string}
     */
    public function clean(string $folder, bool $dryRun = true): array
    {
        $wakkaConfig = $this->config->readWikiConfig($folder);
        if (empty($wakkaConfig['table_prefix'])) {
            throw new WikiStatsException($folder, _t('FERME_CLI_NO_CONFIG_FILE'));
        }

        return $this->lock->during($this->config->wikiDir($folder), _t('FERME_LOCK_CLEAN'), function () use ($folder, $wakkaConfig, $dryRun) {
            $db = $this->database->connect($wakkaConfig);
            $prefix = (string)$wakkaConfig['table_prefix'];

            try {
                $pages = $this->look($db, $prefix);
                $todo = array_values(array_filter($pages, function (array $page) {
                    return $page['action'] !== 'keep';
                }));

                $dump = $dryRun || $todo === [] ? null : $this->dump($folder, $db, $prefix, $todo);
                if (!$dryRun) {
                    foreach ($todo as $page) {
                        if ($page['action'] === 'delete') {
                            $this->deletePage($db, $prefix, $page['tag']);
                        } else {
                            $this->stripPage($db, $prefix, $page['tag']);
                            $this->closeToWriters($db, $prefix, $page['tag']);
                        }
                    }
                }

                return [
                    'deleted' => count(array_filter($todo, fn (array $p) => $p['action'] === 'delete')),
                    'stripped' => count(array_filter($todo, fn (array $p) => $p['action'] === 'strip')),
                    'kept' => count($pages) - count($todo),
                    'pages' => $todo,
                    'dump' => $dump,
                ];
            } finally {
                $db->close();
            }
        });
    }

    /**
     * A body with nothing left once the spam is out is a page the robot wrote.
     */
    public static function strip(string $body, string $hosts = ''): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (self::isSpamLine($line, $hosts)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    private static function isSpamLine(string $line, string $hosts = ''): bool
    {
        if (preg_match(WikiStats::SPAM_VOCABULARY, $line) === 1) {
            return true;
        }
        if ($hosts !== '' && @preg_match('#(' . $hosts . ')#i', $line) === 1) {
            return true;
        }

        return preg_match_all('#https?://#i', $line) >= self::LINKS_FOR_SPAM_LINE;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function look(\mysqli $db, string $prefix): array
    {
        $hosts = $this->hosts();

        $result = $db->query(
            'SELECT p.tag, p.body, p.owner, p.comment_on,'
            . ' (SELECT COUNT(*) FROM `' . $this->database->table($prefix, 'pages') . '` r WHERE r.tag = p.tag) AS revisions'
            . ' FROM `' . $this->database->table($prefix, 'pages') . '` p WHERE p.latest = "Y"'
        );

        $pages = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $body = (string)($row['body'] ?? '');
            $words = preg_match_all(WikiStats::SPAM_VOCABULARY, $body);
            $links = preg_match_all('#https?://#i', $body);
            $known = $hosts !== '' && @preg_match('#(' . $hosts . ')#i', $body) === 1;
            if (!$known && $words < self::WORDS_FOR_SPAM && $links < self::LINKS_FOR_SPAM) {
                continue;
            }

            $tag = (string)$row['tag'];
            $pages[] = [
                'tag' => $tag,
                'action' => $this->decide($tag, $body, (int)$row['revisions'], (string)($row['owner'] ?? '')),
                'words' => (int)$words,
                'links' => (int)$links,
                'revisions' => (int)$row['revisions'],
                'owner' => (string)($row['owner'] ?? ''),
            ];
        }

        return $pages;
    }

    /**
     * A page the robot created goes; a page it edited is kept and cleaned. What
     * tells them apart is the skeleton of a wiki, a history, and an owner.
     */
    private function decide(string $tag, string $body, int $revisions, string $owner): string
    {
        if (in_array(strtolower($tag), self::SKELETON, true) || $this->startsWithSkeleton($tag)) {
            return 'strip';
        }
        if ($revisions === 1 && $owner === '') {
            return 'delete';
        }

        return self::strip($body, $this->hosts()) === '' ? 'delete' : 'strip';
    }

    private function startsWithSkeleton(string $tag): bool
    {
        foreach (self::SKELETON as $name) {
            if (str_starts_with(strtolower($tag), $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything about to be removed, written down first. A cleaning nobody can
     * undo is not a cleaning, it is another kind of damage.
     *
     * @param array<int,array<string,mixed>> $todo
     */
    private function dump(string $folder, \mysqli $db, string $prefix, array $todo): string
    {
        $dir = $this->config->ensureBackupDir('spam');
        $path = $dir . DIRECTORY_SEPARATOR . $folder . '-' . date('Ymd-His') . '.json';

        $kept = [];
        foreach ($todo as $page) {
            $result = $db->query(
                'SELECT tag, time, owner, user, body FROM `' . $this->database->table($prefix, 'pages') . '`'
                . ' WHERE tag = "' . $db->real_escape_string($page['tag']) . '" ORDER BY time DESC'
            );
            $revisions = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $revisions[] = $row;
            }
            $acls = [];
            $rights = $db->query(
                'SELECT privilege, list FROM `' . $this->database->table($prefix, 'acls') . '`'
                . ' WHERE page_tag = "' . $db->real_escape_string($page['tag']) . '"'
            );
            while ($rights && ($right = $rights->fetch_assoc())) {
                $acls[$right['privilege']] = $right['list'];
            }

            $kept[] = ['tag' => $page['tag'], 'action' => $page['action'], 'acls' => $acls, 'revisions' => $revisions];
        }

        file_put_contents($path, json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * A page cleaned once is a page the robot found open. Writing on it goes back
     * to the wiki's administrators; reading and commenting are left as they were.
     */
    private function closeToWriters(\mysqli $db, string $prefix, string $tag): void
    {
        $db->query(
            'INSERT INTO `' . $this->database->table($prefix, 'acls') . '` (page_tag, privilege, list)'
            . ' VALUES ("' . $db->real_escape_string($tag) . '", "write", "' . self::WRITERS_AFTER_CLEANING . '")'
            . ' ON DUPLICATE KEY UPDATE list = "' . self::WRITERS_AFTER_CLEANING . '"'
        );
    }

    private function deletePage(\mysqli $db, string $prefix, string $tag): void
    {
        $quoted = '"' . $db->real_escape_string($tag) . '"';
        $db->query('DELETE FROM `' . $this->database->table($prefix, 'pages') . '` WHERE tag = ' . $quoted . ' OR comment_on = ' . $quoted);
        $db->query('DELETE FROM `' . $this->database->table($prefix, 'acls') . '` WHERE page_tag = ' . $quoted);
        $db->query('DELETE FROM `' . $this->database->table($prefix, 'links') . '` WHERE from_tag = ' . $quoted);
        $db->query('DELETE FROM `' . $this->database->table($prefix, 'referrers') . '` WHERE page_tag = ' . $quoted);
        $db->query('DELETE FROM `' . $this->database->table($prefix, 'triples') . '` WHERE resource = ' . $quoted);
    }

    /**
     * The cleaned body is saved as a new revision, so the wiki's own history
     * still holds what was there and anyone can put it back.
     */
    private function stripPage(\mysqli $db, string $prefix, string $tag): void
    {
        $table = $this->database->table($prefix, 'pages');
        $quoted = '"' . $db->real_escape_string($tag) . '"';

        $row = $db->query('SELECT body, owner, user, comment_on, handler FROM `' . $table . '` WHERE tag = ' . $quoted . ' AND latest = "Y" LIMIT 1')->fetch_assoc();
        if (!$row) {
            return;
        }

        $cleaned = self::strip((string)$row['body'], $this->hosts());
        $db->query('UPDATE `' . $table . '` SET latest = "N" WHERE tag = ' . $quoted);
        $db->query(
            'INSERT INTO `' . $table . '` (tag, time, body, body_r, owner, user, latest, handler, comment_on)'
            . ' VALUES (' . $quoted . ', NOW(), "' . $db->real_escape_string($cleaned) . '", "",'
            . ' "' . $db->real_escape_string((string)$row['owner']) . '",'
            . ' "' . $db->real_escape_string((string)$row['user']) . '", "Y",'
            . ' "' . $db->real_escape_string((string)($row['handler'] ?? 'page')) . '",'
            . ' "' . $db->real_escape_string((string)$row['comment_on']) . '")'
        );
    }
}
