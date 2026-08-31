<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

class FarmAdminAccount
{
    protected $wiki;
    protected $config;

    public function __construct(Wiki $wiki, FarmConfig $config)
    {
        $this->wiki = $wiki;
        $this->config = $config;
    }

    public function add(string $folder): array
    {
        $adminName = $this->wiki->config['yeswiki-farm-admin-name'] ?? '';
        $adminPass = $this->wiki->config['yeswiki-farm-admin-pass'] ?? '';

        $error = $this->guard($folder, !empty($adminName) && !empty($adminPass));
        if ($error !== null) {
            return $error;
        }

        $prefix = $this->config->readWikiConfig($folder)['table_prefix'];
        $this->inWikiDatabase($folder, function () use ($prefix, $adminName, $adminPass) {
            $this->setAdminsGroupMembership($prefix, $adminName, true);

            $existing = $this->wiki->LoadSingle(
                'SELECT name FROM `' . $prefix . 'users` WHERE name="' . addslashes($adminName) . '";'
            );
            if (empty($existing)) {
                $this->wiki->Query(
                    'INSERT INTO `' . $prefix . 'users`'
                    . ' (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`)'
                    . ' VALUES ("' . addslashes($adminName) . '", MD5("' . addslashes($adminPass) . '"), "", "", "20", "50", "Y", NOW(), "N");'
                );
            } else {
                $this->wiki->Query(
                    'UPDATE `' . $prefix . 'users` SET password=MD5("' . addslashes($adminPass) . '")'
                    . ' WHERE name="' . addslashes($adminName) . '";'
                );
            }
        });

        return ['success' => [_t('Super user added for the wiki') . ' :' . $folder . '.']];
    }

    public function remove(string $folder): array
    {
        $adminName = $this->wiki->config['yeswiki-farm-admin-name'] ?? '';

        $error = $this->guard($folder, !empty($adminName));
        if ($error !== null) {
            return $error;
        }

        $prefix = $this->config->readWikiConfig($folder)['table_prefix'];
        $this->inWikiDatabase($folder, function () use ($prefix, $adminName) {
            $this->setAdminsGroupMembership($prefix, $adminName, false);
            $this->wiki->Query('DELETE FROM `' . $prefix . 'users` WHERE name="' . addslashes($adminName) . '";');
        });

        return ['success' => [_t('Super user removed for the wiki') . ' :' . $folder . '.']];
    }

    private function guard(string $folder, bool $accountIsConfigured): ?array
    {
        if (!$this->wiki->UserIsAdmin()) {
            return ['errors' => ['Unauthorized']];
        }

        if (!$accountIsConfigured) {
            return ['errors' => [_t('No yeswiki-farm-admin-name or yeswiki-farm-admin-pass in config.')]];
        }

        if (empty($this->config->readWikiConfig($folder)['table_prefix'])) {
            return ['errors' => [_t('No table prefix found for the wiki') . ' :' . $folder . '.']];
        }

        return null;
    }

    private function inWikiDatabase(string $folder, callable $callback): void
    {
        $wikiConf = $this->config->readWikiConfig($folder);
        $this->wiki->query('USE ' . $wikiConf['mysql_database'] . ';');
        try {
            $callback();
        } finally {
            $this->wiki->query('USE ' . $this->wiki->config['mysql_database'] . ';');
        }
    }

    private function setAdminsGroupMembership(string $prefix, string $userName, bool $isMember): void
    {
        $resource = GROUP_PREFIX . ADMIN_GROUP;
        $where = ' WHERE resource="' . $resource . '" AND property="' . WIKINI_VOC_ACLS_URI . '";';

        $triple = $this->wiki->LoadSingle('SELECT value FROM `' . $prefix . 'triples`' . $where);
        $members = array_values(array_filter(array_map(
            'trim',
            explode("\n", $triple['value'] ?? '')
        ), 'strlen'));

        if ($isMember) {
            if (in_array($userName, $members, true)) {
                return;
            }
            $members[] = $userName;
        } else {
            if (!in_array($userName, $members, true)) {
                return;
            }
            $members = array_values(array_diff($members, [$userName]));
        }

        $value = addslashes(implode("\n", $members));

        if ($triple === null) {
            $this->wiki->Query(
                'INSERT INTO `' . $prefix . 'triples` (`resource`, `property`, `value`)'
                . ' VALUES ("' . $resource . '", "' . WIKINI_VOC_ACLS_URI . '", "' . $value . '");'
            );

            return;
        }

        $this->wiki->Query('UPDATE `' . $prefix . 'triples` SET value="' . $value . '"' . $where);
    }
}
