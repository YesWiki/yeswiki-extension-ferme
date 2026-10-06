<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Admin\Service\ArchiveService;
use YesWiki\Admin\Service\RemoteWikiArchive;
use YesWiki\Files\Service\LocalFiles;
use YesWiki\Files\Service\Storage;

/** The farm's models: a full archive of a wiki, taken by core and restored by core's installer into each wiki made from it. */
class ModelLibrary
{
    public const FOLDER = 'models';
    public const INFOS = 'infos.json';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly WikiArchiver $archiver,
        private readonly Storage $storage,
        private readonly LocalFiles $localFiles
    ) {
    }

    /**
     * Every model the farm holds, by name.
     *
     * @return array<string, array{name: string, label: string, source: string, createdAt: string, offered: bool, archive: string, bytes: int}>
     */
    public function all(): array
    {
        $models = [];
        $root = $this->config->dataPath(self::FOLDER);
        if (!$this->storage->directoryExists($root)) {
            return [];
        }
        foreach ($this->storage->directories($root) as $directory) {
            $name = basename($directory);
            $model = $this->describe($name);
            if ($model !== null) {
                $models[$name] = $model;
            }
        }
        ksort($models);

        return $models;
    }

    /** @return array<string, string> the choices a creator is offered, the blank wiki first */
    public function choices(): array
    {
        $choices = [FarmConfig::BLANK_MODEL => _t('FERME_BASIC_INSTALL')];
        foreach ($this->all() as $name => $model) {
            if ($model['offered']) {
                $choices[$name] = $model['label'];
            }
        }

        return $choices;
    }

    /** @return array{name: string, label: string, source: string, createdAt: string, offered: bool, archive: string, bytes: int}|null */
    public function describe(string $name): ?array
    {
        if (!FarmConfig::isSafeName($name)) {
            return null;
        }
        $archive = $this->archiveOf($name);
        if ($archive === null) {
            return null;
        }
        $infosPath = $this->path($name, self::INFOS);
        $infos = $this->storage->fileExists($infosPath) ? json_decode($this->storage->read($infosPath), true) : null;
        $infos = is_array($infos) ? $infos : [];

        return [
            'name' => $name,
            'label' => (string)($infos['label'] ?? $name),
            'source' => (string)($infos['source'] ?? ''),
            'createdAt' => (string)($infos['createdAt'] ?? ''),
            'offered' => (bool)($infos['offered'] ?? true),
            'archive' => basename($archive),
            'bytes' => $this->storage->fileSize($archive),
        ];
    }

    /** Make a model of a wiki of this farm: its own full archive, moved into the farm's models. */
    public function fromLocalWiki(string $folder, string $name, string $label): string
    {
        $name = $this->freeName($name);
        $made = $this->archiver->create($folder, false);
        $archive = $this->archiver->pathOf($folder, $made['file']);
        if ($archive === null) {
            throw new \RuntimeException(_t('FERME_ARCHIVE_NOT_FOUND'));
        }

        try {
            $this->storage->writeFrom($this->path($name, $made['file']), $archive);
        } finally {
            $this->localFiles->remove($archive);
        }
        $this->writeInfos($name, $label, $this->config->wikiUrl($folder), true);

        return $name;
    }

    /** Make a model of any wiki an administrator can reach, fetched the way `core:clone` fetches it. */
    public function fromRemoteWiki(string $url, string $username, string $password, string $name, string $label): string
    {
        $name = $this->freeName($name);
        $base = RemoteWikiArchive::baseUrlOf($url);
        $file = RemoteWikiArchive::nameForSource('archive.zip', $base);
        $downloaded = $this->localFiles->temporaryFile('ferme-model-');

        try {
            (new RemoteWikiArchive())->fetchInto($url, $username, $password, $downloaded);
            $this->storage->writeFrom($this->path($name, $file), $downloaded);
        } finally {
            $this->localFiles->remove($downloaded);
        }
        $this->writeInfos($name, $label, $base, true);

        return $name;
    }

    public function setLabel(string $name, string $label, ?bool $offered = null): void
    {
        $model = $this->describe($name) ?? throw new \RuntimeException(_t('FERME_INVALID_MODEL_NAME') . ' "' . $name . '"');
        $this->writeInfos($name, trim($label) !== '' ? $label : $model['label'], $model['source'], $offered ?? $model['offered'], $model['createdAt']);
    }

    public function delete(string $name): void
    {
        if ($this->describe($name) === null) {
            throw new \RuntimeException(_t('FERME_INVALID_MODEL_NAME') . ' "' . $name . '"');
        }
        $this->storage->deleteDirectory($this->config->dataPath(self::FOLDER . '/' . $name));
    }

    /**
     * Put a model's archive in a new wiki's backups, where its installer restores it from, and say its name.
     */
    public function placeInto(string $name, string $wikiDir): string
    {
        $model = $this->describe($name) ?? throw new \RuntimeException(_t('FERME_INVALID_MODEL_NAME') . ' "' . $name . '"');
        $target = rtrim($wikiDir, '/') . '/' . ArchiveService::PRIVATE_FOLDER_NAME_IN_ZIP;
        if (!$this->localFiles->makeDirectory($target)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $target);
        }
        $from = $this->storage->readStream($this->path($name, $model['archive']));
        $to = $this->localFiles->openForWriting($target . '/' . $model['archive']);
        if ($to === null) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_WRITE') . ' ' . $target);
        }

        try {
            if (stream_copy_to_stream($from, $to) === false) {
                throw new \RuntimeException(_t('FERME_CLI_CANNOT_WRITE') . ' ' . $target);
            }
        } finally {
            fclose($to);
            if (is_resource($from)) {
                fclose($from);
            }
        }

        return $model['archive'];
    }

    private function archiveOf(string $name): ?string
    {
        foreach ($this->storage->files($this->config->dataPath(self::FOLDER . '/' . $name)) as $path) {
            if (str_ends_with(strtolower($path), '.zip')) {
                return $path;
            }
        }

        return null;
    }

    private function freeName(string $name): string
    {
        $name = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
        if ($name === '' || $name === FarmConfig::BLANK_MODEL) {
            throw new \RuntimeException(_t('FERME_INVALID_MODEL_NAME') . ' "' . $name . '"');
        }
        if ($this->describe($name) !== null) {
            throw new \RuntimeException(_t('FERME_MODEL_EXISTS') . ' "' . $name . '"');
        }

        return $name;
    }

    private function writeInfos(string $name, string $label, string $source, bool $offered, string $createdAt = ''): void
    {
        $this->storage->write($this->path($name, self::INFOS), (string)json_encode([
            'label' => trim($label) !== '' ? trim($label) : $name,
            'source' => $source,
            'createdAt' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
            'offered' => $offered,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function path(string $name, string $file): string
    {
        return $this->config->dataPath(self::FOLDER . '/' . $name . '/' . $file);
    }
}
