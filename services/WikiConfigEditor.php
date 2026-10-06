<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Files\Service\Storage;
use YesWiki\Kernel\Service\ConfigurationService;

/** Reads and rewrites another wiki's configuration file through core's ConfigurationService. */
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

    public function __construct(
        private readonly FarmConfig $config,
        private readonly ConfigurationService $configurationService,
        private readonly Storage $storage
    ) {
    }

    /** @return array<string, mixed> */
    public function load(string $wikiDir): array
    {
        $path = FarmConfig::configFileIn($wikiDir);
        if (!is_file($path)) {
            throw new \RuntimeException(_t('FERME_CLI_NO_CONFIG_FILE') . ' ' . $path);
        }
        $parameters = $this->config->readConfigIn($wikiDir);
        if ($parameters === []) {
            throw new \RuntimeException(_t('FERME_CLI_EMPTY_CONFIG_FILE') . ' ' . $path);
        }

        return $parameters;
    }

    /**
     * Write the whole configuration back, keys left out of it removed.
     *
     * @param array<string, mixed> $config
     */
    public function write(string $wikiDir, array $config): void
    {
        $path = FarmConfig::configFileIn($wikiDir);
        $file = $this->configurationService->getConfiguration($path);
        $file->load();
        foreach (array_keys((array)$file->__get('_parameters')) as $key) {
            if (!array_key_exists($key, $config)) {
                $file->__unset((string)$key);
            }
        }
        foreach ($config as $key => $value) {
            $file[$key] = $value;
        }
        if ($file->write() === false) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_WRITE') . ' ' . $path);
        }
        $this->config->forgetWikiConfig(basename($wikiDir));
    }

    /**
     * Change a few keys of a wiki's configuration, and say what changed.
     *
     * @param array<string, mixed> $set
     * @param list<string>         $unset
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function patch(string $wikiDir, array $set, array $unset = []): array
    {
        $config = $this->load($wikiDir);
        $changes = $this->apply($config, $set, $unset);
        if ($changes !== []) {
            $this->write($wikiDir, $config);
        }

        return $changes;
    }

    /** A copy of a wiki's configuration in the farm's backups, before it is rewritten. */
    public function backup(string $wikiDir): string
    {
        $slug = trim(str_replace('/', '-', $wikiDir), '-');
        $path = $this->config->dataPath('configs/' . $slug . '/' . basename(FarmConfig::configFileIn($wikiDir)) . '.' . date('Ymd-His'));
        $this->storage->writeFrom($path, FarmConfig::configFileIn($wikiDir));

        return $path;
    }

    /**
     * Apply the wanted keys to a configuration in place, and return only the real changes.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $set
     * @param list<string>         $unset
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function apply(array &$config, array $set, array $unset = []): array
    {
        $changes = [];
        foreach ($set as $path => $value) {
            $had = self::hasPath($config, $path);
            $old = self::getPath($config, $path);
            if ($had && $old === $value) {
                continue;
            }
            $changes[$path] = ['old' => $had ? $old : '(missing)', 'new' => $value];
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
     * The mail settings `--smtp` writes in a wiki: the farm's own, locked in that wiki's settings screen.
     *
     * @param array<string, mixed> $wikiConfig
     *
     * @return array<string, mixed>
     */
    public function smtpChangesFor(array $wikiConfig): array
    {
        $smtp = $this->smtpFromFarm();
        $set = $smtp;
        $set[self::LOCKED_PARAMS] = self::lockParams($wikiConfig, array_keys($smtp));

        return $set;
    }

    /**
     * The farm's mail settings, refused when the farm does not send through SMTP.
     *
     * @return array<string, mixed>
     */
    public function smtpFromFarm(): array
    {
        $smtp = [];
        foreach (self::SMTP_KEYS as $key) {
            $value = $this->config->setting($key, '');
            if ($value !== '' && $value !== null) {
                $smtp[$key] = $value;
            }
        }
        if (($smtp['contact_mail_func'] ?? '') !== 'smtp' || ($smtp['contact_smtp_host'] ?? '') === '') {
            throw new \RuntimeException(_t('FERME_CLI_NO_SMTP_IN_MASTER'));
        }

        return $smtp;
    }

    /**
     * The locked settings of a wiki with these keys added.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     *
     * @return list<string>
     */
    public static function lockParams(array $config, array $keys): array
    {
        $locked = array_map('strval', (array)($config[self::LOCKED_PARAMS] ?? []));

        return array_values(array_unique(array_merge($locked, array_diff($keys, [self::LOCKED_PARAMS]))));
    }

    /** A command line value: a string, except true, false, null, int: and json:. */
    public static function cast(string $raw): mixed
    {
        return match (true) {
            $raw === 'true' => true,
            $raw === 'false' => false,
            $raw === 'null' => null,
            str_starts_with($raw, 'int:') => (int)substr($raw, 4),
            str_starts_with($raw, 'json:') => json_decode(substr($raw, 5), true, 512, JSON_THROW_ON_ERROR),
            default => $raw,
        };
    }

    /** A value for the terminal, passwords hidden. */
    public static function mask(string $key, mixed $value): string
    {
        if (preg_match('/pass|secret|token|key/i', $key) === 1 && is_string($value) && !in_array($value, ['', '(missing)', '(removed)'], true)) {
            return "'********'";
        }

        return is_scalar($value) || $value === null ? var_export($value, true) : (string)json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * A nested key written with dots, like yeswiki-farm-extra-config.contact_from.
     *
     * @param array<string, mixed> $config
     */
    public static function getPath(array $config, string $path): mixed
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

    /** @param array<string, mixed> $config */
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

    /** @param array<string, mixed> $config */
    public static function setPath(array &$config, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = (string)array_pop($segments);
        $current = &$config;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        $current[$last] = $value;
        unset($current);
    }

    /** @param array<string, mixed> $config */
    public static function unsetPath(array &$config, string $path): void
    {
        $segments = explode('.', $path);
        $last = (string)array_pop($segments);
        $current = &$config;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }
            $current = &$current[$segment];
        }
        unset($current[$last]);
        unset($current);
    }
}
