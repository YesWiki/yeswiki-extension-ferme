<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Kernel\Service\ConfigurationService;

/** The farm's settings, and where each of its wikis lives. */
class FarmConfig
{
    public const DATA_DIR = 'private/backups/ferme';
    public const CONFIG_FILES = ['yeswiki.config.php', 'wakka.config.php'];
    public const BLANK_MODEL = 'default-content';
    public const RESERVED_FOLDERS = ['cache', 'custom', 'files', 'private', 'src', 'vendor', 'extensions', 'themes', 'styles', 'javascripts', 'templates', 'tests', 'docs', 'lang', 'node_modules', 'binary', 'docker', 'api'];

    /** @var array<string, array<string, mixed>> */
    private array $wikiConfigCache = [];

    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly ConfigurationService $configurationService
    ) {
    }

    /** A setting of the farm wiki, or the default when it says nothing. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->params->has($key) ? $this->params->get($key) : $default;
    }

    /** A setting read as text. */
    public function text(string $key, string $default = ''): string
    {
        $value = $this->setting($key, $default);

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /** A setting read as a yes or a no. */
    public function flag(string $key, bool $default = false): bool
    {
        $value = $this->setting($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * A setting read as a list.
     *
     * @return array<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->setting($key, []);

        return is_array($value) ? $value : [];
    }

    public function farmId(): string
    {
        $id = $this->text('bazar_farm_id', '1100');

        return ctype_digit($id) ? $id : '1100';
    }

    public function instanceDir(): string
    {
        return rtrim(\defined('YESWIKI_INSTANCE_DIR') ? YESWIKI_INSTANCE_DIR : (string)getcwd(), '/');
    }

    /** The folder the wikis are made in, absolute. */
    public function rootFolder(): string
    {
        $folder = $this->text('yeswiki-farm-root-folder', '.');
        if ($folder === '' || $folder === '.') {
            return $this->instanceDir();
        }
        $path = str_starts_with($folder, '/') ? $folder : $this->instanceDir() . '/' . $folder;

        return rtrim(self::normalise($path), '/');
    }

    /** The address the wikis are served under, ending with a slash. */
    public function rootUrl(): string
    {
        $url = $this->text('yeswiki-farm-root-url');
        if ($url === '') {
            $url = str_replace(['wakka.php?wiki=', 'index.php?', '?'], '', $this->text('base_url'));
        }

        return rtrim($url, '/') . '/';
    }

    public function wikiUrl(string $folder): string
    {
        return $this->rootUrl() . $folder . '/';
    }

    /** Refuse a folder or model name that would walk out of the folder it names. */
    public static function isSafeName(string $name, bool $nested = false): bool
    {
        if ($name === '' || strpbrk($name, "\\\0") !== false) {
            return false;
        }
        if (!$nested && str_contains($name, '/')) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** A folder a new wiki may be given: a slug, and not one the farm wiki itself uses. */
    public function isFreeName(string $folder): bool
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $folder) !== 1) {
            return false;
        }

        return $this->rootFolder() !== $this->instanceDir() || !in_array(strtolower($folder), self::RESERVED_FOLDERS, true);
    }

    public function wikiDir(string $folder): string
    {
        if (!self::isSafeName($folder, true)) {
            throw new \InvalidArgumentException(_t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"');
        }

        return self::normalise($this->rootFolder() . '/' . $folder);
    }

    /** The configuration file of a wiki, the Doryphore name included for the wikis that still carry it. */
    public function wikiConfigFile(string $folder): string
    {
        return self::configFileIn($this->wikiDir($folder));
    }

    public static function configFileIn(string $wikiDir): string
    {
        foreach (self::CONFIG_FILES as $name) {
            if (is_file($wikiDir . '/' . $name)) {
                return $wikiDir . '/' . $name;
            }
        }

        return $wikiDir . '/' . self::CONFIG_FILES[0];
    }

    public function hasWiki(string $folder): bool
    {
        return self::isSafeName($folder, true) && is_file($this->wikiConfigFile($folder));
    }

    /** @return array<string, mixed> */
    public function readWikiConfig(string $folder): array
    {
        if (!array_key_exists($folder, $this->wikiConfigCache)) {
            $this->wikiConfigCache[$folder] = $this->readConfigIn($this->wikiDir($folder));
        }

        return $this->wikiConfigCache[$folder];
    }

    /** @return array<string, mixed> */
    public function readConfigIn(string $wikiDir): array
    {
        $path = self::configFileIn($wikiDir);
        if (!is_file($path)) {
            return [];
        }
        $file = $this->configurationService->getConfiguration($path);
        $file->load();
        $parameters = $file->__get('_parameters');

        return is_array($parameters) ? $parameters : [];
    }

    public function forgetWikiConfig(string $folder): void
    {
        unset($this->wikiConfigCache[$folder]);
    }

    /** Where the farm keeps a file of its own, through Storage. */
    public function dataPath(string $relative): string
    {
        return self::DATA_DIR . '/' . ltrim($relative, '/');
    }

    /**
     * The themes a new wiki may start with.
     *
     * @return array<int|string, array{label: string, theme: string, squelette: string, style: string, preset: string, screenshot: string}>
     */
    public function themes(): array
    {
        $themes = [];
        foreach ($this->list('yeswiki-farm-themes') as $key => $theme) {
            if (!is_array($theme) || trim((string)($theme['label'] ?? '')) === '' || trim((string)($theme['theme'] ?? '')) === '') {
                continue;
            }
            if (!$this->themeExists((string)$theme['theme'])) {
                continue;
            }
            $themes[$key] = [
                'label' => (string)$theme['label'],
                'theme' => (string)$theme['theme'],
                'squelette' => (string)($theme['squelette'] ?? SQUELETTE_PAR_DEFAUT),
                'style' => (string)($theme['style'] ?? CSS_PAR_DEFAUT),
                'preset' => (string)($theme['preset'] ?? ''),
                'screenshot' => is_string($theme['screenshot'] ?? null) ? (string)$theme['screenshot'] : '',
            ];
        }

        return $themes !== [] ? $themes : [0 => [
            'label' => 'YesWiki',
            'theme' => THEME_PAR_DEFAUT,
            'squelette' => SQUELETTE_PAR_DEFAUT,
            'style' => CSS_PAR_DEFAUT,
            'preset' => '',
            'screenshot' => '',
        ]];
    }

    private function themeExists(string $theme): bool
    {
        if (!self::isSafeName($theme)) {
            return false;
        }
        $program = \defined('YESWIKI_PROGRAM_DIR') ? YESWIKI_PROGRAM_DIR : $this->instanceDir();

        return is_dir($program . '/themes/' . $theme) || is_dir($this->instanceDir() . '/custom/themes/' . $theme);
    }

    /**
     * The access rights a new wiki may start with.
     *
     * @return array<int|string, array{label: string, read: string, write: string, comments: string, create_user: bool}>
     */
    public function acls(): array
    {
        $acls = [];
        foreach ($this->list('yeswiki-farm-acls') as $key => $acl) {
            if (!is_array($acl) || trim((string)($acl['label'] ?? '')) === '') {
                continue;
            }
            $acls[$key] = [
                'label' => (string)$acl['label'],
                'read' => (string)($acl['read'] ?? '*'),
                'write' => (string)($acl['write'] ?? '*'),
                'comments' => (string)($acl['comments'] ?? 'comments-closed'),
                'create_user' => !empty($acl['create_user']),
            ];
        }

        return $acls !== [] ? $acls : [0 => ['label' => _t('FERME_ACL_OPEN'), 'read' => '*', 'write' => '*', 'comments' => 'comments-closed', 'create_user' => false]];
    }

    /**
     * The additions a creator may tick: some text appended to a page of the new wiki.
     *
     * @return array<int|string, array{label: string, checked: bool, page: string, content: string}>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->list('yeswiki-farm-options') as $key => $option) {
            if (!is_array($option) || trim((string)($option['label'] ?? '')) === '' || trim((string)($option['page'] ?? '')) === '') {
                continue;
            }
            $options[$key] = [
                'label' => (string)$option['label'],
                'checked' => !empty($option['checked']),
                'page' => (string)$option['page'],
                'content' => (string)($option['content'] ?? ''),
            ];
        }

        return $options;
    }

    /** @return array<string, mixed> settings every new wiki is given */
    public function extraConfig(): array
    {
        $extra = [];
        foreach ($this->list('yeswiki-farm-extra-config') as $key => $value) {
            if (is_string($key) && $key !== '') {
                $extra[$key] = $value;
            }
        }

        return $extra;
    }

    /** @return list<string> extensions switched on in every new wiki */
    public function extensions(): array
    {
        return array_values(array_filter(array_map(
            static fn ($name): string => is_string($name) ? trim($name, " \t\n\r\0\x0B/") : '',
            $this->list('yeswiki-farm-extensions')
        ), static fn (string $name): bool => $name !== '' && self::isSafeName($name)));
    }

    public function homepage(): string
    {
        $page = $this->text('yeswiki-farm-homepage');

        return $page !== '' ? $page : 'PagePrincipale';
    }

    public function adminEmail(): string
    {
        return $this->text('yeswiki-farm-admin-email');
    }

    public function tablePrefix(string $folder): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '_', $this->text('yeswiki-farm-prefix', 'yeswiki_')) ?? 'yeswiki_';

        return $prefix . str_replace('-', '_', $folder) . '__';
    }

    /**
     * What a new wiki's database is: its own database and account when the farm can create them, the farm's database under a prefix of its own otherwise.
     *
     * @return array{driver: string, host: string, port: string, database: string, user: string, password: string, prefix: string, provision: bool}
     */
    public function databaseFor(string $folder): array
    {
        $driver = $this->text('yeswiki-farm-db-driver');
        if ($driver === '') {
            $driver = $this->text('db_driver', 'mysql');
        }
        $host = $this->text('yeswiki-farm-db-host');
        $port = $this->text('yeswiki-farm-db-port');
        $sameServer = $host === '' || $host === $this->text('db_host');
        $host = $host !== '' ? $host : $this->text('db_host', 'localhost');
        $port = $port !== '' ? $port : ($sameServer ? $this->text('db_port') : '');

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'host' => '', 'port' => '', 'database' => '', 'user' => '', 'password' => '', 'prefix' => 'yeswiki_', 'provision' => false];
        }

        $slug = substr(strtolower(rtrim($this->tablePrefix($folder), '_')), 0, 60);
        if ($this->text('yeswiki-farm-db-admin-user') !== '') {
            return [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => $slug,
                'user' => substr($slug, 0, 32),
                'password' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
                'prefix' => 'yeswiki_',
                'provision' => true,
            ];
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $this->text('db_database'),
            'user' => $this->text('db_user'),
            'password' => $this->text('db_password'),
            'prefix' => $this->tablePrefix($folder),
            'provision' => false,
        ];
    }

    /** A path with its `.` and `..` resolved, without asking the disk. */
    public static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
