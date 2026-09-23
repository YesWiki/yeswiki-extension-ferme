<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Wiki;

class WikiRemover
{
    public const SLOW_MS = 1000;
    public const LOG_FILE = 'private/logs/ferme-delete.log';

    protected $wiki;
    protected $config;
    protected $files;
    protected $entryManager;
    protected $stats;
    protected $mattermost;
    protected $lock;
    protected $plan;
    protected $hibernator;
    protected $timings = [];

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        FileSystem $files,
        EntryManager $entryManager,
        WikiStatsStore $stats,
        MattermostNotifier $mattermost,
        FolderLock $lock,
        WikiHibernator $hibernator
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->entryManager = $entryManager;
        $this->stats = $stats;
        $this->mattermost = $mattermost;
        $this->lock = $lock;
        $this->hibernator = $hibernator;
    }

    public function deleteForApi(string $idFiche): array
    {
        return $this->deleteMany([$idFiche])[0];
    }

    /** Delete several wikis in one go. */
    public function deleteMany(array $idFiches): array
    {
        $results = [];
        foreach ($idFiches as $idFiche) {
            $results[] = $this->deleteOne(trim((string)$idFiche));
        }

        return $results;
    }

    public function deleteFromEntry(string $idFiche): void
    {
        $this->deleteOne($idFiche);
    }

    /** Delete a wiki whose time ran out, entry and all, with no session behind the call. */
    public function expire(string $idFiche, string $folder): void
    {
        $entry = $this->entryManager->getOne($idFiche, false, null, false, true) ?? [];
        $kept = $folder === '' ? [] : $this->deleteWikiData($folder, $idFiche);
        $this->entryManager->delete($idFiche, true);
        $this->plan()->forget($idFiche);
        $this->mattermost->deleted($entry, $folder, $kept !== []);
    }

    /** Take a wiki's files and tables away and leave its entry, for an archived wiki. */
    public function removeWikiOnly(string $idFiche, string $folder): void
    {
        $this->deleteWikiData($folder, $idFiche);
    }

    /** Delete an entry whose wiki is already gone. */
    public function forgetEntry(string $idFiche): void
    {
        $this->entryManager->delete($idFiche, true);
        $this->plan()->forget($idFiche);
    }

    private function deleteOne(string $idFiche): array
    {
        $this->timings = [];
        $started = microtime(true);
        $folder = $this->resolveFolder($idFiche);
        if (!is_string($folder)) {
            return array_merge(['id_fiche' => $idFiche], $folder);
        }

        $entry = $this->entryManager->getOne($idFiche) ?? [];

        try {
            $kept = $this->deleteWikiData($folder, $idFiche);
        } catch (FolderBusyException $busy) {
            return ['id_fiche' => $idFiche, 'success' => false, 'error' => $busy->getMessage()];
        }

        try {
            $this->timed('entry', function () use ($idFiche) {
                $this->entryManager->delete($idFiche, true);
            });
        } catch (\Throwable $th) {
            return ['id_fiche' => $idFiche, 'success' => false, 'error' => 'Entry deletion failed: ' . $th->getMessage()];
        }

        $this->plan()->forget($idFiche);
        $this->mattermost->deleted($entry, $folder, $kept !== []);

        $timings = $this->report($folder, $started);

        return $kept === []
            ? ['id_fiche' => $idFiche, 'success' => true, 'timings' => $timings]
            : ['id_fiche' => $idFiche, 'success' => true, 'timings' => $timings, 'output' => _t('FERME_WIKI_KEPT_FOR') . ' ' . implode(', ', $kept)];
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

    /** Take the wiki apart, unless another farm entry still points at it: a folder two entries claim must survive the deletion of one of them, or removing a duplicate entry would take the wiki with it. */
    private function deleteWikiData(string $folder, string $idFiche): array
    {
        $claimedElsewhere = $this->otherEntriesClaiming($folder, $idFiche);
        if (!empty($claimedElsewhere)) {
            return $claimedElsewhere;
        }

        $dir = $this->config->wikiDir($folder);

        return $this->lock->during($dir, _t('FERME_LOCK_DELETE'), function () use ($folder, $dir) {
            $this->timed('stats', function () use ($folder) {
                $this->stats->forget($folder);
            });

            if (!is_dir($dir)) {
                return [];
            }

            $prefix = (string)($this->config->readWikiConfig($folder)['table_prefix'] ?? '');

            $this->timed('files', function () use ($dir) {
                $this->files->rrmdir($dir);
            });

            if ($prefix === '') {
                return [];
            }

            $tables = array_map(function ($table) use ($prefix) {
                return '`' . $prefix . $table . '`';
            }, WikiRepository::WIKI_TABLES);

            $this->timed('tables', function () use ($tables) {
                $this->wiki->Query('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ';');
            });

            return [];
        });
    }

    /** A wiki put to sleep can still be deleted: hibernation keeps a wiki from being changed, and disposing of it is the other thing one wants to do with it. */
    public function isAsleep(string $folder): bool
    {
        return WikiHibernator::isAsleep(trim((string)($this->config->readWikiConfig($folder)['wiki_status'] ?? '')));
    }

    public function otherEntriesClaiming(string $folder, string $idFiche): array
    {
        return $this->plan()->others($folder, $idFiche);
    }

    /** The farm's entries, read once and kept for the rest of the request. */
    private function plan(): DeletionPlan
    {
        if ($this->plan === null) {
            $this->timed('claims', function () {
                $farmId = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');
                $this->plan = new DeletionPlan();
                $this->plan->index($this->entryManager->search(['formsIds' => [$farmId]]));
            });
        }

        return $this->plan;
    }

    private function timed(string $phase, callable $work)
    {
        $started = microtime(true);

        try {
            return $work();
        } finally {
            $this->timings[$phase] = (int)round((microtime(true) - $started) * 1000);
        }
    }

    /** Where a deletion spent its time, and a line in the log for the slow ones: on a farm of thousands, the difference between a minute and an hour is one phase. */
    private function report(string $folder, float $started): array
    {
        $timings = $this->timings;
        $timings['total'] = (int)round((microtime(true) - $started) * 1000);

        if ($timings['total'] >= self::SLOW_MS && $this->logDir() !== null) {
            $parts = [];
            foreach ($timings as $phase => $ms) {
                $parts[] = $phase . ' ' . $ms;
            }
            @file_put_contents(
                self::LOG_FILE,
                date('c') . ' ' . $folder . ' ' . implode(' ', $parts) . PHP_EOL,
                FILE_APPEND
            );
        }

        return $timings;
    }

    private function logDir(): ?string
    {
        $dir = dirname(self::LOG_FILE);
        if (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) {
            return $dir;
        }

        return null;
    }
}
