<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\TripleStore;
use YesWiki\Ferme\Exception\WikiStatsException;

/**
 * What one wiki holds: accounts, forms, entries, pages, twelve months of activity
 * and the disk its data takes. Reads only, and storing what comes out is not its
 * job. A symlinked data folder is left out rather than counted in every wiki
 * sharing it.
 */
class WikiStats
{
    public const MONTHS = 12;

    private $config;
    private $database;
    private $connections = [];

    public function __construct(FarmConfig $config, WikiDatabase $database)
    {
        $this->config = $config;
        $this->database = $database;
    }

    /**
     * @return array{users:int,forms:int,entries:int,pages:int,lastPageId:int,lastActivity:?string,activity:array<int,int>,files:int,filesBytes:int,customBytes:int,privateBytes:int}
     */
    public function compute(string $folder): array
    {
        return array_merge($this->fromDatabase($folder), $this->fromDisk($folder));
    }

    /**
     * @return array{users:int,forms:int,entries:int,pages:int,lastPageId:int,lastActivity:?string,activity:array<int,int>,version:string,release:string}
     */
    public function fromDatabase(string $folder): array
    {
        $wakkaConfig = $this->wikiConfig($folder);
        $prefix = (string)$wakkaConfig['table_prefix'];
        $db = $this->connect($folder, $wakkaConfig);

        try {
            return array_merge(
                [
                    'version' => (string)($wakkaConfig['yeswiki_version'] ?? ''),
                    'release' => (string)($wakkaConfig['yeswiki_release'] ?? ''),
                    'users' => $this->countRows($db, $prefix, 'users'),
                    'forms' => $this->countRows($db, $prefix, 'nature'),
                    'entries' => $this->countEntries($db, $prefix),
                    'pages' => $this->countPages($db, $prefix),
                ],
                $this->latest($db, $prefix),
                ['activity' => $this->activity($db, $prefix)]
            );
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
    }

    /**
     * @return array{files:int,filesBytes:int,customBytes:int,privateBytes:int}
     */
    public function fromDisk(string $folder): array
    {
        $root = $this->wikiDir($folder);
        if (!is_dir($root)) {
            throw new WikiStatsException($folder, _t('FERME_STATS_NO_WIKI_FOLDER') . ' ' . $root);
        }

        $files = $this->walk($root . 'files');

        return [
            'files' => $files['count'],
            'filesBytes' => $files['bytes'],
            'customBytes' => $this->walk($root . 'custom')['bytes'],
            'privateBytes' => $this->walk($root . 'private')['bytes'],
        ];
    }

    /**
     * Edits day by day over the last year, for the calendar of one wiki. Computed
     * on the spot and stored nowhere: 365 numbers a wiki would weigh more than
     * everything else put together, and nobody looks at two calendars at once.
     *
     * @return array<string,int> keyed by Y-m-d, days without an edit absent
     */
    public function daily(string $folder, int $days = 371): array
    {
        $wakkaConfig = $this->wikiConfig($folder);
        $prefix = (string)$wakkaConfig['table_prefix'];
        $db = $this->connect($folder, $wakkaConfig);
        $since = date('Y-m-d 00:00:00', strtotime('-' . max(1, $days) . ' days'));

        try {
            $statement = $db->prepare(
                'SELECT DATE(time) AS day, COUNT(*) AS edits FROM `'
                . $this->database->table($prefix, 'pages') . '` WHERE time >= ? GROUP BY day'
            );
            $statement->bind_param('s', $since);
            $statement->execute();
            $result = $statement->get_result();

            $edits = [];
            while ($row = $result->fetch_assoc()) {
                $edits[(string)$row['day']] = (int)$row['edits'];
            }
            $statement->close();

            return $edits;
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
    }

    /**
     * The id of the last revision written, to compare with the one a previous run
     * stored: equal means nothing has happened and the counting can be skipped.
     *
     * @return int|null null when the wiki cannot be read at all
     */
    public function probe(string $folder): ?int
    {
        try {
            $wakkaConfig = $this->wikiConfig($folder);
            $db = $this->connect($folder, $wakkaConfig);
            $table = $this->database->table((string)$wakkaConfig['table_prefix'], 'pages');
            $row = $db->query('SELECT MAX(id) FROM `' . $table . '`')->fetch_row();

            return (int)($row[0] ?? 0);
        } catch (\Throwable $throwable) {
            return null;
        }
    }

    /**
     * The same question for the disk: uploads are flat in files/, so adding or
     * removing one moves its mtime.
     */
    public function diskProbe(string $folder): ?int
    {
        try {
            $mtime = @filemtime($this->wikiDir($folder) . 'files');
        } catch (\Throwable $throwable) {
            return null;
        }

        return $mtime === false ? null : $mtime;
    }

    public function close(): void
    {
        foreach ($this->connections as $db) {
            $db->close();
        }
        $this->connections = [];
    }

    private function countRows(\mysqli $db, string $prefix, string $table): int
    {
        $row = $db->query('SELECT COUNT(*) FROM `' . $this->database->table($prefix, $table) . '`')->fetch_row();

        return (int)($row[0] ?? 0);
    }

    private function countEntries(\mysqli $db, string $prefix): int
    {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM `' . $this->database->table($prefix, 'triples') . '` WHERE property = ? AND value = ?'
        );
        $type = TripleStore::TYPE_URI;
        $value = 'fiche_bazar';
        $statement->bind_param('ss', $type, $value);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
        $statement->close();

        return (int)($row[0] ?? 0);
    }

    private function countPages(\mysqli $db, string $prefix): int
    {
        $row = $db->query(
            'SELECT COUNT(*) FROM `' . $this->database->table($prefix, 'pages') . '` WHERE latest = \'Y\' AND comment_on = \'\''
        )->fetch_row();

        return (int)($row[0] ?? 0);
    }

    /**
     * @return array{lastPageId:int,lastActivity:?string}
     */
    private function latest(\mysqli $db, string $prefix): array
    {
        $row = $db->query(
            'SELECT MAX(id) AS id, MAX(time) AS time FROM `' . $this->database->table($prefix, 'pages') . '`'
        )->fetch_assoc();

        return [
            'lastPageId' => (int)($row['id'] ?? 0),
            'lastActivity' => $row['time'] ?? null,
        ];
    }

    /**
     * Edits per month over the last twelve, oldest first, counting every revision
     * and not just the current ones.
     *
     * @return array<int,int>
     */
    private function activity(\mysqli $db, string $prefix): array
    {
        $months = [];
        $firstOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $months[$firstOfMonth->modify('-' . $back . ' month')->format('Y-m')] = 0;
        }
        $since = array_key_first($months) . '-01 00:00:00';

        $statement = $db->prepare(
            'SELECT DATE_FORMAT(time, \'%Y-%m\') AS month, COUNT(*) AS edits FROM `'
            . $this->database->table($prefix, 'pages') . '` WHERE time >= ? GROUP BY month'
        );
        $statement->bind_param('s', $since);
        $statement->execute();
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            if (array_key_exists((string)$row['month'], $months)) {
                $months[(string)$row['month']] = (int)$row['edits'];
            }
        }
        $statement->close();

        return array_values($months);
    }

    /**
     * @return array{count:int,bytes:int}
     */
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
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $count++;
            $bytes += (int)$file->getSize();
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    private function wikiConfig(string $folder): array
    {
        try {
            $wakkaConfig = $this->config->readWikiConfig($folder);
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }

        if (empty($wakkaConfig['table_prefix'])) {
            throw new WikiStatsException($folder, _t('FERME_STATS_NO_CONFIG'));
        }

        return $wakkaConfig;
    }

    private function wikiDir(string $folder): string
    {
        $dir = $this->config->wikiDir($folder);

        return str_ends_with($dir, DIRECTORY_SEPARATOR) ? $dir : $dir . DIRECTORY_SEPARATOR;
    }

    /**
     * One connection per host, user and database, so a farm whose wikis share a
     * database opens a single one for the whole sweep.
     */
    private function connect(string $folder, array $wakkaConfig): \mysqli
    {
        $key = ($wakkaConfig['mysql_host'] ?? '') . '|'
            . ($wakkaConfig['mysql_user'] ?? '') . '|'
            . ($wakkaConfig['mysql_database'] ?? '');

        if (!isset($this->connections[$key])) {
            try {
                $this->connections[$key] = $this->database->connect($wakkaConfig);
            } catch (\Throwable $throwable) {
                throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
            }
        }

        return $this->connections[$key];
    }
}
