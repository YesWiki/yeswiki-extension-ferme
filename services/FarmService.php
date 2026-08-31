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

    public function __construct(
        FarmConfig $config,
        FileSystem $files,
        FarmAdminAccount $adminAccount,
        WikiCreator $creator,
        WikiUpdater $updater,
        WikiRemover $remover,
        WikiRepository $repository
    ) {
        $this->config = $config;
        $this->files = $files;
        $this->adminAccount = $adminAccount;
        $this->creator = $creator;
        $this->updater = $updater;
        $this->remover = $remover;
        $this->repository = $repository;
    }

    public function initFarmConfig()
    {
        $this->config->init();
    }

    public function getWikiConfig($wiki)
    {
        return $this->config->readWikiConfig($wiki);
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

    public function updateWiki($wiki)
    {
        return $this->updater->update($wiki);
    }

    public function deleteWikiForApi(string $idFiche): array
    {
        return $this->remover->deleteForApi($idFiche);
    }

    public function deleteWikiFromEntry($id)
    {
        $this->remover->deleteFromEntry($id);
    }

    public function getWikiList()
    {
        return $this->repository->getAll();
    }

    public function getWikiListPaginated(int $start, int $length, string $search, int $orderCol, string $orderDir): array
    {
        return $this->repository->getPaginated($start, $length, $search, $orderCol, $orderDir);
    }

    public function searchWikisOnServer(string $adminMail, bool $checkHttp = true): array
    {
        return $this->repository->searchOnServer($adminMail, $checkHttp);
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
