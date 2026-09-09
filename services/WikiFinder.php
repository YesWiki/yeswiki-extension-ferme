<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Find YesWiki installations on disk.
 *
 * Walks a path looking for wakka.config.php and reads base_url, version and
 * release with a regexp rather than including the file, which is what makes a
 * scan of a few hundred wikis fast.
 *
 * The farm master is never returned: copying its own tree onto itself while it
 * runs is not recoverable.
 */
class WikiFinder
{
    public const CONFIG_FILE = 'wakka.config.php';

    /** folders of the farm master that can never hold a child wiki */
    private const MASTER_SUBFOLDERS = [
        'actions', 'cache', 'custom', 'docs', 'docker', 'files', 'formatters',
        'handlers', 'includes', 'javascripts', 'lang', 'node_modules', 'private',
        'setup', 'styles', 'templates', 'tests', 'themes', 'tools', 'vendor',
    ];

    protected $wiki;
    protected $config;

    public function __construct(Wiki $wiki, FarmConfig $config)
    {
        $this->wiki = $wiki;
        $this->config = $config;
    }

    /**
     * @return array<int,array{PATH:string,FOLDER:string,URL:string,VERSION:string,RELEASE:string}>
     */
    public function find(?string $path = null, int $depth = 1): array
    {
        $root = $this->normalize($path ?: $this->config->basePath());
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException(_t('FERME_CLI_NOT_A_DIRECTORY') . ' ' . ($path ?: $this->config->basePath()));
        }

        $excluded = $this->excludedDirs();
        $masterRoot = $this->masterRoot();

        $directories = new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator($directories, function ($current) use ($excluded) {
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
        // CATCH_GET_CHILD skips the folders we may not read instead of aborting the scan
        $iterator = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $iterator->setMaxDepth($depth);

        $wikis = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getBasename() !== self::CONFIG_FILE) {
                continue;
            }
            $wikiDir = dirname($file->getPathname());
            if ($wikiDir === $masterRoot) {
                continue;
            }
            $wikis[] = $this->describe($wikiDir);
        }

        usort($wikis, function ($a, $b) {
            return strcasecmp($a['PATH'], $b['PATH']);
        });

        return $wikis;
    }

    /**
     * One wiki, by its folder name under the farm root.
     */
    public function findOne(string $folder): array
    {
        $wikiDir = $this->normalize($this->config->wikiDir($folder));
        if ($wikiDir === $this->masterRoot()) {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED'));
        }
        if (!is_file($wikiDir . '/' . self::CONFIG_FILE)) {
            throw new \RuntimeException(_t('FERME_CLI_NO_WIKI_IN') . ' ' . $wikiDir);
        }

        return $this->describe($wikiDir);
    }

    /**
     * Absolute path of the farm master, without trailing slash.
     */
    public function masterRoot(): string
    {
        return $this->normalize(getcwd());
    }

    /**
     * Read the few keys a listing needs, without including the file.
     */
    public static function extractConfig(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $config = [];
        foreach (['base_url', 'yeswiki_version', 'yeswiki_release', 'root_page', 'wakka_name'] as $key) {
            if (preg_match('/[\'"]' . $key . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i', $content, $matches)) {
                $config[$key] = $matches[1];
            }
        }

        return $config;
    }

    private function describe(string $wikiDir): array
    {
        $config = self::extractConfig($wikiDir . '/' . self::CONFIG_FILE);

        return [
            'PATH' => $wikiDir,
            'FOLDER' => basename($wikiDir),
            'URL' => $config['base_url'] ?? 'KO',
            'VERSION' => $config['yeswiki_version'] ?? 'KO',
            'RELEASE' => $config['yeswiki_release'] ?? 'KO',
        ];
    }

    /**
     * Directories a scan must never enter: the backup folder, because it holds
     * copies of wakka.config.php that would be taken for wikis, and the farm
     * master's own subfolders.
     */
    private function excludedDirs(): array
    {
        $excluded = [];

        $backupDir = $this->normalize($this->config->backupDir());
        if ($backupDir !== '') {
            $excluded[] = $backupDir . '/';
        }

        $masterRoot = $this->masterRoot();
        foreach (self::MASTER_SUBFOLDERS as $folder) {
            $excluded[] = $masterRoot . '/' . $folder . '/';
        }

        return $excluded;
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);

        return $real === false ? rtrim($path, '/') : rtrim($real, '/');
    }
}
