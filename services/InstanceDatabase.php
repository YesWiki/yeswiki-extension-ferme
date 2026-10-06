<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Admin\Service\DatabaseCopier;
use YesWiki\Admin\Service\InstallationService;
use YesWiki\Kernel\Database\DumpRewriter;

/** Reads another wiki's database with that wiki's own credentials, through core's connection builder. */
class InstanceDatabase
{
    /** @var array<string, \PDO> */
    private array $connections = [];

    /**
     * @param array<string, mixed> $config the other wiki's configuration
     */
    public function connect(array $config, string $wikiDir): \PDO
    {
        $config = InstallationService::mapLegacyKeys($config);
        $driver = self::text($config, 'db_driver', 'mysql');
        $database = self::text($config, 'db_database');
        if ($driver === 'sqlite') {
            $database = $database !== '' ? $database : 'private/yeswiki.db';
            $database = str_starts_with($database, '/') ? $database : rtrim($wikiDir, '/') . '/' . $database;
        }
        $host = self::text($config, 'db_host', 'localhost');
        $port = self::text($config, 'db_port');
        if ($port === '' && str_contains($host, ':') && !str_starts_with($host, '/')) {
            [$host, $port] = explode(':', $host, 2);
        }
        $key = implode('|', [$driver, $host, $port, $database, self::text($config, 'db_user')]);
        if (!isset($this->connections[$key])) {
            $this->connections[$key] = DatabaseCopier::connect($driver, $host, $port, $database, self::text($config, 'db_user'), self::text($config, 'db_password'));
            $this->connections[$key]->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        }

        return $this->connections[$key];
    }

    public function close(): void
    {
        $this->connections = [];
    }

    /** @param array<string, mixed> $config */
    public static function prefix(array $config): string
    {
        return self::text($config, 'table_prefix', 'yeswiki_');
    }

    public static function quote(\PDO $db, string $identifier): string
    {
        return $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** The prefixed, quoted name of one of the wiki's tables. */
    public static function table(\PDO $db, string $prefix, string $name): string
    {
        return self::quote($db, $prefix . $name);
    }

    /**
     * Every row a query returns, with bound values.
     *
     * @param list<scalar|null> $values
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(\PDO $db, string $sql, array $values = []): array
    {
        $statement = $db->prepare($sql);
        $statement->execute($values);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param list<scalar|null> $values */
    public static function scalar(\PDO $db, string $sql, array $values = []): mixed
    {
        $statement = $db->prepare($sql);
        $statement->execute($values);

        return $statement->fetchColumn();
    }

    /**
     * The tables this wiki has under its prefix, another wiki's sharing the database left out.
     *
     * @return list<string>
     */
    public function ownTables(\PDO $db, string $prefix): array
    {
        $sql = match ((string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            'pgsql' => 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
            default => 'SHOW TABLES',
        };
        $statement = $db->query($sql);
        $names = $statement === false ? [] : array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));

        return DumpRewriter::ownTables($names, $prefix);
    }

    /**
     * Drop a wiki's tables from a database it shares with other wikis, which is all a farm without a database administrator can do.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string> the tables dropped
     */
    public function dropOwnTables(array $config, string $wikiDir): array
    {
        $prefix = self::prefix($config);
        if ($prefix === '') {
            return [];
        }
        $db = $this->connect($config, $wikiDir);
        $dropped = [];
        foreach ($this->ownTables($db, $prefix) as $table) {
            $db->exec('DROP TABLE IF EXISTS ' . self::quote($db, $table));
            $dropped[] = $table;
        }

        return $dropped;
    }

    /** @param array<string, mixed> $config */
    private static function text(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : $default;
    }
}
