<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Wiki;

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

    public function deleteFromEntry(string $idFiche): void
    {
        $folder = $this->resolveFolder($idFiche);
        if (is_string($folder)) {
            $this->deleteWikiData($folder);
        }
    }

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

    private function deleteWikiData(string $folder): void
    {
        $dir = $this->config->wikiDir($folder);
        if (!is_dir($dir)) {
            return;
        }

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
