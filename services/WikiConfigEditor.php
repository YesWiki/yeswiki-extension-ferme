<?php
// Reads and rewrites the wakka.config.php of another wiki through the core ConfigurationService.

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Wiki;

class WikiConfigEditor
{
    public const LOCKED_PARAMS = 'edit_config_locked_params';

    public const SMTP_KEYS = [
        'contact_mail_func',
        'contact_smtp_host',
        'contact_smtp_port',
        'contact_smtp_user',
        'contact_smtp_pass',
        'contact_smtp_secure',
        'contact_from',
    ];

    protected $wiki;
    protected $config;
    protected $configurationService;

    public function __construct(Wiki $wiki, FarmConfig $config, ConfigurationService $configurationService)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->configurationService = $configurationService;
    }

    public function load(string $wikiDir): array
    {
        $path = $this->configPath($wikiDir);
        if (!is_file($path)) {
            throw new \RuntimeException(_t('FERME_CLI_NO_CONFIG_FILE') . ' ' . $path);
        }

        $configFile = $this->configurationService->getConfiguration($path);
        $configFile->load();
        $parameters = $configFile->_parameters;
        if (empty($parameters)) {
            throw new \RuntimeException(_t('FERME_CLI_EMPTY_CONFIG_FILE') . ' ' . $path);
        }

        return $parameters;
    }

    public function write(string $wikiDir, array $config): void
    {
        $path = $this->configPath($wikiDir);
        $configFile = $this->configurationService->getConfiguration($path);
        foreach ($config as $key => $value) {
            $configFile[$key] = $value;
        }
        if ($configFile->write() === false) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_WRITE') . ' ' . $path);
        }
    }

    // copies a wakka.config.php into the farm backups, in a folder named after the wiki path
    public function backup(string $wikiDir): string
    {
        $slug = str_replace('/', '-', trim($this->realPath($wikiDir), '/'));
        $dir = $this->config->ensureBackupDir('configs' . DIRECTORY_SEPARATOR . $slug);
        $backupFile = $dir . DIRECTORY_SEPARATOR . 'wakka.config.php.' . date('Ymd-His');
        if (!copy($this->configPath($wikiDir), $backupFile)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_WRITE') . ' ' . $backupFile);
        }
        chmod($backupFile, 0600);

        return $backupFile;
    }

    // applies the wanted keys to a config array in place and returns only the real changes
    public function apply(array &$config, array $set, array $unset = []): array
    {
        $changes = [];

        foreach ($set as $path => $value) {
            $old = self::getPath($config, $path);
            if (self::hasPath($config, $path) && $old === $value) {
                continue;
            }
            $changes[$path] = ['old' => self::hasPath($config, $path) ? $old : '(missing)', 'new' => $value];
            self::setPath($config, $path, $value);
        }

        foreach ($unset as $path) {
            if (!self::hasPath($config, $path)) {
                continue;
            }
            $changes[$path] = ['old' => self::getPath($config, $path), 'new' => '(removed)'];
            self::unsetPath($config, $path);
        }

        return $changes;
    }

    // returns the keys --smtp writes in one wiki: the master mail settings, locked in edit config, and copied in its own farm defaults
    public function smtpChangesFor(array $wikiConfig): array
    {
        $smtp = $this->smtpFromMaster();
        $set = $smtp;
        $set[self::LOCKED_PARAMS] = self::lockParams($wikiConfig, array_keys($smtp));

        if (isset($wikiConfig['yeswiki-farm-extra-config']) && is_array($wikiConfig['yeswiki-farm-extra-config'])) {
            foreach ($smtp as $key => $value) {
                $set['yeswiki-farm-extra-config.' . $key] = $value;
            }
        }

        return $set;
    }

    // returns the keys --smtp writes in the master: only its farm defaults, from its own contact_* settings
    public function smtpChangesForMaster(): array
    {
        $set = [];
        foreach ($this->smtpFromMaster() as $key => $value) {
            $set['yeswiki-farm-extra-config.' . $key] = $value;
        }

        return $set;
    }

    // returns the master smtp settings, and refuses to propagate a master that does not send through smtp
    public function smtpFromMaster(): array
    {
        $smtp = [];
        foreach (self::SMTP_KEYS as $key) {
            if (isset($this->wiki->config[$key]) && $this->wiki->config[$key] !== '') {
                $smtp[$key] = $this->wiki->config[$key];
            }
        }

        if (($smtp['contact_mail_func'] ?? '') !== 'smtp' || ($smtp['contact_smtp_host'] ?? '') === '') {
            throw new \RuntimeException(_t('FERME_CLI_NO_SMTP_IN_MASTER'));
        }

        return $smtp;
    }

    // returns the locked edit config params of a wiki with these keys added
    public static function lockParams(array $config, array $keys): array
    {
        $locked = array_merge((array)($config[self::LOCKED_PARAMS] ?? []), array_diff($keys, [self::LOCKED_PARAMS]));

        return array_values(array_unique($locked));
    }

    // casts a command line value, which stays a string except true, false, null, int: and json:
    public static function cast(string $raw)
    {
        switch ($raw) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
        }
        if (str_starts_with($raw, 'int:')) {
            return (int)substr($raw, 4);
        }
        if (str_starts_with($raw, 'json:')) {
            return json_decode(substr($raw, 5), true, 512, JSON_THROW_ON_ERROR);
        }

        return $raw;
    }

    // prints a value for the terminal, hiding passwords
    public static function mask(string $key, $value): string
    {
        $printable = is_scalar($value) || is_null($value)
            ? var_export($value, true)
            : json_encode($value, JSON_UNESCAPED_SLASHES);

        if (
            preg_match('/pass|secret|token|key/i', $key)
            && is_string($value)
            && !in_array($value, ['', '(missing)', '(removed)'], true)
        ) {
            return "'********'";
        }

        return $printable;
    }

    // reads a nested key written with dots, like yeswiki-farm-extra-config.contact_from
    public static function getPath(array $config, string $path)
    {
        $current = $config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public static function hasPath(array $config, string $path): bool
    {
        $current = $config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public static function setPath(array &$config, string $path, $value): void
    {
        $segments = explode('.', $path);
        $current = &$config;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $current[$segment] = $value;
                break;
            }
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        unset($current);
    }

    public static function unsetPath(array &$config, string $path): void
    {
        $segments = explode('.', $path);
        $current = &$config;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                unset($current[$segment]);
                break;
            }
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }
            $current = &$current[$segment];
        }
        unset($current);
    }

    private function configPath(string $wikiDir): string
    {
        return rtrim($wikiDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . WikiFinder::CONFIG_FILE;
    }

    private function realPath(string $wikiDir): string
    {
        $real = realpath($wikiDir);

        return $real === false ? rtrim($wikiDir, '/') : $real;
    }
}
