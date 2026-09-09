<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Create, update or remove a user in another wiki and set its group membership.
 *
 * The farm's own super-admin buttons and the ferme:admin command both come
 * through here, so a password is hashed the same way whichever one you use.
 */
class FarmAdminAccount
{
    protected $wiki;
    protected $config;
    protected $editor;
    protected $database;

    public function __construct(Wiki $wiki, FarmConfig $config, WikiConfigEditor $editor, WikiDatabase $database)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->editor = $editor;
        $this->database = $database;
    }

    /**
     * The AdminWikis button: put the configured farm super admin in a wiki.
     */
    public function add(string $folder): array
    {
        $name = (string)($this->wiki->config['yeswiki-farm-admin-name'] ?? '');
        $password = (string)($this->wiki->config['yeswiki-farm-admin-pass'] ?? '');
        if ($name === '' || $password === '') {
            return ['errors' => [_t('FERME_NO_FARM_ADMIN_CONFIGURED')]];
        }

        try {
            $result = $this->ensureUser($this->config->wikiDir($folder), $name, $password, $this->config->adminEmail() ?: null);
        } catch (\Throwable $th) {
            return ['errors' => [$th->getMessage()]];
        }

        return $result + ['success' => [_t('FERME_SUPER_USER_ADDED') . ' ' . $folder]];
    }

    /**
     * The AdminWikis button: take the configured farm super admin back out.
     */
    public function remove(string $folder): array
    {
        $name = (string)($this->wiki->config['yeswiki-farm-admin-name'] ?? '');
        if ($name === '') {
            return ['errors' => [_t('FERME_NO_FARM_ADMIN_CONFIGURED')]];
        }

        try {
            $result = $this->removeUser($this->config->wikiDir($folder), $name);
        } catch (\Throwable $th) {
            return ['errors' => [$th->getMessage()]];
        }

        return $result + ['success' => [_t('FERME_SUPER_USER_REMOVED') . ' ' . $folder]];
    }

    /**
     * Create the user or reset its password, and make sure it belongs to the group.
     *
     * @return array{user:string,group:string} user is created or updated, group is
     *                                         created, added or member
     */
    public function ensureUser(
        string $wikiDir,
        string $name,
        string $password,
        ?string $email = null,
        string $group = ADMIN_GROUP,
        bool $dryRun = false
    ): array {
        $wikiConfig = $this->editor->load($wikiDir);
        $prefix = $wikiConfig['table_prefix'] ?? '';
        $db = $this->database->connect($wikiConfig);

        try {
            $existing = $this->database->findUser($db, $prefix, $name);
            $result = ['user' => $existing === null ? 'created' : 'updated'];

            if (!$dryRun) {
                $hash = $this->database->passwordHash($db, $prefix, $password);
                if ($existing === null) {
                    $this->database->insertUser($db, $prefix, $name, $hash, $email ?? '');
                } else {
                    $this->database->updateUser($db, $prefix, $name, $hash, $email ?? $existing['email']);
                }
            }

            $result['group'] = $this->addToGroup($db, $prefix, $group, $name, $dryRun);
        } finally {
            $db->close();
        }

        return $result;
    }

    /**
     * Take the user out of the group and delete it.
     *
     * @return array{user:string,group:string} user is removed or absent, group is
     *                                         removed or absent
     */
    public function removeUser(
        string $wikiDir,
        string $name,
        string $group = ADMIN_GROUP,
        bool $dryRun = false
    ): array {
        $wikiConfig = $this->editor->load($wikiDir);
        $prefix = $wikiConfig['table_prefix'] ?? '';
        $db = $this->database->connect($wikiConfig);

        try {
            $result = ['group' => $this->removeFromGroup($db, $prefix, $group, $name, $dryRun)];

            $existing = $this->database->findUser($db, $prefix, $name);
            $result['user'] = $existing === null ? 'absent' : 'removed';
            if ($existing !== null && !$dryRun) {
                $this->database->deleteUser($db, $prefix, $name);
            }
        } finally {
            $db->close();
        }

        return $result;
    }

    private function addToGroup(\mysqli $db, string $prefix, string $group, string $name, bool $dryRun): string
    {
        $triple = $this->database->groupTriple($db, $prefix, $group);
        $members = $this->database->groupMembers($db, $prefix, $group);

        if (in_array($name, $members, true)) {
            return 'member';
        }

        $members[] = $name;
        if (!$dryRun) {
            $this->database->saveGroupMembers($db, $prefix, $group, $members);
        }

        return $triple === null ? 'created' : 'added';
    }

    private function removeFromGroup(\mysqli $db, string $prefix, string $group, string $name, bool $dryRun): string
    {
        $members = $this->database->groupMembers($db, $prefix, $group);
        if (!in_array($name, $members, true)) {
            return 'absent';
        }

        if (!$dryRun) {
            $this->database->saveGroupMembers($db, $prefix, $group, array_values(array_diff($members, [$name])));
        }

        return 'removed';
    }
}
