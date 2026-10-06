<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Kernel\Service\DbService;

/** Keeps each wiki's numbers as triples of the farm wiki, so the farm can sort and filter without opening every database. */
class WikiStatsStore
{
    public const RESOURCE_PREFIX = 'ferme:';
    public const PROPERTY_PREFIX = 'http://yeswiki.net/_vocabulary/ferme/stats/';

    public const NUMBERS = ['users', 'forms', 'entries', 'pages', 'lastPageId', 'filesMtime', 'files', 'filesBytes', 'customBytes', 'privateBytes', 'suspect', 'spamWords', 'spamLinks', 'spamPages', 'editedPages'];
    public const TEXTS = ['lastActivity', 'firstActivity', 'lastEdit', 'computedAt', 'checkedAt', 'status', 'error', 'version', 'release', 'suspectWhy', 'spamHosts', 'spamApproved', 'adminPresent'];
    public const SERIES = ['activity'];

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    private const ERROR_LENGTH = 255;

    public function __construct(private readonly DbService $db)
    {
    }

    /**
     * Store what WikiStats measured, keeping whatever it did not measure.
     *
     * @param array<string, mixed> $stats
     */
    public function save(string $folder, array $stats): void
    {
        $now = date('Y-m-d H:i:s');
        $this->write($folder, array_merge($this->read($folder) ?? [], $stats, [
            'computedAt' => $now,
            'checkedAt' => $now,
            'status' => self::STATUS_OK,
            'error' => null,
        ]));
    }

    /** Record that a wiki could not be measured, keeping the numbers of the last run that could. */
    public function fail(string $folder, string $reason): void
    {
        $this->write($folder, array_merge($this->read($folder) ?? [], [
            'checkedAt' => date('Y-m-d H:i:s'),
            'status' => self::STATUS_ERROR,
            'error' => mb_substr(trim($reason), 0, self::ERROR_LENGTH),
        ]));
    }

    /**
     * Keep a value somebody decided, next to the numbers, without claiming the wiki was counted.
     *
     * @param array<string, mixed> $values
     */
    public function keep(string $folder, array $values): void
    {
        $this->write($folder, array_merge($this->read($folder) ?? [], $values));
    }

    public function touch(string $folder): void
    {
        $this->touchMany([$folder]);
    }

    /**
     * Say several wikis were looked at, in one statement.
     *
     * @param list<string> $folders
     */
    public function touchMany(array $folders): void
    {
        $resources = [];
        foreach (array_unique($folders) as $folder) {
            if (FarmConfig::isSafeName($folder, true)) {
                $resources[] = self::RESOURCE_PREFIX . $folder;
            }
        }
        if ($resources === []) {
            return;
        }

        $exact = array_flip($resources);
        $ids = [];
        foreach ($this->db->loadAll('SELECT id, resource FROM ' . $this->db->prefixTable('triples') . ' WHERE property = ? AND resource IN (' . implode(',', array_fill(0, count($resources), '?')) . ')', [self::PROPERTY_PREFIX . 'checkedAt', ...$resources]) as $row) {
            if (isset($exact[(string)$row['resource']])) {
                $ids[] = (int)$row['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $this->db->query('UPDATE ' . $this->db->prefixTable('triples') . ' SET value = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [date('Y-m-d H:i:s'), ...$ids]);
    }

    /** @return array<string, mixed>|null null when this wiki has never been measured */
    public function read(string $folder): ?array
    {
        return $this->readMany([$folder])[$folder] ?? null;
    }

    /**
     * @param list<string> $folders
     *
     * @return array<string, array<string, mixed>>
     */
    public function readMany(array $folders): array
    {
        $folders = array_values(array_filter(array_unique($folders), static fn (string $folder): bool => FarmConfig::isSafeName($folder, true)));
        if ($folders === []) {
            return [];
        }
        $resources = array_map(static fn (string $folder): string => self::RESOURCE_PREFIX . $folder, $folders);

        return $this->hydrate($this->db->loadAll(
            'SELECT resource, property, value FROM ' . $this->db->prefixTable('triples')
            . ' WHERE resource IN (' . implode(',', array_fill(0, count($resources), '?')) . ') AND property LIKE ?',
            [...$resources, self::PROPERTY_PREFIX . '%']
        ), $folders);
    }

    /** @return array<string, array<string, mixed>> */
    public function readAll(): array
    {
        return $this->hydrate($this->db->loadAll(
            'SELECT resource, property, value FROM ' . $this->db->prefixTable('triples') . ' WHERE property LIKE ?',
            [self::PROPERTY_PREFIX . '%']
        ));
    }

    /** @return list<string> */
    public function measuredFolders(): array
    {
        $folders = [];
        foreach ($this->db->loadAll('SELECT DISTINCT resource FROM ' . $this->db->prefixTable('triples') . ' WHERE property LIKE ?', [self::PROPERTY_PREFIX . '%']) as $row) {
            $folders[] = substr((string)$row['resource'], strlen(self::RESOURCE_PREFIX));
        }

        return $folders;
    }

    /** Forget a wiki's numbers, and nobody else's: the comparison is made exactly, whatever the table's collation. */
    public function forget(string $folder): void
    {
        if (!FarmConfig::isSafeName($folder, true)) {
            return;
        }
        $resource = self::RESOURCE_PREFIX . $folder;
        $ids = [];
        foreach ($this->db->loadAll('SELECT id, resource FROM ' . $this->db->prefixTable('triples') . ' WHERE resource = ? AND property LIKE ?', [$resource, self::PROPERTY_PREFIX . '%']) as $row) {
            if ((string)$row['resource'] === $resource) {
                $ids[] = (int)$row['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $this->db->query('DELETE FROM ' . $this->db->prefixTable('triples') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /**
     * Drop the numbers of wikis that are gone.
     *
     * @param list<string> $liveFolders
     *
     * @return list<string>
     */
    public function forgetOrphans(array $liveFolders): array
    {
        $orphans = array_values(array_diff($this->measuredFolders(), $liveFolders));
        foreach ($orphans as $folder) {
            $this->forget($folder);
        }

        return $orphans;
    }

    /** @param array<string, mixed> $values */
    private function write(string $folder, array $values): void
    {
        if (!FarmConfig::isSafeName($folder, true)) {
            throw new \InvalidArgumentException(_t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"');
        }

        $rows = [];
        $params = [];
        foreach (array_merge(self::NUMBERS, self::TEXTS, self::SERIES) as $property) {
            $stored = $this->encode($property, $values[$property] ?? null);
            if ($stored === null) {
                continue;
            }
            $rows[] = '(?, ?, ?)';
            array_push($params, self::RESOURCE_PREFIX . $folder, self::PROPERTY_PREFIX . $property, $stored);
        }

        $this->db->transactional(function () use ($folder, $rows, $params): void {
            $this->forget($folder);
            if ($rows !== []) {
                $this->db->query('INSERT INTO ' . $this->db->prefixTable('triples') . ' (resource, property, value) VALUES ' . implode(',', $rows), $params);
            }
        });
    }

    private function encode(string $property, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($property, self::SERIES, true)) {
            return is_array($value) ? implode(',', array_map('intval', $value)) : (string)(is_scalar($value) ? $value : '');
        }
        if (in_array($property, self::NUMBERS, true)) {
            return (string)(int)(is_scalar($value) ? $value : 0);
        }

        return is_scalar($value) ? (string)$value : (string)json_encode($value);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null          $asked the folders wanted, compared exactly
     *
     * @return array<string, array<string, mixed>>
     */
    private function hydrate(array $rows, ?array $asked = null): array
    {
        $wanted = $asked === null ? null : array_flip($asked);
        $wikis = [];
        foreach ($rows as $row) {
            $folder = substr((string)$row['resource'], strlen(self::RESOURCE_PREFIX));
            $property = substr((string)$row['property'], strlen(self::PROPERTY_PREFIX));
            if ($folder === '' || $property === '' || ($wanted !== null && !isset($wanted[$folder]))) {
                continue;
            }
            $wikis[$folder][$property] = $this->decode($property, (string)$row['value']);
        }

        return $wikis;
    }

    private function decode(string $property, string $value): mixed
    {
        if (in_array($property, self::SERIES, true)) {
            return array_map('intval', explode(',', $value));
        }

        return in_array($property, self::NUMBERS, true) ? (int)$value : $value;
    }
}
