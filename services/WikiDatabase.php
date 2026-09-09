<?php

namespace YesWiki\Ferme\Service;

/**
 * Talk to the database of another wiki, with that wiki's own credentials.
 *
 * The farm used to switch database with USE on its own connection, which only
 * works when every wiki sits on the same server and the farm's MySQL user has
 * rights on it. Opening a connection per wiki has neither constraint.
 */
class WikiDatabase
{
    public const WIKI_TABLES = ['acls', 'links', 'nature', 'pages', 'referrers', 'triples', 'users'];

    public function connect(array $wakkaConfig): \mysqli
    {
        foreach (['mysql_host', 'mysql_user', 'mysql_password', 'mysql_database'] as $key) {
            if (!array_key_exists($key, $wakkaConfig)) {
                throw new \RuntimeException(_t('FERME_CLI_MISSING_CONFIG_KEY') . ' ' . $key);
            }
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $host = $wakkaConfig['mysql_host'];
        $port = null;
        if (str_contains($host, ':') && !str_starts_with($host, '/')) {
            [$host, $port] = explode(':', $host, 2);
            $port = (int)$port;
        }

        $db = new \mysqli($host, $wakkaConfig['mysql_user'], $wakkaConfig['mysql_password'], $wakkaConfig['mysql_database'], $port);
        $db->set_charset($wakkaConfig['db_charset'] ?? 'utf8mb4');

        return $db;
    }

    /**
     * Which of the tables a YesWiki needs are missing.
     *
     * @return array<int,string>
     */
    public function missingTables(\mysqli $db, string $tablePrefix): array
    {
        // one SHOW TABLES and a diff: MariaDB refuses a placeholder in SHOW TABLES LIKE ?
        $existing = [];
        $result = $db->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $existing[] = $row[0];
        }
        $result->free();

        $missing = [];
        foreach (self::WIKI_TABLES as $table) {
            if (!in_array($tablePrefix . $table, $existing, true)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Members of a wiki group, in the order the triple lists them.
     *
     * @return array<int,string>
     */
    public function groupMembers(\mysqli $db, string $tablePrefix, string $group = ADMIN_GROUP): array
    {
        $resource = GROUP_PREFIX . $group;
        $property = WIKINI_VOC_ACLS_URI;

        $statement = $db->prepare('SELECT value FROM `' . $this->table($tablePrefix, 'triples') . '` WHERE resource = ? AND property = ? LIMIT 1');
        $statement->bind_param('ss', $resource, $property);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        if ($row === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n]+/', (string)$row['value'])),
            function ($member) {
                return $member !== '';
            }
        )));
    }

    /**
     * Email of the first admin of a wiki that has one, so an imported farm entry
     * reaches whoever runs that wiki rather than the farm owner.
     */
    public function firstAdminEmail(\mysqli $db, string $tablePrefix): ?string
    {
        foreach ($this->groupMembers($db, $tablePrefix) as $member) {
            $statement = $db->prepare('SELECT email FROM `' . $this->table($tablePrefix, 'users') . '` WHERE name = ? LIMIT 1');
            $statement->bind_param('s', $member);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();

            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    public function table(string $tablePrefix, string $name): string
    {
        return str_replace('`', '', $tablePrefix . $name);
    }
}
