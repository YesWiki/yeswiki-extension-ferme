<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\DbService;

/**
 * Keeps each wiki's numbers as triples of the farm wiki, one triple per value, so
 * the farm can order and filter on them without opening ten thousand databases.
 * A wiki with no triples has never been measured, which is not the same as empty.
 *
 * Folder names are compared exactly. The triples table collates case-insensitively,
 * so two wikis whose folders differ only in case, which a farm really does have,
 * would otherwise share one row and erase each other. The indexed comparison stays
 * in every query and the exact one is added on top, so the index is still used.
 */
class WikiStatsStore
{
    public const RESOURCE_PREFIX = 'ferme:';
    public const PROPERTY_PREFIX = 'http://yeswiki.net/_vocabulary/ferme/stats/';

    public const NUMBERS = ['users', 'forms', 'entries', 'pages', 'lastPageId', 'filesMtime', 'files', 'filesBytes', 'customBytes', 'privateBytes', 'suspect', 'spamWords', 'spamLinks'];
    public const TEXTS = ['lastActivity', 'computedAt', 'checkedAt', 'status', 'error', 'version', 'release', 'suspectWhy', 'spamHosts'];
    public const SERIES = ['activity'];

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    private const ERROR_LENGTH = 255;
    private const EXACT = 'utf8mb4_bin';

    private $db;

    public function __construct(DbService $db)
    {
        $this->db = $db;
    }

    /**
     * Store what `WikiStats` returned, keeping whatever it did not measure. A null
     * value is not written at all, so "never touched" stays apart from "unknown".
     *
     * @param array<string,mixed> $stats
     */
    public function save(string $folder, array $stats): void
    {
        $now = date('Y-m-d H:i:s');
        $values = array_merge($this->read($folder) ?? [], $stats, [
            'computedAt' => $now,
            'checkedAt' => $now,
            'status' => self::STATUS_OK,
            'error' => null,
        ]);

        $this->write($folder, $values);
    }

    /**
     * Record that a wiki could not be measured, without losing the numbers of the
     * last run that could. `checkedAt` moves, `computedAt` does not.
     */
    public function fail(string $folder, string $reason): void
    {
        $values = array_merge($this->read($folder) ?? [], [
            'checkedAt' => date('Y-m-d H:i:s'),
            'status' => self::STATUS_ERROR,
            'error' => mb_substr(trim($reason), 0, self::ERROR_LENGTH),
        ]);

        $this->write($folder, $values);
    }

    /**
     * Say a wiki was looked at without claiming its numbers were made again. One
     * timestamp moves, so this is one UPDATE and not a rewrite of the whole row:
     * a sweep over a farm where nothing changed does this once per wiki.
     */
    public function touch(string $folder): void
    {
        $this->touchMany([$folder]);
    }

    /**
     * The same for a whole sweep, in one statement. Each write costs a commit, so
     * a farm of a few thousand wikis is a second of disk if this is done one wiki
     * at a time, and a few milliseconds if it is done once.
     *
     * @param array<int,string> $folders
     */
    public function touchMany(array $folders): void
    {
        $resources = [];
        foreach (array_unique($folders) as $folder) {
            if (is_string($folder) && FarmConfig::isSafeName($folder, true)) {
                $resources[] = '"' . $this->db->escape(self::RESOURCE_PREFIX . $folder) . '"';
            }
        }
        if (empty($resources)) {
            return;
        }

        $this->db->query(
            'UPDATE ' . $this->db->prefixTable('triples')
            . ' SET value = "' . $this->db->escape(date('Y-m-d H:i:s')) . '"'
            . ' WHERE property = "' . $this->db->escape(self::PROPERTY_PREFIX . 'checkedAt') . '"'
            . ' AND resource IN (' . implode(',', $resources) . ')'
            . ' AND resource COLLATE ' . self::EXACT . ' IN (' . implode(',', $resources) . ')'
        );
    }

    /**
     * @return array<string,mixed>|null null when this wiki has never been measured
     */
    public function read(string $folder): ?array
    {
        $found = $this->readMany([$folder]);

        return $found[$folder] ?? null;
    }

    /**
     * Every stat of the given wikis in one query, for the rows of one page.
     *
     * @param array<int,string> $folders
     *
     * @return array<string,array<string,mixed>> keyed by folder, missing wikis absent
     */
    public function readMany(array $folders): array
    {
        $folders = array_values(array_filter(array_unique($folders), function ($folder) {
            return is_string($folder) && FarmConfig::isSafeName($folder, true);
        }));
        if (empty($folders)) {
            return [];
        }

        $quoted = array_map(function (string $folder) {
            return '"' . $this->db->escape(self::RESOURCE_PREFIX . $folder) . '"';
        }, $folders);

        return $this->hydrate(
            $this->db->loadAll(
                'SELECT resource, property, value FROM ' . $this->db->prefixTable('triples')
                . ' WHERE resource IN (' . implode(',', $quoted) . ')'
                . ' AND property LIKE "' . $this->db->escape(self::PROPERTY_PREFIX) . '%"'
            ),
            $folders
        );
    }

    /**
     * @return array<string,array<string,mixed>> every measured wiki, keyed by folder
     */
    public function readAll(): array
    {
        return $this->hydrate($this->db->loadAll(
            'SELECT resource, property, value FROM ' . $this->db->prefixTable('triples')
            . ' WHERE property LIKE "' . $this->db->escape(self::PROPERTY_PREFIX) . '%"'
        ));
    }

    /**
     * @return array<int,string> the folders that have stats stored
     */
    public function measuredFolders(): array
    {
        $rows = $this->db->loadAll(
            'SELECT DISTINCT resource FROM ' . $this->db->prefixTable('triples')
            . ' WHERE property LIKE "' . $this->db->escape(self::PROPERTY_PREFIX) . '%"'
        );

        return array_map(function (array $row) {
            return substr($row['resource'], strlen(self::RESOURCE_PREFIX));
        }, $rows);
    }

    public function forget(string $folder): void
    {
        if (!FarmConfig::isSafeName($folder, true)) {
            return;
        }

        $resource = $this->db->escape(self::RESOURCE_PREFIX . $folder);

        $this->db->query(
            'DELETE FROM ' . $this->db->prefixTable('triples')
            . ' WHERE resource = "' . $resource . '"'
            . ' AND resource COLLATE ' . self::EXACT . ' = "' . $resource . '"'
            . ' AND property LIKE "' . $this->db->escape(self::PROPERTY_PREFIX) . '%"'
        );
    }

    /**
     * Drop the stats of wikis that are no longer there, for the ones deleted by
     * something other than the farm.
     *
     * @param array<int,string> $liveFolders
     *
     * @return array<int,string> the folders that were forgotten
     */
    public function forgetOrphans(array $liveFolders): array
    {
        $orphans = array_values(array_diff($this->measuredFolders(), $liveFolders));
        foreach ($orphans as $folder) {
            $this->forget($folder);
        }

        return $orphans;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function write(string $folder, array $values): void
    {
        if (!FarmConfig::isSafeName($folder, true)) {
            throw new \InvalidArgumentException(_t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"');
        }

        $rows = [];
        foreach (array_merge(self::NUMBERS, self::TEXTS, self::SERIES) as $property) {
            $stored = $this->encode($property, $values[$property] ?? null);
            if ($stored === null) {
                continue;
            }
            $rows[] = '("' . $this->db->escape(self::RESOURCE_PREFIX . $folder) . '","'
                . $this->db->escape(self::PROPERTY_PREFIX . $property) . '","'
                . $this->db->escape($stored) . '")';
        }

        $this->forget($folder);
        if (empty($rows)) {
            return;
        }

        $this->db->query(
            'INSERT INTO ' . $this->db->prefixTable('triples')
            . ' (resource, property, value) VALUES ' . implode(',', $rows)
        );
    }

    private function encode(string $property, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($property, self::SERIES, true)) {
            return is_array($value) ? implode(',', array_map('intval', $value)) : (string)$value;
        }
        if (in_array($property, self::NUMBERS, true)) {
            return (string)(int)$value;
        }

        return (string)$value;
    }

    /**
     * @param array<int,array<string,string>> $rows
     * @param array<int,string>|null          $asked the folders whose rows are wanted, exactly
     *
     * @return array<string,array<string,mixed>>
     */
    private function hydrate(array $rows, ?array $asked = null): array
    {
        $wanted = $asked === null ? null : array_flip($asked);
        $wikis = [];
        foreach ($rows as $row) {
            $folder = substr($row['resource'], strlen(self::RESOURCE_PREFIX));
            $property = substr($row['property'], strlen(self::PROPERTY_PREFIX));
            if ($folder === '' || $property === '') {
                continue;
            }
            if ($wanted !== null && !isset($wanted[$folder])) {
                continue;
            }
            $wikis[$folder][$property] = $this->decode($property, $row['value']);
        }

        return $wikis;
    }

    private function decode(string $property, string $value)
    {
        if (in_array($property, self::SERIES, true)) {
            return array_map('intval', explode(',', $value));
        }
        if (in_array($property, self::NUMBERS, true)) {
            return (int)$value;
        }

        return $value;
    }
}
