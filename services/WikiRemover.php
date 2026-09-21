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
    protected $stats;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        FileSystem $files,
        EntryManager $entryManager,
        WikiStatsStore $stats
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->entryManager = $entryManager;
        $this->stats = $stats;
    }

    public function deleteForApi(string $idFiche): array
    {
        $folder = $this->resolveFolder($idFiche);
        if (!is_string($folder)) {
            return $folder;
        }

        $kept = $this->deleteWikiData($folder, $idFiche);

        try {
            $this->entryManager->delete($idFiche, true);
        } catch (\Throwable $th) {
            return ['success' => false, 'error' => 'Entry deletion failed: ' . $th->getMessage()];
        }

        return $kept === []
            ? ['success' => true]
            : ['success' => true, 'output' => _t('FERME_WIKI_KEPT_FOR') . ' ' . implode(', ', $kept)];
    }

    public function deleteFromEntry(string $idFiche): void
    {
        $folder = $this->resolveFolder($idFiche);
        if (is_string($folder)) {
            $this->deleteWikiData($folder, $idFiche);
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

        $folder = is_string($entry['bf_dossier-wiki']) ? $entry['bf_dossier-wiki'] : '';
        if (!FarmConfig::isSafeName($folder, true)) {
            return ['success' => false, 'error' => _t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"'];
        }

        return $folder;
    }

    /**
     * Take the wiki apart, unless another farm entry still points at it: a folder
     * two entries claim must survive the deletion of one of them, or removing a
     * duplicate entry would take the wiki with it.
     *
     * @return array<int,string> the other entries that kept this wiki alive
     */
    private function deleteWikiData(string $folder, string $idFiche): array
    {
        $claimedElsewhere = $this->otherEntriesClaiming($folder, $idFiche);
        if (!empty($claimedElsewhere)) {
            return $claimedElsewhere;
        }

        $this->stats->forget($folder);

        $dir = $this->config->wikiDir($folder);
        if (!is_dir($dir)) {
            return [];
        }

        $prefix = $this->config->readWikiConfig($folder)['table_prefix'] ?? '';

        $this->files->rrmdir($dir);

        if (empty($prefix)) {
            return [];
        }

        $tables = array_map(function ($table) use ($prefix) {
            return '`' . $prefix . $table . '`';
        }, WikiRepository::WIKI_TABLES);

        $this->wiki->Query('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ';');

        return [];
    }

    /**
     * @return array<int,string> the farm entries other than this one naming that folder
     */
    private function otherEntriesClaiming(string $folder, string $idFiche): array
    {
        $farmId = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');

        $others = [];
        foreach ($this->entryManager->search(['formsIds' => [$farmId]]) as $entry) {
            if ((string)($entry['bf_dossier-wiki'] ?? '') !== $folder) {
                continue;
            }
            if ((string)($entry['id_fiche'] ?? '') === $idFiche) {
                continue;
            }
            $others[] = (string)$entry['id_fiche'];
        }

        return $others;
    }
}
