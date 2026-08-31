<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Wiki;

/**
 * Deletes a wiki: its folder on disk, its tables in the database, and optionally the
 * bazar entry that described it.
 */
class WikiRemover
{
    protected $wiki;
    protected $config;
    protected $files;
    protected $entryManager;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        FileSystem $files,
        EntryManager $entryManager
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->entryManager = $entryManager;
    }

    /**
     * Delete a wiki and the entry that described it.
     * CSRF must be verified by the caller before invoking this method.
     *
     * @return array {success: bool, error?: string}
     */
    public function deleteForApi(string $idFiche): array
    {
        $folder = $this->resolveFolder($idFiche);
        if (!is_string($folder)) {
            return $folder;
        }

        $this->deleteWikiData($folder);

        try {
            $this->entryManager->delete($idFiche, true);
        } catch (\Throwable $th) {
            return ['success' => false, 'error' => 'Entry deletion failed: ' . $th->getMessage()];
        }

        return ['success' => true];
    }

    /**
     * Delete a wiki's files and DB tables from an entry page tag, leaving the entry alone.
     * CSRF must be verified by the caller before invoking this method.
     */
    public function deleteFromEntry(string $idFiche): void
    {
        $folder = $this->resolveFolder($idFiche);
        if (is_string($folder)) {
            $this->deleteWikiData($folder);
        }
    }

    /**
     * @return string|array the wiki folder, or the error payload explaining why not
     */
    private function resolveFolder(string $idFiche)
    {
        if (!$this->wiki->UserIsAdmin() && !$this->wiki->UserIsOwner()) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        if (!$this->entryManager->isEntry($idFiche)) {
            return ['success' => false, 'error' => 'Entry not found: ' . $idFiche];
        }

        $entry = $this->entryManager->getOne($idFiche);
        if (empty($entry['bf_dossier-wiki'])) {
            return ['success' => false, 'error' => 'Wiki folder not set for entry: ' . $idFiche];
        }

        return $entry['bf_dossier-wiki'];
    }

    /**
     * Delete the wiki folder from disk and drop its database tables. The tables only go
     * once the folder is confirmed gone, so a bad folder name cannot drop a live wiki.
     */
    private function deleteWikiData(string $folder): void
    {
        $dir = $this->config->wikiDir($folder);
        if (!is_dir($dir)) {
            return;
        }

        // read the config before the folder holding it goes away
        $prefix = $this->config->readWikiConfig($folder)['table_prefix'] ?? '';

        $this->files->rrmdir($dir);

        if (empty($prefix)) {
            return;
        }

        $tables = array_map(function ($table) use ($prefix) {
            return '`' . $prefix . $table . '`';
        }, WikiRepository::WIKI_TABLES);

        $this->wiki->Query('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ';');
    }
}
