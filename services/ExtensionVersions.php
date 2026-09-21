<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\HttpClient\HttpClient;
use YesWiki\Wiki;

/**
 * What the extension repository holds for a YesWiki version, against what a wiki
 * has on disk. A wiki is only updated once its own extensions are at the release
 * published for the version it is moving to.
 */
class ExtensionVersions
{
    public const DEFAULT_REPOSITORY = 'https://repository.yeswiki.net/';
    private const INDEX = 'packages.json';
    private const PREFIX = 'extension-';

    private $wiki;
    private $indexes = [];

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
    }

    /**
     * The extensions to bring up before the migrations run. A wiki staying on its
     * version only needs the ones behind the published release; one changing version
     * needs them all, since the release it runs was built for another core.
     *
     * @param array<int,string> $extensions
     *
     * @return array<int,string>
     */
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
            if ($installed === null || version_compare($installed, $latest, '<')) {
                $todo[] = $extension;
            }
        }

        return $todo;
    }

    /**
     * The extensions the target version has nothing to offer for, which the update
     * carries over as they are.
     *
     * @param array<int,string> $extensions
     *
     * @return array<int,string>
     */
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

    /**
     * The release an extension writes next to itself when the wiki installs it.
     */
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

    /**
     * @return array<string,string> extension name => latest published release
     */
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
