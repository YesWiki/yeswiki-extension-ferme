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
     * The triple that holds a group's members, or null when the group has none yet.
     */
    public function groupTriple(\mysqli $db, string $tablePrefix, string $group): ?array
    {
        $resource = GROUP_PREFIX . $group;
        $property = WIKINI_VOC_ACLS_URI;

        $statement = $db->prepare('SELECT id, value FROM `' . $this->table($tablePrefix, 'triples') . '` WHERE resource = ? AND property = ? LIMIT 1');
        $statement->bind_param('ss', $resource, $property);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return $row ?: null;
    }

    /**
     * Members of a wiki group, in the order the triple lists them.
     *
     * @return array<int,string>
     */
    public function groupMembers(\mysqli $db, string $tablePrefix, string $group = ADMIN_GROUP): array
    {
        $triple = $this->groupTriple($db, $tablePrefix, $group);
        if ($triple === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n]+/', (string)$triple['value'])),
            function ($member) {
                return $member !== '';
            }
        )));
    }

    /**
     * Write a group's member list, the way YesWiki stores it: one name per line
     * on the ThisWikiGroup:<group> triple.
     */
    public function saveGroupMembers(\mysqli $db, string $tablePrefix, string $group, array $members): void
    {
        $resource = GROUP_PREFIX . $group;
        $property = WIKINI_VOC_ACLS_URI;
        $value = implode("\n", $members);
        $table = $this->table($tablePrefix, 'triples');

        $triple = $this->groupTriple($db, $tablePrefix, $group);
        if ($triple === null) {
            $statement = $db->prepare('INSERT INTO `' . $table . '` (resource, property, value) VALUES (?, ?, ?)');
            $statement->bind_param('sss', $resource, $property, $value);
        } else {
            $statement = $db->prepare('UPDATE `' . $table . '` SET value = ? WHERE id = ?');
            $statement->bind_param('si', $value, $triple['id']);
        }
        $statement->execute();
        $statement->close();
    }

    public function findUser(\mysqli $db, string $tablePrefix, string $name): ?array
    {
        $statement = $db->prepare('SELECT name, email FROM `' . $this->table($tablePrefix, 'users') . '` WHERE name = ? LIMIT 1');
        $statement->bind_param('s', $name);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return $row ?: null;
    }

    /**
     * Hash a password the way this particular wiki expects it.
     *
     * YesWiki widened users.password in migration 20240425 to hold a bcrypt hash;
     * before that the column only fits an md5, so a wiki that has not migrated yet
     * gets md5 or it could never log the user in.
     */
    public function passwordHash(\mysqli $db, string $tablePrefix, string $password): string
    {
        $result = $db->query('SHOW COLUMNS FROM `' . $this->table($tablePrefix, 'users') . "` LIKE 'password'");
        $column = $result === false ? null : $result->fetch_assoc();
        if ($column === null) {
            throw new \RuntimeException(_t('FERME_CLI_NO_PASSWORD_COLUMN') . ' ' . $this->table($tablePrefix, 'users'));
        }

        preg_match('/\((\d+)\)/', $column['Type'], $matches);
        $width = (int)($matches[1] ?? 0);

        return $width >= 60 ? password_hash($password, PASSWORD_BCRYPT) : md5($password);
    }

    public function insertUser(\mysqli $db, string $tablePrefix, string $name, string $hash, string $email): void
    {
        $statement = $db->prepare(
            'INSERT INTO `' . $this->table($tablePrefix, 'users') . "` (name, password, email, motto, signuptime) VALUES (?, ?, ?, '', NOW())"
        );
        $statement->bind_param('sss', $name, $hash, $email);
        $statement->execute();
        $statement->close();
    }

    public function updateUser(\mysqli $db, string $tablePrefix, string $name, string $hash, string $email): void
    {
        $statement = $db->prepare('UPDATE `' . $this->table($tablePrefix, 'users') . '` SET password = ?, email = ? WHERE name = ?');
        $statement->bind_param('sss', $hash, $email, $name);
        $statement->execute();
        $statement->close();
    }

    public function deleteUser(\mysqli $db, string $tablePrefix, string $name): void
    {
        $statement = $db->prepare('DELETE FROM `' . $this->table($tablePrefix, 'users') . '` WHERE name = ?');
        $statement->bind_param('s', $name);
        $statement->execute();
        $statement->close();
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
