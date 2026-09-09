<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\ArchiveService;
use YesWiki\Core\Service\RemoteBackupService;
use YesWiki\Wiki;

/**
 * A wiki model is a SQL dump plus the whole content of its source: the files folder
 * with the originals, not the sizes a page happened to display, and the custom folder
 * that styles them. This fills custom/wiki-models/<model>/files and .../custom, where
 * WikiCreator already looks for them when it builds a wiki.
 *
 * A source hosted on this server is copied from disk. A source elsewhere is asked for
 * a backup of those two folders and nothing else, which needs an administrator account
 * on it and no new API route on either side.
 */
class ModelAssets
{
    public const MODELS_DIR = 'custom/wiki-models';

    /** The two folders a model takes from its source, whole. */
    public const CONTENT_FOLDERS = ['custom', 'files'];

    /**
     * Left out of what is copied: the farm's own models, because a model of a farm
     * master would nest every other model inside itself, and the caches.
     */
    private const CUSTOM_SKIPPED = ['wiki-models', 'cache'];

    private const POLL_SECONDS = 2;
    private const FETCH_MAX_SECONDS = 1800;

    protected $wiki;
    protected $config;
    protected $files;
    protected $archiveService;
    protected $remoteBackup;

    public function __construct(
        Wiki $wiki,
        FarmConfig $config,
        FileSystem $files,
        ArchiveService $archiveService,
        RemoteBackupService $remoteBackup
    ) {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->archiveService = $archiveService;
        $this->remoteBackup = $remoteBackup;
    }

    /**
     * Fill a model folder with the files and the custom folder of its source.
     *
     * @param string $model       folder name under custom/wiki-models
     * @param string $baseUrl     source wiki, without trailing slash
     * @param array  $credentials 'username' and 'password' of an administrator of the
     *                            source, needed only when it is on another server
     *
     * @return array report lines
     */
    public function collect(string $model, string $baseUrl, array $credentials = []): array
    {
        $target = self::MODELS_DIR . '/' . $model;
        foreach (self::CONTENT_FOLDERS as $dir) {
            $this->files->remove($target . '/' . $dir);
        }

        $localPath = $this->localWikiPath($baseUrl);
        if (!is_null($localPath)) {
            return $this->copyFromDisk($localPath, $target);
        }

        return $this->fetchArchive($baseUrl, $target, $credentials);
    }

    /**
     * Where the source wiki lives on this server, or null when it is elsewhere.
     */
    public function localWikiPath(string $baseUrl): ?string
    {
        $baseUrl = $this->normalizeUrl($baseUrl);
        if ($baseUrl === '') {
            return null;
        }

        if ($baseUrl === $this->normalizeUrl($this->wiki->config['base_url'] ?? '')) {
            return rtrim(getcwd(), DIRECTORY_SEPARATOR);
        }

        $rootUrl = $this->normalizeUrl($this->config->rootUrl());
        if ($rootUrl === '' || strpos($baseUrl . '/', $rootUrl . '/') !== 0) {
            return null;
        }

        $folder = trim(substr($baseUrl, strlen($rootUrl)), '/');
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return null;
        }

        $path = rtrim($this->config->wikiDir($folder), DIRECTORY_SEPARATOR);

        return is_file($path . DIRECTORY_SEPARATOR . 'wakka.config.php') ? $path : null;
    }

    private function copyFromDisk(string $source, string $target): array
    {
        $messages = [];

        if (is_dir($source . '/files')) {
            $this->files->copyRecursive($source . '/files', $target . '/files');
            $messages[] = _t('FERME_MODEL_FILES_COPIED') . ' ' . $this->countFiles($target . '/files');
        }

        if (is_dir($source . '/custom')) {
            @mkdir($target . '/custom', 0777, true);
            foreach (scandir($source . '/custom') as $entry) {
                if (in_array($entry, ['.', '..'], true) || in_array($entry, self::CUSTOM_SKIPPED, true)) {
                    continue;
                }
                $this->files->copyRecursive($source . '/custom/' . $entry, $target . '/custom/' . $entry);
            }
            $messages[] = _t('FERME_MODEL_CUSTOM_COPIED') . ' ' . $this->countFiles($target . '/custom');
        }

        return empty($messages) ? [_t('FERME_MODEL_NO_ASSETS')] : $messages;
    }

    /**
     * Nothing lists the files of a remote wiki, and reading its pages would only find
     * the images they display, at the size they display them. So the source makes a
     * backup of its custom and files folders, and this unpacks it into the model.
     */
    private function fetchArchive(string $baseUrl, string $target, array $credentials): array
    {
        $username = trim((string)($credentials['username'] ?? ''));
        $password = (string)($credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            return [_t('FERME_MODEL_REMOTE_NEEDS_ADMIN')];
        }

        $zipPath = $this->downloadArchive($baseUrl, $username, $password);
        try {
            $extracted = $this->extractContent($zipPath, $target);
        } finally {
            @unlink($zipPath);
        }

        return [_t('FERME_MODEL_ARCHIVE_EXTRACTED') . ' ' . $extracted];
    }

    /**
     * Drive the remote backup to its end and hand back the zip it left in the backups
     * folder. Each status() call moves the job one step, so this is the polling the
     * backup screen does, with no one watching.
     *
     * @throws \RuntimeException
     */
    private function downloadArchive(string $baseUrl, string $username, string $password): string
    {
        $state = $this->remoteBackup->start($baseUrl, $username, $password, [
            'savefiles' => '1',
            'savedatabase' => '0',
            'onlyFolders' => self::CONTENT_FOLDERS,
        ]);

        $deadline = time() + self::FETCH_MAX_SECONDS;
        $previousStep = '';
        while (!empty($state['running'])) {
            if (time() > $deadline) {
                $this->remoteBackup->cancel();
                throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_TIMEOUT'));
            }
            if ($state['step'] === $previousStep) {
                sleep(self::POLL_SECONDS);
            }
            $previousStep = $state['step'];
            $state = $this->remoteBackup->status();
        }

        if (!empty($state['error'])) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_FAILED') . ' ' . $state['error']);
        }
        if (empty($state['filename'])) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_FAILED'));
        }

        return $this->archiveService->getPrivateFolder() . DIRECTORY_SEPARATOR . $state['filename'];
    }

    /**
     * Take the custom and files folders out of a backup, and leave the rest of it,
     * starting with the wakka.config.php of a wiki that is not this one.
     *
     * @throws \RuntimeException
     */
    private function extractContent(string $zipPath, string $target): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_UNREADABLE') . ' ' . basename($zipPath));
        }

        $extracted = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if ($name === false || substr($name, -1) === '/' || !$this->isModelContent($name)) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                if ($this->writeStream($stream, $target . '/' . $name)) {
                    ++$extracted;
                }
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return $extracted;
    }

    private function isModelContent(string $name): bool
    {
        if (strpos($name, '..') !== false || strpos($name, "\0") !== false || substr($name, 0, 1) === '/') {
            return false;
        }
        if (!preg_match('#^(' . implode('|', self::CONTENT_FOLDERS) . ')/#', $name)) {
            return false;
        }
        foreach (self::CUSTOM_SKIPPED as $skipped) {
            if (strpos($name, 'custom/' . $skipped . '/') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param resource $stream
     */
    private function writeStream($stream, string $path): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }
        $written = stream_copy_to_stream($stream, $handle);
        fclose($handle);

        return $written !== false;
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Reduce the many shapes of a wiki address to one comparable form:
     * no query marker, no trailing slash.
     */
    private function normalizeUrl(string $url): string
    {
        $url = str_replace(['wakka.php?wiki=', 'index.php?wiki=', '?wiki='], '', trim($url));
        $url = preg_replace('/[?#].*$/', '', $url);

        return rtrim($url, '/');
    }
}
