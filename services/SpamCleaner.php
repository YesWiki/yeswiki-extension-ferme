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

    /**
     * Line breaks, spelled out. `\R` on a pattern without `u` also matches the
     * single byte 0x85, which sits inside plenty of Cyrillic and CJK characters:
     * splitting there cuts them in half and the database refuses what comes back.
     */
    public const LINES = '/\r\n|\r|\n/';
    public const WORDS_FOR_SPAM = 2;
    public const LINKS_FOR_SPAM = 50;
    public const LINKS_FOR_SPAM_LINE = 5;
    public const TEXT_BESIDE_LINK = 20;
    public const LINES_FOR_LINK_FARM = 10;
    public const SHARE_FOR_LINK_FARM = 0.6;
    public const LINES_FOR_LINK_LIST = 50;
    public const SHARE_FOR_LINK_LIST = 0.8;
    public const SHARE_FOR_MANY_LINKS = 0.3;

    private $wiki;
    private $config;
    private $database;
    private $lock;
    private $hibernator;
    private $refresher;
    private $fingerprints;

    public function __construct(
        \YesWiki\Wiki $wiki,
        FarmConfig $config,
        WikiDatabase $database,
        FolderLock $lock,
        WikiHibernator $hibernator,
        StatsRefresher $refresher,
        SpamFingerprints $fingerprints
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->database = $database;
        $this->lock = $lock;
        $this->hibernator = $hibernator;
        $this->refresher = $refresher;
        $this->fingerprints = $fingerprints;
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
     * The pages a wiki is condemned for and that no rule can touch: the cleaning
     * would run and change nothing, so the wiki stays marked forever. Each one
     * comes with the hosts its links point at, which is what an operator needs to
     * decide — add the host to `yeswiki-farm-spam-hosts`, or leave the page alone.
     *
     * @return array<int,array{tag:string,links:int,share:float,hosts:array<string,int>}>
     */
    public function stuck(string $folder): array
    {
        $wakkaConfig = $this->config->readWikiConfig($folder);
        if (empty($wakkaConfig['table_prefix'])) {
            throw new WikiStatsException($folder, _t('FERME_CLI_NO_CONFIG_FILE'));
        }

        $db = $this->database->connect($wakkaConfig);
        $hosts = $this->hosts();

        try {
            $result = $db->query(
                'SELECT tag, body FROM `' . $this->database->table((string)$wakkaConfig['table_prefix'], 'pages') . '`'
                . ' WHERE latest = "Y"'
            );

            $stuck = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $body = (string)$row['body'];
                $words = (int)preg_match_all(WikiStats::SPAM_VOCABULARY, $body);
                preg_match_all('#https?://([a-z0-9.\-]+)#i', $body, $found);
                $links = count($found[1] ?? []);
                if (!self::isSpamPage($body, $words, $links, $hosts, $this->fingerprints->isCampaignPage($body))) {
                    continue;
                }
                if (self::strip($body, $hosts, $this->fingerprints) !== trim($body)) {
                    continue;
                }

                $counted = array_count_values(array_map('strtolower', $found[1] ?? []));
                arsort($counted);
                $stuck[] = [
                    'tag' => (string)$row['tag'],
                    'links' => $links,
                    'share' => self::linkShare(preg_split(self::LINES, $body) ?: []),
                    'hosts' => array_slice($counted, 0, 3, true),
                ];
            }

            return $stuck;
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
            $awoken = $dryRun ? false : $this->wakeUp($folder, $wakkaConfig);
            $db = null;
            $prefix = (string)$wakkaConfig['table_prefix'];

            try {
                $db = $this->database->connect($wakkaConfig);
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

                if (!$dryRun && $todo !== []) {
                    $this->remeasure($folder);
                }

                return [
                    'deleted' => count(array_filter($todo, fn (array $p) => $p['action'] === 'delete')),
                    'stripped' => count(array_filter($todo, fn (array $p) => $p['action'] === 'strip')),
                    'kept' => count($pages) - count($todo),
                    'pages' => $todo,
                    'dump' => $dump,
                ];
            } finally {
                if ($db !== null) {
                    $db->close();
                }
                if ($awoken) {
                    $this->hibernator->hibernate($folder);
                }
            }
        });
    }

    /**
     * A sleeping wiki is cleaned like any other, and goes back to sleep after. The
     * farm writes to its database directly, so the status does not stand in the
     * way — waking it is what keeps the wiki honest about its own state while
     * somebody is working on it.
     */
    private function wakeUp(string $folder, array $wakkaConfig): bool
    {
        if (!WikiHibernator::isAsleep(trim((string)($wakkaConfig['wiki_status'] ?? '')))) {
            return false;
        }

        return $this->hibernator->wake($folder)['changed'];
    }

    /**
     * Pages left with no current revision, given theirs back.
     *
     * Writing a cleaned page used to be two statements without a transaction: when
     * the second was refused, the page kept no revision marked current and vanished
     * from its wiki. It cannot happen again, and this puts back what did.
     *
     * @return array{repaired:array<int,string>,pages:int}
     */
    public function repair(string $folder, bool $dryRun = true): array
    {
        $wakkaConfig = $this->config->readWikiConfig($folder);
        if (empty($wakkaConfig['table_prefix'])) {
            throw new WikiStatsException($folder, _t('FERME_CLI_NO_CONFIG_FILE'));
        }

        return $this->lock->during($this->config->wikiDir($folder), _t('FERME_LOCK_CLEAN'), function () use ($folder, $wakkaConfig, $dryRun) {
            $awoken = $dryRun ? false : $this->wakeUp($folder, $wakkaConfig);
            $db = null;
            $table = $this->database->table((string)$wakkaConfig['table_prefix'], 'pages');

            try {
                $db = $this->database->connect($wakkaConfig);
                $headless = [];
                $result = $db->query(
                    'SELECT tag FROM `' . $table . '` GROUP BY tag HAVING SUM(latest = "Y") = 0'
                );
                while ($result && ($row = $result->fetch_assoc())) {
                    $headless[] = (string)$row['tag'];
                }

                if (!$dryRun) {
                    foreach ($headless as $tag) {
                        $db->query(
                            'UPDATE `' . $table . '` SET latest = "Y"'
                            . ' WHERE tag = "' . $db->real_escape_string($tag) . '"'
                            . ' ORDER BY time DESC, id DESC LIMIT 1'
                        );
                    }
                }

                if (!$dryRun && $headless !== []) {
                    $this->remeasure($folder);
                }

                return ['repaired' => $headless, 'pages' => count($headless)];
            } finally {
                if ($db !== null) {
                    $db->close();
                }
                if ($awoken) {
                    $this->hibernator->hibernate($folder);
                }
            }
        });
    }

    /**
     * Count the wiki again, now that its pages have changed: the page that ordered
     * the cleaning shows the new figures rather than the ones from before. Only the
     * database is read again — the cleaning moves no file. A failure here is left
     * alone, the sweep puts the numbers right within the quarter of an hour.
     */
    private function remeasure(string $folder): void
    {
        $this->refresher->remeasure($folder, false);
    }

    /**
     * The one rule: what the cleaning would touch. The statistics ask it too, so a
     * wiki is marked "contenu spammé" when — and only when — there is work here.
     */
    public static function isSpamPage(string $body, int $words, int $links, string $hosts = '', bool $campaign = false): bool
    {
        if ($campaign || $words >= self::WORDS_FOR_SPAM) {
            return true;
        }
        if ($links >= self::LINKS_FOR_SPAM && self::linkShare(preg_split(self::LINES, $body) ?: []) >= self::SHARE_FOR_MANY_LINKS) {
            return true;
        }

        return $hosts !== '' && @preg_match('#(' . $hosts . ')#i', $body) === 1;
    }

    /**
     * How much of what a page says is links. Minutes of a meeting quote fifty
     * addresses among two thousand lines of prose; a robot's page has nothing else
     * to say.
     *
     * @param array<int,string> $lines
     */
    public static function linkShare(array $lines): float
    {
        $carrying = 0;
        $written = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $written++;
            if (preg_match('#https?://#i', $line) === 1) {
                $carrying++;
            }
        }

        return $written === 0 ? 0.0 : $carrying / $written;
    }

    /**
     * The lines of a page that is little more than a stack of links, by their
     * position. This decides what a cleaning takes out, never whether a page is
     * spam: a page listing its resources looks the same from far away, and only
     * pages already condemned are ever stripped.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,true> the indexes of the lines to take out
     */
    public static function linkFarm(array $lines): array
    {
        $bare = [];
        $carrying = [];
        $written = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $written++;
            if (preg_match('#https?://#i', $line) !== 1 || str_contains($line, '{{')) {
                continue;
            }
            $carrying[$index] = true;
            $beside = trim((string)preg_replace('#\[\[|\]\]|https?://\S+#i', '', $line));
            if (strlen($beside) <= self::TEXT_BESIDE_LINK) {
                $bare[$index] = true;
            }
        }

        if (count($bare) >= self::LINES_FOR_LINK_FARM && count($bare) / max(1, $written) >= self::SHARE_FOR_LINK_FARM) {
            return $bare;
        }

        return count($carrying) >= self::LINES_FOR_LINK_LIST && count($carrying) / max(1, $written) >= self::SHARE_FOR_LINK_LIST
            ? $carrying
            : [];
    }

    /**
     * A body with nothing left once the spam is out is a page the robot wrote.
     */
    public static function strip(string $body, string $hosts = '', ?SpamFingerprints $campaigns = null): string
    {
        $lines = preg_split(self::LINES, $body) ?: [];
        $farm = self::linkFarm($lines);

        $kept = [];
        foreach ($lines as $index => $line) {
            if (isset($farm[$index]) || self::isSpamLine($line, $hosts) || ($campaigns !== null && $campaigns->isKnownLine($line))) {
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
            $campaign = $this->fingerprints->isCampaignPage($body);
            if (!self::isSpamPage($body, (int)$words, (int)$links, $hosts, $campaign)) {
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
                'campaign' => $campaign,
                'host' => $hosts !== '' && @preg_match('#(' . $hosts . ')#i', $body) === 1,
                'cleanable' => self::strip($body, $hosts, $this->fingerprints) !== trim($body),
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

        return self::strip($body, $this->hosts(), $this->fingerprints) === '' ? 'delete' : 'strip';
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

        $cleaned = self::strip((string)$row['body'], $this->hosts(), $this->fingerprints);

        $db->begin_transaction();

        try {
            $db->query('UPDATE `' . $table . '` SET latest = "N" WHERE tag = ' . $quoted);
            $db->query(
                'INSERT INTO `' . $table . '` (tag, time, body, body_r, owner, user, latest, handler, comment_on)'
                . ' VALUES (' . $quoted . ', NOW(), "' . $db->real_escape_string($cleaned) . '", "",'
                . ' "' . $db->real_escape_string((string)$row['owner']) . '",'
                . ' "' . $db->real_escape_string((string)$row['user']) . '", "Y",'
                . ' "' . $db->real_escape_string((string)($row['handler'] ?? 'page')) . '",'
                . ' "' . $db->real_escape_string((string)$row['comment_on']) . '")'
            );
            $db->commit();
        } catch (\Throwable $throwable) {
            $db->rollback();

            throw $throwable;
        }
    }
}
