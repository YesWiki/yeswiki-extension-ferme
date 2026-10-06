<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Admin\Command\DestroyCommand;
use YesWiki\Admin\Service\DatabaseProvisioner;
use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Identity\Service\UserManager;
use YesWiki\Kernel\Service\UrlFormatter;

/** Creates a wiki of the farm: a new Instance of the shared Program, made by core's create-instance and installed by core's installer. */
class WikiCreator
{
    public const ADMIN_GROUP = 'admins';

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceConsole $console,
        private readonly ModelLibrary $models,
        private readonly WikiConfigEditor $editor,
        private readonly FolderLock $lock,
        private readonly MattermostNotifier $mattermost,
        private readonly WelcomeMailer $welcome,
        private readonly StatsRefresher $refresher,
        private readonly UserManager $userManager,
        private readonly UrlFormatter $urlFormatter,
        private readonly InstanceDatabase $database
    ) {
    }

    /** @return list<string> what went wrong without stopping the creation */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Create the wiki an entry of the farm form describes.
     *
     * @param array<string, mixed> $entry what the creation form posted
     */
    public function createFromEntry(array $entry, string $fieldName, string $theme = '0', string $model = FarmConfig::BLANK_MODEL): string
    {
        $this->warnings = [];
        $folder = trim((string)($entry[$fieldName] ?? ''));
        if (!$this->config->isFreeName($folder)) {
            throw new WikiCreationException(_t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"', $fieldName);
        }
        $options = $this->checkChoices($entry, $theme, $model);
        $admin = $this->admin($entry, $fieldName, $folder);

        if ($this->config->flag('yeswiki-farm-create-user')) {
            $this->createFarmUser($admin, $fieldName);
        }

        $dir = $this->config->wikiDir($folder);
        if (!$this->lock->acquire($dir, _t('FERME_LOCK_CREATE'))) {
            throw new WikiCreationException(_t('FERME_FOLDER_BUSY') . ' ' . $folder, $fieldName);
        }

        try {
            if (file_exists($dir)) {
                throw new WikiCreationException(_t('FERME_ADDRESS_TAKEN', ['url' => $this->config->wikiUrl($folder)]), $fieldName);
            }
            if (!is_dir($this->config->rootFolder()) || !is_writable($this->config->rootFolder())) {
                throw new WikiCreationException(_t('FERME_ROOT_NOT_WRITABLE', ['folder' => $this->config->rootFolder()]));
            }

            $installed = false;
            $db = $this->config->databaseFor($folder);
            try {
                $this->console->createInstance($dir);
                $archive = $model === FarmConfig::BLANK_MODEL ? '' : $this->models->placeInto($model, $dir);
                $this->install($dir, $folder, $db, $entry, $admin, $archive);
                $installed = true;
                $this->configure($dir, $entry, $theme);
                if ($archive !== '') {
                    $this->ensureAccount($dir, $admin['name'], $admin['email'], $admin['password'], self::ADMIN_GROUP);
                }
                $this->addAccessAccount($dir, $entry, $admin['email']);
                $this->enableExtensions($dir);
            } catch (\Throwable $failed) {
                $this->undo($dir, $db, $installed);

                throw $failed instanceof WikiCreationException ? $failed : new WikiCreationException(_t('FERME_CREATION_FAILED') . ' ' . $failed->getMessage(), null, $failed);
            }

            $this->applyOptions($dir, $options);
            $this->joinGroup($dir, $entry);
            $this->refresher->remeasure($folder);
            $this->mattermost->created($entry, $folder);
            $this->greet($entry, $admin['name']);
        } finally {
            $this->lock->release($dir);
        }

        return $dir;
    }

    /**
     * Refuse a theme, model, access right or option the farm does not offer.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<array{label: string, checked: bool, page: string, content: string}> the options ticked
     */
    private function checkChoices(array $entry, string $theme, string $model): array
    {
        if (!array_key_exists($model, $this->models->choices())) {
            throw new WikiCreationException(_t('FERME_INVALID_MODEL_NAME') . ' "' . $model . '"');
        }
        if (!array_key_exists($theme, $this->config->themes())) {
            throw new WikiCreationException(_t('FERME_INVALID_THEME') . ' "' . $theme . '"');
        }
        $acl = $entry['yeswiki-farm-acls'] ?? array_key_first($this->config->acls());
        if (!is_scalar($acl) || !array_key_exists((string)$acl, $this->config->acls())) {
            throw new WikiCreationException(_t('FERME_INVALID_ACL') . ' "' . (is_scalar($acl) ? $acl : '') . '"');
        }

        $offered = $this->config->options();
        $ticked = [];
        $asked = $entry['yeswiki-farm-options'] ?? [];
        foreach (is_array($asked) ? $asked : explode(',', is_scalar($asked) ? (string)$asked : '') as $key) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $offered)) {
                throw new WikiCreationException(_t('FERME_INVALID_OPTION') . ' "' . $key . '"');
            }
            $ticked[] = $offered[$key];
        }

        return $ticked;
    }

    /**
     * The first account of the new wiki, as the farm or the creator names it.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{name: string, email: string, password: string}
     */
    private function admin(array $entry, string $fieldName, string $folder): array
    {
        $name = trim((string)($this->config->text('yeswiki-farm-default-WikiAdmin') !== '' ? $this->config->text('yeswiki-farm-default-WikiAdmin') : ($entry[$fieldName . '_wikiname'] ?? '')));
        $name = $name === '{{folder}}' || $name === '' ? $folder : $name;
        if (preg_match('/^[^!#@<>\\\\\/][^<>\\\\\/]{2,}$/', $name) !== 1 || strlen($name) > 80) {
            throw new WikiCreationException(_t('FERME_CLI_BAD_USER_NAME') . ' "' . $name . '"', $fieldName . '_wikiname');
        }

        $email = trim((string)($entry[$fieldName . '_email'] ?? ''));
        $configured = $this->config->text('yeswiki-farm-email-WikiAdmin', 'bf_mail');
        if ($email === '' || (!filter_var($email, FILTER_VALIDATE_EMAIL) && isset($entry[$email]))) {
            $email = trim((string)($entry[$email !== '' ? $email : $configured] ?? $entry[$configured] ?? ''));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new WikiCreationException(_t('FERME_CLI_BAD_EMAIL') . ' "' . $email . '"', $fieldName . '_email');
        }

        $password = $this->config->text('yeswiki-farm-password-WikiAdmin');
        $password = $password !== '' ? $password : (string)($entry[$fieldName . '_password'] ?? '');
        if (strlen($password) < 5) {
            throw new WikiCreationException(_t('FERME_PASSWORD_TOO_SHORT'), $fieldName . '_password');
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /** @param array{name: string, email: string, password: string} $admin */
    private function createFarmUser(array $admin, string $fieldName): void
    {
        if ($this->userManager->getOneByName($admin['name']) !== null) {
            throw new WikiCreationException(_t('FERME_USER_TAKEN', ['name' => $admin['name']]), $fieldName . '_wikiname');
        }
        $this->userManager->create($admin['name'], $admin['email'], $admin['password']);
    }

    /**
     * Run the new wiki's own `core:install`, passwords given through its environment rather than its command line.
     *
     * @param array{driver: string, host: string, port: string, database: string, user: string, password: string, prefix: string, provision: bool} $db
     * @param array<string, mixed>                                                                                                                 $entry
     * @param array{name: string, email: string, password: string}                                                                                 $admin
     */
    private function install(string $dir, string $folder, array $db, array $entry, array $admin, string $archive): void
    {
        $arguments = [
            'core:install',
            '--driver=' . $db['driver'],
            '--table-prefix=' . $db['prefix'],
            '--base-url=' . $this->config->wikiUrl($folder) . '?',
            '--root-page=' . $this->config->homepage(),
            '--wiki-name=' . FarmMailer::titleOf($entry),
            '--language=' . $this->config->text('default_language', 'fr'),
            '--admin-name=' . $admin['name'],
            '--admin-email=' . $admin['email'],
        ];
        $env = ['ADMIN_PASSWORD' => $admin['password']];
        if ($db['driver'] !== 'sqlite') {
            array_push($arguments, '--db-host=' . $db['host'], '--db-database=' . $db['database'], '--db-user=' . $db['user']);
            if ($db['port'] !== '') {
                $arguments[] = '--db-port=' . $db['port'];
            }
            $env['DB_PASSWORD'] = $db['password'];
        }
        if ($db['provision']) {
            $arguments[] = '--db-admin-user=' . $this->config->text('yeswiki-farm-db-admin-user');
            $env['DB_ADMIN_PASSWORD'] = $this->config->text('yeswiki-farm-db-admin-password');
        }
        if ($archive !== '') {
            $arguments[] = '--from-archive=' . $archive;
        }

        $this->console->mustRun($dir, $arguments, $env);
    }

    /**
     * What the farm decides for every new wiki: access rights, theme, description and the settings it imposes.
     *
     * @param array<string, mixed> $entry
     */
    private function configure(string $dir, array $entry, string $theme): void
    {
        $acl = $this->config->acls()[(string)($entry['yeswiki-farm-acls'] ?? array_key_first($this->config->acls()))];
        $username = trim((string)($entry['access-username'] ?? '')) !== '' ? trim((string)$entry['access-username']) : '';
        $rights = [];
        foreach (['write' => 'default_write_acl', 'read' => 'default_read_acl', 'comments' => 'default_comment_acl'] as $right => $key) {
            $rights[$key] = $acl[$right] === '{{user}}' ? ($username !== '' ? $username : '@admins') : $acl[$right];
        }
        $chosen = $this->config->themes()[$theme];
        $set = $rights + [
            'favorite_theme' => $chosen['theme'],
            'favorite_squelette' => $chosen['squelette'],
            'favorite_style' => $chosen['style'],
            'favorite_preset' => $chosen['preset'],
            'ferme_entry' => $this->urlFormatter->href('', (string)($entry['tag'] ?? ''), null, false),
        ];
        $description = trim(strip_tags(str_replace('<br>', ' ', (string)($entry['bf_description'] ?? ''))));
        if ($description !== '') {
            $set['meta_description'] = mb_substr($description, 0, 150);
        }
        $extra = $this->config->extraConfig();
        if ($extra !== []) {
            $set += $extra;
            $set[WikiConfigEditor::LOCKED_PARAMS] = WikiConfigEditor::lockParams($this->editor->load($dir), array_keys($extra));
        }
        $this->editor->patch($dir, $set);
    }

    /** Create an account in the wiki, or give it this password, and put it in a group. */
    public function ensureAccount(string $dir, string $name, string $email, string $password, string $group = ''): void
    {
        $env = ['YESWIKI_USER_PASSWORD' => $password];
        $created = $this->console->run($dir, ['user:create', $name, $email, ...($group === '' ? [] : ['--group=' . $group])], $env);
        if ($created['status'] === 0) {
            return;
        }
        $updated = $this->console->run($dir, ['user:update', $name, '--email=' . $email], $env);
        if ($updated['status'] !== 0) {
            throw new \RuntimeException('user:create: ' . $created['output']);
        }
        if ($group !== '') {
            $this->console->mustRun($dir, ['user:add-to-group', $name, $group]);
        }
    }

    /**
     * The account a protected wiki is read with, when the creator asked for one.
     *
     * @param array<string, mixed> $entry
     */
    private function addAccessAccount(string $dir, array $entry, string $email): void
    {
        $name = trim((string)($entry['access-username'] ?? ''));
        $password = (string)($entry['access-password'] ?? '');
        if ($name !== '' && $password !== '') {
            $this->ensureAccount($dir, $name, $email, $password);
        }
    }

    private function enableExtensions(string $dir): void
    {
        foreach ($this->config->extensions() as $extension) {
            $result = $this->console->run($dir, ['extension:enable', $extension]);
            if ($result['status'] !== 0) {
                $this->warnings[] = $extension . ': ' . $result['output'];
            }
        }
    }

    /** @param list<array{label: string, checked: bool, page: string, content: string}> $options */
    private function applyOptions(string $dir, array $options): void
    {
        foreach ($options as $option) {
            try {
                $this->console->task($dir, 'append-to-page', ['tag' => $option['page'], 'content' => $option['content']]);
            } catch (\Throwable $failed) {
                $this->warnings[] = $option['label'] . ': ' . $failed->getMessage();
            }
        }
    }

    /**
     * Put the people an entry field names in a group of the new wiki.
     *
     * @param array<string, mixed> $entry
     */
    private function joinGroup(string $dir, array $entry): void
    {
        $group = $this->config->list('yeswiki-farm-group');
        $name = trim((string)($group['groupname'] ?? ''));
        $field = (string)($group['group_members_field'] ?? '');
        if ($name === '' || $field === '') {
            return;
        }
        $members = $entry[$field] ?? '';
        foreach (array_filter(array_map('trim', is_array($members) ? $members : explode(',', (string)$members))) as $member) {
            $result = $this->console->run($dir, ['user:add-to-group', (string)$member, $name]);
            if ($result['status'] !== 0) {
                $this->warnings[] = $member . ': ' . $result['output'];
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function greet(array $entry, string $username): void
    {
        try {
            $this->welcome->send($entry, $username, new \DateTimeImmutable('today'));
        } catch (\Throwable $throwable) {
            $this->warnings[] = _t('FERME_WELCOME_MAIL_FAILED') . ' ' . $throwable->getMessage();
        }
    }

    /**
     * Leave nothing behind when the creation failed halfway: core drops what the install made, the folder goes after.
     *
     * @param array{driver: string, host: string, port: string, database: string, user: string, password: string, prefix: string, provision: bool} $db
     */
    private function undo(string $dir, array $db, bool $installed): void
    {
        try {
            if ($installed) {
                $config = $this->config->readConfigIn($dir);
                $arguments = ['core:destroy', '--keep-archive', '--confirm=' . DestroyCommand::hostOf((string)($config['base_url'] ?? ''))];
                $env = [];
                if ($db['provision']) {
                    $arguments[] = '--db-admin-user=' . $this->config->text('yeswiki-farm-db-admin-user');
                    $env['DB_ADMIN_PASSWORD'] = $this->config->text('yeswiki-farm-db-admin-password');
                } else {
                    $arguments[] = '--keep-database';
                }
                $this->console->run($dir, $arguments, $env);
                if (!$db['provision'] && $db['driver'] !== 'sqlite') {
                    $this->database->dropOwnTables($config, $dir);
                }
            } elseif ($db['provision']) {
                (new DatabaseProvisioner())->destroy($this->config->text('yeswiki-farm-db-admin-user'), $this->config->text('yeswiki-farm-db-admin-password'), [
                    'db_driver' => $db['driver'],
                    'db_host' => $db['host'],
                    'db_port' => $db['port'],
                    'db_database' => $db['database'],
                    'db_user' => $db['user'],
                ]);
            }
        } catch (\Throwable $ignored) {
            error_log('ferme: ' . $ignored->getMessage());
        }

        try {
            if (is_dir($dir)) {
                $this->console->removeInstance($dir);
            }
        } catch (\Throwable $ignored) {
            error_log('ferme: ' . $ignored->getMessage());
        }
    }
}
