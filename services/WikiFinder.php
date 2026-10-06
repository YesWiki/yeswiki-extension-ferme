<?php

namespace YesWiki\Ferme\Service;

/** Finds the wikis installed under the farm's folder, the farm wiki itself left out. */
class WikiFinder
{
    public function __construct(private readonly FarmConfig $config)
    {
    }

    /**
     * @return list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}>
     */
    public function find(?string $path = null, int $depth = 1): array
    {
        $root = $this->normalize($path ?? $this->config->rootFolder());
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException(_t('FERME_CLI_NOT_A_DIRECTORY') . ' ' . ($path ?? $this->config->rootFolder()));
        }

        $farm = $this->masterRoot();
        $excluded = $this->excludedDirs();
        $directories = new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator($directories, static function (\SplFileInfo $current) use ($excluded): bool {
            if (!$current->isDir()) {
                return true;
            }
            $dir = rtrim($current->getPathname(), '/') . '/';
            foreach ($excluded as $excludedDir) {
                if (str_starts_with($dir, $excludedDir)) {
                    return false;
                }
            }

            return true;
        });
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        $iterator->setMaxDepth($depth);

        $wikis = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !in_array($file->getBasename(), FarmConfig::CONFIG_FILES, true)) {
                continue;
            }
            $wikiDir = dirname($file->getPathname());
            if ($wikiDir === $farm || isset($wikis[$wikiDir])) {
                continue;
            }
            $wikis[$wikiDir] = $this->describe($wikiDir, $root);
        }

        $wikis = array_values($wikis);
        usort($wikis, static fn (array $a, array $b): int => strcasecmp($a['PATH'], $b['PATH']));

        return $wikis;
    }

    /** @return array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string} */
    public function findOne(string $folder): array
    {
        $wikiDir = $this->normalize($this->config->wikiDir($folder));
        if ($wikiDir === $this->masterRoot()) {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED'));
        }
        if (!is_file(FarmConfig::configFileIn($wikiDir))) {
            throw new \RuntimeException(_t('FERME_NO_WIKI_IN') . ' ' . $wikiDir);
        }

        return $this->describe($wikiDir, $this->config->rootFolder());
    }

    public function masterRoot(): string
    {
        return $this->normalize($this->config->instanceDir());
    }

    /**
     * The few keys a listing needs, read without running the file.
     *
     * @return array<string, string>
     */
    public static function extractConfig(string $filePath): array
    {
        $content = is_file($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            return [];
        }

        $config = [];
        foreach (['base_url', 'yeswiki_version', 'yeswiki_release', 'root_page', 'yeswiki_name', 'wakka_name', 'wiki_status'] as $key) {
            if (preg_match('/[\'"]' . $key . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i', $content, $matches) === 1) {
                $config[$key] = $matches[1];
            }
        }

        return $config;
    }

    /** @return array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string} */
    private function describe(string $wikiDir, string $root): array
    {
        $config = self::extractConfig(FarmConfig::configFileIn($wikiDir));
        $folder = str_starts_with($wikiDir, $root . '/') ? substr($wikiDir, strlen($root) + 1) : basename($wikiDir);

        return [
            'PATH' => $wikiDir,
            'FOLDER' => $folder,
            'URL' => $config['base_url'] ?? 'KO',
            'VERSION' => $config['yeswiki_version'] ?? 'KO',
            'RELEASE' => $config['yeswiki_release'] ?? 'KO',
        ];
    }

    /**
     * The farm wiki's own folders, which never hold another wiki.
     *
     * @return list<string>
     */
    private function excludedDirs(): array
    {
        $farm = $this->masterRoot();
        $excluded = [];
        foreach (FarmConfig::RESERVED_FOLDERS as $folder) {
            $excluded[] = $farm . '/' . $folder . '/';
        }

        return $excluded;
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim($real === false ? $path : $real, '/');
    }
}
