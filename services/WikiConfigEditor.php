<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Wiki;

/**
 * Read and rewrite the wakka.config.php of another wiki.
 *
 * Writing goes through the core ConfigurationService, so the file comes back in
 * the exact shape YesWiki writes itself: short arrays, two spaces, no reordering
 * surprises.
 */
class WikiConfigEditor
{
    /**
     * Mail settings the farm pushes to its wikis. Read from the master's own
     * config, so a farm never carries a second copy of its SMTP credentials.
     */
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

    /**
     * Copy a wakka.config.php aside before touching it. The folder is named after
     * the wiki path, so two wikis sharing a base_url never overwrite each other.
     */
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

    /**
     * Apply the wanted keys to a config array, in place.
     *
     * @return array<string,array{old:mixed,new:mixed}> only the keys that really change
     */
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

    /**
     * The keys --smtp writes in one wiki: the master's mail settings, plus the
     * same settings inside yeswiki-farm-extra-config when that wiki is itself a
     * farm, so the wikis it creates in turn inherit them.
     */
    public function smtpChangesFor(array $wikiConfig): array
    {
        $smtp = $this->smtpFromMaster();
        $set = $smtp;

        if (isset($wikiConfig['yeswiki-farm-extra-config']) && is_array($wikiConfig['yeswiki-farm-extra-config'])) {
            foreach ($smtp as $key => $value) {
                $set['yeswiki-farm-extra-config.' . $key] = $value;
            }
        }

        return $set;
    }

    /**
     * What --smtp writes in the master itself: only inside yeswiki-farm-extra-config,
     * which WikiCreator merges into every wiki it creates from then on. The master's
     * own contact_* settings are the source here, so they are left alone.
     */
    public function smtpChangesForMaster(): array
    {
        $set = [];
        foreach ($this->smtpFromMaster() as $key => $value) {
            $set['yeswiki-farm-extra-config.' . $key] = $value;
        }

        return $set;
    }

    /**
     * The master's own mail settings, the ones its wikis should inherit.
     *
     * A farm that does not send through SMTP itself has nothing to propagate:
     * the contact defaults are an empty host and contact_mail_func 'mail', and
     * copying those into every wiki would break their mail silently.
     */
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

    /**
     * Values given on the command line are strings, except these.
     */
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

    /**
     * Keep passwords out of the terminal and out of whatever logs it.
     */
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

    /**
     * Nested keys use dots: yeswiki-farm-extra-config.contact_from.
     */
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
