<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Entity\PageType;
use YesWiki\Ferme\Exception\WikiStatsException;

/** What one wiki holds: accounts, forms, entries, pages, a year of activity and the disk its data takes. Reads only. */
class WikiStats
{
    public const MONTHS = 12;
    public const PAGES_READ = 8;
    public const INSTALL_MINUTES = 5;

    public const SPAM_VOCABULARY = '/(casino|jackpot|escort|call.?girl|camgirl|viagra|cialis|sattamatka|porn|\bnude\b|hookup|adultfriend|backlink|payday.?loan|\bbet\b|\bslots?\b|\bpoker\b|\bseo\b|\bessays?\b|\bhomework\b|\bdating\b|keonhacai|nhacai|taixiu|soikeo|bongda|cacuoc|sunwin|togel|judi)/i';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceDatabase $database,
        private readonly WikiPages $pages,
        private readonly SpamFingerprints $fingerprints,
        private readonly SpamApprovals $approvals
    ) {
    }

    /** @return array<string, mixed> */
    public function compute(string $folder): array
    {
        return array_merge($this->fromDatabase($folder), $this->fromDisk($folder));
    }

    /** @return array<string, mixed> */
    public function fromDatabase(string $folder): array
    {
        $wikiConfig = $this->wikiConfig($folder);

        try {
            $db = $this->database->connect($wikiConfig, $this->config->wikiDir($folder));
            $pages = InstanceDatabase::table($db, InstanceDatabase::prefix($wikiConfig), 'pages');
            $counts = $this->counts($db, $pages);

            return array_merge(
                [
                    'version' => (string)($wikiConfig['yeswiki_version'] ?? ''),
                    'release' => (string)($wikiConfig['yeswiki_release'] ?? ''),
                    'name' => (string)($wikiConfig['yeswiki_name'] ?? $wikiConfig['wakka_name'] ?? ''),
                    'description' => mb_substr((string)($wikiConfig['meta_description'] ?? ''), 0, 300),
                    'users' => $counts[PageType::USER] ?? 0,
                    'forms' => $counts[PageType::FORM] ?? 0,
                    'entries' => $counts[PageType::ENTRY] ?? 0,
                    'pages' => $counts[PageType::PAGE] ?? 0,
                ],
                $this->latest($db, $pages),
                $this->adminPresence($db, $pages),
                ['activity' => $this->activity($db, $pages)],
                $this->spamInPages($folder)
            );
        } catch (WikiStatsException $failed) {
            throw $failed;
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
    }

    /** @return array{files: int, filesBytes: int, customBytes: int, privateBytes: int} */
    public function fromDisk(string $folder): array
    {
        $root = $this->config->wikiDir($folder);
        if (!is_dir($root)) {
            throw new WikiStatsException($folder, _t('FERME_STATS_NO_WIKI_FOLDER') . ' ' . $root);
        }

        $legacy = $this->walk($root . '/files');
        $uploads = $this->walk($root . '/private/files');
        $private = $this->walk($root . '/private');

        return [
            'files' => $legacy['count'] + $uploads['count'],
            'filesBytes' => $legacy['bytes'] + $uploads['bytes'],
            'customBytes' => $this->walk($root . '/custom')['bytes'],
            'privateBytes' => max(0, $private['bytes'] - $uploads['bytes']),
        ];
    }

    /**
     * Edits day by day over the last year, for the calendar of one wiki.
     *
     * @return array<string, int>
     */
    public function daily(string $folder, int $days = 371): array
    {
        $wikiConfig = $this->wikiConfig($folder);

        try {
            $db = $this->database->connect($wikiConfig, $this->config->wikiDir($folder));
            $pages = InstanceDatabase::table($db, InstanceDatabase::prefix($wikiConfig), 'pages');
            $day = $this->dateExpression($db, 'day');
            $time = InstanceDatabase::quote($db, 'time');
            $edits = [];
            foreach (InstanceDatabase::rows($db, "SELECT {$day} AS day, COUNT(*) AS edits FROM {$pages} WHERE {$time} >= ? GROUP BY {$day}", [date('Y-m-d 00:00:00', (int)strtotime('-' . max(1, $days) . ' days'))]) as $row) {
                $edits[(string)$row['day']] = (int)$row['edits'];
            }

            return $edits;
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
    }

    /** The id of the last revision written, null when the wiki cannot be read. */
    public function probe(string $folder): ?int
    {
        try {
            $wikiConfig = $this->wikiConfig($folder);
            $db = $this->database->connect($wikiConfig, $this->config->wikiDir($folder));

            return (int)InstanceDatabase::scalar($db, 'SELECT MAX(id) FROM ' . InstanceDatabase::table($db, InstanceDatabase::prefix($wikiConfig), 'pages'));
        } catch (\Throwable $throwable) {
            return null;
        }
    }

    /** The same question for the disk: adding an upload moves the folder's mtime. */
    public function diskProbe(string $folder): ?int
    {
        try {
            $root = $this->config->wikiDir($folder);
        } catch (\Throwable $throwable) {
            return null;
        }
        $times = [];
        foreach (['/files', '/private/files'] as $sub) {
            if (is_dir($root . $sub)) {
                $times[] = (int)filemtime($root . $sub);
            }
        }

        return $times === [] ? null : max($times);
    }

    public function close(): void
    {
        $this->database->close();
    }

    /**
     * Whether the farm's super admin has an account in the wiki, when the farm has one.
     *
     * @return array<string, string>
     */
    private function adminPresence(\PDO $db, string $pages): array
    {
        $name = $this->config->text('yeswiki-farm-admin-name');
        if ($name === '') {
            return [];
        }
        $type = InstanceDatabase::quote($db, 'type');
        $present = (int)InstanceDatabase::scalar($db, "SELECT COUNT(*) FROM {$pages} WHERE latest = 'Y' AND {$type} = ? AND tag = ?", [PageType::USER, $name]) > 0;

        return ['adminPresent' => $present ? 'yes' : 'no'];
    }

    /** @return array<string, int> */
    private function counts(\PDO $db, string $pages): array
    {
        $type = InstanceDatabase::quote($db, 'type');
        $counts = [];
        foreach (InstanceDatabase::rows($db, "SELECT {$type} AS kind, COUNT(*) AS total FROM {$pages} WHERE latest = 'Y' GROUP BY {$type}") as $row) {
            $counts[(string)$row['kind']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * When the wiki was installed, when it was last written to, and what somebody wrote by hand since: an install writes every page within the same few minutes.
     *
     * @return array{lastPageId: int, lastActivity: ?string, firstActivity: ?string, editedPages: int, lastEdit: ?string}
     */
    private function latest(\PDO $db, string $pages): array
    {
        $time = InstanceDatabase::quote($db, 'time');
        $user = InstanceDatabase::quote($db, 'user');
        $row = InstanceDatabase::rows($db, "SELECT MAX(id) AS id, MAX({$time}) AS last, MIN({$time}) AS first FROM {$pages}")[0] ?? [];
        $first = isset($row['first']) ? (string)$row['first'] : null;
        $settled = $first === null ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', (int)strtotime($first) + self::INSTALL_MINUTES * 60);
        $edited = InstanceDatabase::rows($db, "SELECT COUNT(DISTINCT tag) AS edited, MAX({$time}) AS last FROM {$pages} WHERE {$user} <> '' AND {$time} > ?", [$settled])[0] ?? [];

        return [
            'lastPageId' => (int)($row['id'] ?? 0),
            'lastActivity' => isset($row['last']) ? (string)$row['last'] : null,
            'firstActivity' => $first,
            'editedPages' => (int)($edited['edited'] ?? 0),
            'lastEdit' => isset($edited['last']) ? (string)$edited['last'] : null,
        ];
    }

    /**
     * Edits per month over the last twelve, oldest first.
     *
     * @return list<int>
     */
    private function activity(\PDO $db, string $pages): array
    {
        $months = [];
        $firstOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $months[$firstOfMonth->modify('-' . $back . ' month')->format('Y-m')] = 0;
        }
        $month = $this->dateExpression($db, 'month');
        $time = InstanceDatabase::quote($db, 'time');
        foreach (InstanceDatabase::rows($db, "SELECT {$month} AS month, COUNT(*) AS edits FROM {$pages} WHERE {$time} >= ? GROUP BY {$month}", [array_key_first($months) . '-01 00:00:00']) as $row) {
            if (array_key_exists((string)$row['month'], $months)) {
                $months[(string)$row['month']] = (int)$row['edits'];
            }
        }

        return array_values($months);
    }

    /** @return array{spamWords: int, spamLinks: int, spamPages: int, spamHosts: string} */
    private function spamInPages(string $folder): array
    {
        $words = 0;
        $links = 0;
        $dirty = 0;
        $hosts = [];
        $known = $this->config->text('yeswiki-farm-spam-hosts');

        foreach ($this->pages->current($folder, self::PAGES_READ) as $page) {
            $body = $page['content'];
            $itsWords = (int)preg_match_all(self::SPAM_VOCABULARY, $body);
            preg_match_all('#https?://([a-z0-9.\-]+)#i', $body, $found);
            $itsLinks = count($found[1]);
            $words += $itsWords;
            $links += $itsLinks;
            foreach (array_map('strtolower', $found[1]) as $host) {
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
            }
            if (SpamCleaner::isSpamPage($body, $itsWords, $itsLinks, $known, $this->fingerprints->isCampaignPage($body))
                && !$this->approvals->isApproved($folder, $page['tag'], $body)) {
                $dirty++;
            }
        }
        arsort($hosts);

        return [
            'spamWords' => $words,
            'spamLinks' => $links,
            'spamPages' => $dirty,
            'spamHosts' => implode(' ', array_slice(array_keys($hosts), 0, 3)),
        ];
    }

    private function dateExpression(\PDO $db, string $grain): string
    {
        $time = InstanceDatabase::quote($db, 'time');

        return match ((string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'pgsql' => $grain === 'day' ? "TO_CHAR({$time}, 'YYYY-MM-DD')" : "TO_CHAR({$time}, 'YYYY-MM')",
            'sqlite' => $grain === 'day' ? "SUBSTR({$time}, 1, 10)" : "SUBSTR({$time}, 1, 7)",
            default => $grain === 'day' ? "DATE_FORMAT({$time}, '%Y-%m-%d')" : "DATE_FORMAT({$time}, '%Y-%m')",
        };
    }

    /** @return array{count: int, bytes: int} */
    private function walk(string $path): array
    {
        if (is_link($path) || !is_dir($path)) {
            return ['count' => 0, 'bytes' => 0];
        }

        $count = 0;
        $bytes = 0;
        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($tree as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }
            $count++;
            $bytes += (int)$file->getSize();
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /** @return array<string, mixed> */
    private function wikiConfig(string $folder): array
    {
        try {
            $wikiConfig = $this->config->readWikiConfig($folder);
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
        if ($wikiConfig === [] || empty($wikiConfig['base_url'])) {
            throw new WikiStatsException($folder, _t('FERME_STATS_NO_CONFIG'));
        }

        return $wikiConfig;
    }
}
