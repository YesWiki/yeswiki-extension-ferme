<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Brings a hosted wiki back in line with the farm's own YesWiki: recopies the source
 * files, stamps the new version into its config, then runs its migrations.
 */
class WikiUpdater
{
    /** tools that used to ship with YesWiki and are now gone. TODO make a migration */
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

    /**
     * @return string HTML report shown to the admin
     */
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

        // mise a jour des fichiers de YesWiki qui ne sont pas des symlink
        foreach ($this->wiki->config['yeswiki_files'] as $file) {
            if (!in_array($file, $symlinked)) {
                $this->replace($srcfolder, $destfolder, $file);
            }
        }

        // mise a jour des extensions de YesWiki de la configuration qui ne sont pas des symlink
        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] as $file) {
            $file = 'tools/' . $file;
            if (!in_array($file, $symlinked)) {
                $this->replace($srcfolder, $destfolder, $file);
            }
        }

        // mise a jour des fichiers de YesWiki qui sont des symlink
        foreach ($symlinked as $file) {
            $this->clear($destfolder, $file);
            symlink($srcfolder . $file, $destfolder . $file);
        }

        $this->stampVersion($destfolder);
        $this->yeswicli->migrate($destfolder);

        return $output . '<div class="alert alert-success">' . _t('FERME_WIKI') . $folder . _t('FERME_UPDATED') . '</div>';
    }

    /**
     * Drop the wiki's copy of a file and put the farm's own in its place.
     */
    private function replace(string $srcfolder, string $destfolder, string $file): void
    {
        $this->clear($destfolder, $file);
        $this->files->copyRecursive($srcfolder . $file, $destfolder . $file);
    }

    /**
     * Remove the wiki's copy of a file, unless it is one of the folders that belong to
     * the wiki itself (cache, custom, files, private) and must survive the update.
     */
    private function clear(string $destfolder, string $file): void
    {
        if (
            file_exists($destfolder . $file)
            && !in_array($file, $this->wiki->config['yeswiki_empty_folders'])
        ) {
            $this->files->rrmdir($destfolder . $file);
        }
    }

    /**
     * Write the farm's YesWiki version into the wiki's own wakka.config.php.
     */
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
