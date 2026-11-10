<?php

// What the extension repository publishes for a YesWiki version, against what a wiki has on disk.

namespace YesWiki\Ferme\Service;

use Symfony\Component\HttpClient\HttpClient;
use YesWiki\Wiki;

class ExtensionVersions
{
    public const DEFAULT_REPOSITORY = 'https://repository.yeswiki.net/';
    private const INDEX = 'packages.json';
    private const PREFIX = 'extension-';
    private const DATED = '/^\d{4}-\d{2}-\d{2}(-\d+)?$/';

    private $wiki;
    private $indexes = [];

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
    }

    // the extensions behind the published release, or all of them when the wiki changes version
    public function toUpgrade(string $wikiDir, array $extensions, string $targetVersion, string $currentVersion = ''): array
    {
        if (empty($extensions)) {
            return [];
        }

        $published = $this->index($targetVersion);
        $sameVersion = strtolower($currentVersion) === strtolower($targetVersion);
        $todo = [];

        foreach ($extensions as $extension) {
            $latest = $published[$extension] ?? null;
            if ($latest === null) {
                continue;
            }
            if (!$sameVersion) {
                $todo[] = $extension;
                continue;
            }

            $installed = $this->installedRelease($wikiDir, $extension);
            if ($installed === null || $this->isBehind($installed, $latest)) {
                $todo[] = $extension;
            }
        }

        return $todo;
    }

    // the extensions the target version publishes nothing for, carried over as they are
    public function unpublished(array $extensions, string $targetVersion): array
    {
        if (empty($extensions)) {
            return [];
        }

        $published = $this->index($targetVersion);

        return array_values(array_filter($extensions, function (string $extension) use ($published) {
            return !isset($published[$extension]);
        }));
    }

    // the release an extension writes next to itself when the wiki installs it
    public function installedRelease(string $wikiDir, string $extension): ?string
    {
        $file = rtrim($wikiDir, DIRECTORY_SEPARATOR) . '/tools/' . $extension . '/infos.json';
        if (!is_file($file)) {
            return null;
        }

        $infos = json_decode((string)file_get_contents($file), true);
        $release = is_array($infos) ? ($infos['release'] ?? null) : null;

        return is_string($release) && $release !== '' ? $release : null;
    }

    // a dated release like 2025-02-04-1 predates every numbered one, whatever version_compare says
    private function isBehind(string $installed, string $latest): bool
    {
        $installedDated = preg_match(self::DATED, $installed) === 1;
        $latestDated = preg_match(self::DATED, $latest) === 1;
        if ($installedDated !== $latestDated) {
            return $installedDated;
        }

        return version_compare($installed, $latest, '<');
    }

    // extension name => latest release published for that version
    protected function index(string $targetVersion): array
    {
        $url = $this->repositoryUrl($targetVersion);
        if (isset($this->indexes[$url])) {
            return $this->indexes[$url];
        }

        try {
            $body = HttpClient::create()->request('GET', $url)->getContent();
        } catch (\Throwable $th) {
            throw new \RuntimeException(_t('FERME_CLI_EXT_NO_REPOSITORY') . ' ' . $url . ': ' . $th->getMessage());
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(_t('FERME_CLI_EXT_NO_REPOSITORY') . ' ' . $url);
        }

        $releases = [];
        foreach ($data as $key => $package) {
            if (!is_array($package) || !str_starts_with((string)$key, self::PREFIX)) {
                continue;
            }
            $releases[substr((string)$key, strlen(self::PREFIX))] = (string)($package['version'] ?? '');
        }

        return $this->indexes[$url] = $releases;
    }

    private function repositoryUrl(string $targetVersion): string
    {
        $base = trim((string)($this->wiki->config['yeswiki_repository'] ?? self::DEFAULT_REPOSITORY));
        if ($base === '') {
            $base = self::DEFAULT_REPOSITORY;
        }

        return rtrim($base, '/') . '/' . strtolower($targetVersion) . '/' . self::INDEX;
    }
}
