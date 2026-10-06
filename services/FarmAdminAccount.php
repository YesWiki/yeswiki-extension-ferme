<?php

namespace YesWiki\Ferme\Service;

/** Creates, updates or removes an account in another wiki through that wiki's own `user:*` commands. */
class FarmAdminAccount
{
    public const ADMIN_GROUP = 'admins';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceConsole $console,
        private readonly WikiHibernator $hibernator,
        private readonly StatsRefresher $refresher
    ) {
    }

    /**
     * Put the farm's super admin in a wiki.
     *
     * @return array<string, mixed>
     */
    public function add(string $folder): array
    {
        $this->hibernator->refuseIfAsleep($folder);
        $name = $this->config->text('yeswiki-farm-admin-name');
        $password = $this->config->text('yeswiki-farm-admin-pass');
        if ($name === '' || $password === '') {
            return ['errors' => [_t('FERME_NO_FARM_ADMIN_CONFIGURED')]];
        }

        try {
            $result = $this->ensureUser($this->config->wikiDir($folder), $name, $password, $this->config->adminEmail() !== '' ? $this->config->adminEmail() : null);
        } catch (\Throwable $th) {
            return ['errors' => [$th->getMessage()]];
        }
        $this->refresher->remeasure($folder, false);

        return $result + ['success' => [_t('FERME_SUPER_USER_ADDED') . ' ' . $folder]];
    }

    /**
     * Take the farm's super admin back out of a wiki.
     *
     * @return array<string, mixed>
     */
    public function remove(string $folder): array
    {
        $this->hibernator->refuseIfAsleep($folder);
        $name = $this->config->text('yeswiki-farm-admin-name');
        if ($name === '') {
            return ['errors' => [_t('FERME_NO_FARM_ADMIN_CONFIGURED')]];
        }

        try {
            $result = $this->removeUser($this->config->wikiDir($folder), $name);
        } catch (\Throwable $th) {
            return ['errors' => [$th->getMessage()]];
        }
        $this->refresher->remeasure($folder, false);

        return $result + ['success' => [_t('FERME_SUPER_USER_REMOVED') . ' ' . $folder]];
    }

    /**
     * Create the account or give it this password, and make sure it is in the group.
     *
     * @return array{user: string, group: string}
     */
    public function ensureUser(string $wikiDir, string $name, string $password, ?string $email = null, string $group = self::ADMIN_GROUP, bool $dryRun = false): array
    {
        $existing = $this->find($wikiDir, $name);
        $result = ['user' => $existing === null ? 'created' : 'updated', 'group' => $existing !== null && in_array($group, $existing['groups'], true) ? 'member' : 'added'];
        if ($dryRun) {
            return $result;
        }
        $env = ['YESWIKI_USER_PASSWORD' => $password];
        if ($existing === null) {
            $this->console->mustRun($wikiDir, ['user:create', $name, $email ?? $this->config->adminEmail(), '--group=' . $group], $env);

            return $result;
        }
        $this->console->mustRun($wikiDir, ['user:update', $name, ...($email === null ? [] : ['--email=' . $email])], $env);
        if ($result['group'] === 'added') {
            $this->console->mustRun($wikiDir, ['user:add-to-group', $name, $group]);
        }

        return $result;
    }

    /**
     * Take the account out of the group and delete it.
     *
     * @return array{user: string, group: string}
     */
    public function removeUser(string $wikiDir, string $name, string $group = self::ADMIN_GROUP, bool $dryRun = false): array
    {
        $existing = $this->find($wikiDir, $name);
        $result = ['user' => $existing === null ? 'absent' : 'removed', 'group' => $existing !== null && in_array($group, $existing['groups'], true) ? 'removed' : 'absent'];
        if (!$dryRun && $existing !== null) {
            $this->console->mustRun($wikiDir, ['user:delete', $name]);
        }

        return $result;
    }

    /**
     * An account of the wiki as its `user:list` shows it.
     *
     * @return array{name: string, groups: list<string>}|null
     */
    private function find(string $wikiDir, string $name): ?array
    {
        foreach (preg_split('/\R/', $this->console->mustRun($wikiDir, ['user:list'])) ?: [] as $line) {
            $columns = array_map('trim', explode('|', trim(trim($line), '|')));
            if ($columns[0] !== $name) {
                continue;
            }

            return ['name' => $name, 'groups' => array_values(array_filter(array_map(static fn (string $group): string => ltrim(trim($group), '@'), explode(',', (string)($columns[2] ?? '')))))];
        }

        return null;
    }
}
