<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

class WikiUpdater
{
    private const REMOVED_TOOLS = ['tools/despam', 'tools/hashcash', 'tools/ipblock', 'tools/nospam'];

    protected $wiki;
    protected $config;
    protected $files;
    protected $yeswicli;

    public function __construct(Wiki $wiki, FarmConfig $config, FileSystem $files, Yeswicli $yeswicli)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->yeswicli = $yeswicli;
    }

    public function update(string $folder): string
    {
        $srcfolder = getcwd() . DIRECTORY_SEPARATOR;
        $destfolder = $this->config->wikiDir($folder);

        $output = '<div class="alert alert-info">' . _t('FERME_UPDATING') . $folder . '.</div>';

        foreach (self::REMOVED_TOOLS as $folderToDelete) {
            if (is_dir($destfolder . $folderToDelete)) {
                $this->files->rrmdir($destfolder . $folderToDelete);
            }
        }

        $symlinked = $this->wiki->config['yeswiki_symlinked_files'];

        foreach ($this->wiki->config['yeswiki_files'] as $file) {
            if (!in_array($file, $symlinked)) {
                $this->replace($srcfolder, $destfolder, $file);
            }
        }

        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] as $file) {
            $file = 'tools/' . $file;
            if (!in_array($file, $symlinked)) {
                $this->replace($srcfolder, $destfolder, $file);
            }
        }

        foreach ($symlinked as $file) {
            $this->clear($destfolder, $file);
            symlink($srcfolder . $file, $destfolder . $file);
        }

        $this->stampVersion($destfolder);
        $this->yeswicli->migrate($destfolder);

        return $output . '<div class="alert alert-success">' . _t('FERME_WIKI') . $folder . _t('FERME_UPDATED') . '</div>';
    }

    private function replace(string $srcfolder, string $destfolder, string $file): void
    {
        $this->clear($destfolder, $file);
        $this->files->copyRecursive($srcfolder . $file, $destfolder . $file);
    }

    private function clear(string $destfolder, string $file): void
    {
        if (
            file_exists($destfolder . $file)
            && !in_array($file, $this->wiki->config['yeswiki_empty_folders'])
        ) {
            $this->files->rrmdir($destfolder . $file);
        }
    }

    private function stampVersion(string $destfolder): void
    {
        include_once 'tools/templates/libs/Configuration.php';
        $config = new \Configuration($destfolder . 'wakka.config.php');
        $config->load();
        $config->yeswiki_version = $this->wiki->config['yeswiki_version'];
        $config->yeswiki_release = $this->wiki->config['yeswiki_release'];
        $config->write();
    }
}
