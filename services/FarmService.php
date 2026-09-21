<?php

namespace YesWiki\Ferme\Service;

class FarmService
{
    protected $config;
    protected $files;
    protected $adminAccount;
    protected $creator;
    protected $updater;
    protected $remover;
    protected $repository;
    protected $modelAssets;
    protected $aside;
    protected $statsService;
    protected $statsStore;
    protected $hibernator;

    public function __construct(
        FarmConfig $config,
        FileSystem $files,
        FarmAdminAccount $adminAccount,
        WikiCreator $creator,
        WikiUpdater $updater,
        WikiRemover $remover,
        WikiRepository $repository,
        ModelAssets $modelAssets,
        CustomAside $aside,
        WikiStats $statsService,
        WikiStatsStore $statsStore,
        WikiHibernator $hibernator
    ) {
        $this->config = $config;
        $this->files = $files;
        $this->adminAccount = $adminAccount;
        $this->creator = $creator;
        $this->updater = $updater;
        $this->remover = $remover;
        $this->repository = $repository;
        $this->modelAssets = $modelAssets;
        $this->aside = $aside;
        $this->statsService = $statsService;
        $this->statsStore = $statsStore;
        $this->hibernator = $hibernator;
    }

    public function initFarmConfig()
    {
        $this->config->init();
    }

    public function getWikiConfig($wiki)
    {
        return $this->config->readWikiConfig($wiki);
    }

    public function startModelAssets(string $model, string $baseUrl, array $credentials = []): array
    {
        return $this->modelAssets->start($model, $baseUrl, $credentials);
    }

    public function advanceModelAssets(): array
    {
        return $this->modelAssets->advance();
    }

    public function cancelModelAssets(): array
    {
        return $this->modelAssets->cancel();
    }

    public function runningModelAssets(): ?string
    {
        return $this->modelAssets->runningModel();
    }

    public function getModelLabels()
    {
        return $this->config->getModelLabels();
    }

    public function getWikiPath(string $folder): string
    {
        return $this->config->wikiDir($folder);
    }

    public function addFarmAdmin($wiki)
    {
        return $this->adminAccount->add($wiki);
    }

    public function removeFarmAdmin($wiki)
    {
        return $this->adminAccount->remove($wiki);
    }

    public function createWikiFromEntry($entry, $fieldName, string $theme = '0', string $model = 'default-content')
    {
        $this->creator->createFromEntry($entry, $fieldName, $theme, $model);
    }

    public function updateWiki($wiki, array $options = [])
    {
        return $this->updater->update($this->config->wikiDir($wiki), $options);
    }

    public function updateWikiExtensions($wiki, array $options = [])
    {
        return $this->updater->updateExtensions($this->config->wikiDir($wiki), $options);
    }

    /**
     * @return array{status:string,messages:array<int,string>}
     */
    public function refreshWikiStats($wiki): array
    {
        $folder = (string)$wiki;
        $stats = $this->statsService->compute($folder);
        $stats['filesMtime'] = $this->statsService->diskProbe($folder);
        $this->statsStore->save($folder, $stats);

        return ['status' => 'measured', 'messages' => [_t('FERME_STATS_REFRESHED', [
            'entries' => $stats['entries'],
            'pages' => $stats['pages'],
            'users' => $stats['users'],
        ])]];
    }

    /**
     * @return array<string,int> edits per day over the last year
     */
    public function wikiActivity($wiki): array
    {
        return $this->statsService->daily((string)$wiki);
    }

    /**
     * @return array{status:string,messages:array<int,string>}
     */
    public function recoverWikiCustom($wiki): array
    {
        $recovered = $this->aside->recover($this->config->wikiDir($wiki));

        return $recovered === null
            ? ['status' => 'nothing', 'messages' => [_t('FERME_CUSTOM_NOTHING_TO_RECOVER')]]
            : ['status' => 'recovered', 'messages' => [$recovered]];
    }

    public function deleteWikiForApi(string $idFiche): array
    {
        return $this->remover->deleteForApi($idFiche);
    }

    /**
     * @param array<int,string> $idFiches
     *
     * @return array<int,array<string,mixed>>
     */
    public function deleteWikisForApi(array $idFiches): array
    {
        return $this->remover->deleteMany($idFiches);
    }

    /**
     * @return array{changed:bool,status:string,before:string}
     */
    public function hibernateWiki(string $folder): array
    {
        return $this->hibernator->hibernate($folder);
    }

    /**
     * @return array{changed:bool,status:string,before:string}
     */
    public function wakeWiki(string $folder): array
    {
        return $this->hibernator->wake($folder);
    }

    /**
     * @return array<int,string> the other farm entries pointing at that wiki
     */
    public function entriesClaiming(string $folder, string $idFiche): array
    {
        return $this->remover->otherEntriesClaiming($folder, $idFiche);
    }

    public function deleteWikiFromEntry($id)
    {
        $this->remover->deleteFromEntry($id);
    }

    public function getWikiList()
    {
        return $this->repository->getAll();
    }

    /**
     * @return array{wikis:array<int,array<string,string>>,total:int}
     */
    public function wikisForSelection(string $search, string $filter = ''): array
    {
        return $this->repository->listForSelection($search, $filter);
    }

    public function getWikiListPaginated(
        int $start,
        int $length,
        string $search,
        string $sort,
        string $direction,
        string $filter = ''
    ): array {
        return $this->repository->getPaginated($start, $length, $search, $sort, $direction, $filter);
    }

    public function searchWikisOnServer(): array
    {
        return $this->repository->searchOnServer();
    }

    /**
     * @param array<int,string> $folders
     *
     * @return array<int,string>
     */
    public function importWikiFolders(array $folders, string $fallbackEmail = ''): array
    {
        return $this->repository->importFolders($folders, $fallbackEmail);
    }

    public function rrmdir($src)
    {
        $this->files->rrmdir($src);
    }

    public function copyRecursive($path, $dest)
    {
        return $this->files->copyRecursive($path, $dest);
    }

    public function getAbsolutePath($path)
    {
        return $this->files->getAbsolutePath($path);
    }

    public function querySqlFile($dblink, $sqlFile, $replacements = [])
    {
        return $this->creator->runSqlFile($dblink, $sqlFile, $replacements);
    }
}
