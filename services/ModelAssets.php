<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Core\Service\ArchiveService;
use YesWiki\Core\Service\RemoteBackupService;
use YesWiki\Wiki;

/**
 * A wiki model is a SQL dump plus the whole content of its source: the files folder
 * with the originals, not the sizes a page happened to display, and the custom folder
 * that styles them. This fills <model>/files and <model>/custom, where WikiCreator
 * already looks for them when it builds a wiki.
 *
 * A source hosted on this server is copied from disk, in one go. A source elsewhere is
 * asked for a backup of those two folders and nothing else, which takes minutes: that
 * one is a job the browser walks forward a step at a time, the way the core backup
 * screen does, because no single request lives long enough to sit through it.
 */
class ModelAssets
{
    /** The two folders a model takes from its source, whole. */
    public const CONTENT_FOLDERS = ['custom', 'files'];

    public const JOB_FILENAME = 'model-assets.json';
    public const STEP_IDLE = 'idle';
    public const STEP_DONE = 'done';

    /**
     * Left out of what is copied: the farm's own models, because a model of a farm
     * master would nest every other model inside itself, and the caches.
     */
    private const CUSTOM_SKIPPED = ['wiki-models', 'cache'];

    /** A job still around after this long was left behind by a browser that went away. */
    private const STALE_AFTER = 7200;

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
     * Fill a model folder with the files and the custom folder of its source, or open
     * the job that will, when the source is on another server.
     *
     * @param string $model       folder name under custom/wiki-models
     * @param string $baseUrl     source wiki, without trailing slash
     * @param array  $credentials 'username' and 'password' of an administrator of the
     *                            source, needed only when it is on another server
     *
     * @return array{running:bool,messages:array,state:array}
     */
    public function start(string $model, string $baseUrl, array $credentials = []): array
    {
        $localPath = $this->localWikiPath($baseUrl);
        if (!is_null($localPath)) {
            return $this->finished($this->copyFromDisk($localPath, $this->config->modelDir($model)));
        }

        $username = trim((string)($credentials['username'] ?? ''));
        $password = (string)($credentials['password'] ?? '');
        if ($username === '' || $password === '') {
            return $this->finished([_t('FERME_MODEL_REMOTE_NEEDS_ADMIN')]);
        }

        // fail here rather than after the source has started working for nothing
        $this->jobPath();

        $state = $this->remoteBackup->start($baseUrl, $username, $password, [
            'savefiles' => '1',
            'savedatabase' => '0',
            'onlyFolders' => self::CONTENT_FOLDERS,
        ]);
        $this->writeJob(['model' => $model, 'baseUrl' => $baseUrl, 'startedAt' => time()]);

        return ['running' => true, 'messages' => [], 'state' => $state];
    }

    /**
     * Move the running fetch one step further. The last step unpacks the backup into
     * the model, which is the only step that touches what the model already held.
     *
     * @return array{running:bool,messages:array,state:array}
     *
     * @throws \RuntimeException
     */
    public function advance(): array
    {
        $job = $this->readJob();
        if (empty($job)) {
            return $this->finished([], self::STEP_IDLE);
        }
        if (time() - (int)($job['startedAt'] ?? 0) > self::STALE_AFTER) {
            $this->cancel();

            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_TIMEOUT'));
        }

        $state = $this->remoteBackup->status();
        if (!empty($state['running'])) {
            return ['running' => true, 'messages' => [], 'state' => $state];
        }

        $this->deleteJob();
        if (!empty($state['error'])) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_FAILED') . ' ' . $state['error']);
        }
        if (empty($state['filename'])) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_FAILED'));
        }

        $zipPath = $this->archiveService->getPrivateFolder() . DIRECTORY_SEPARATOR . $state['filename'];
        try {
            $extracted = $this->unpack($zipPath, $this->config->modelDir($job['model']));
        } finally {
            @unlink($zipPath);
        }

        return $this->finished([_t('FERME_MODEL_ARCHIVE_EXTRACTED') . ' ' . $extracted]);
    }

    /**
     * Give up a fetch, here and on the wiki it was being fetched from.
     */
    public function cancel(): array
    {
        $ours = !empty($this->readJob());
        $this->deleteJob();
        if ($ours) {
            // a backup someone started from the core screen is theirs to stop, not ours
            $this->remoteBackup->cancel();
        }

        return $this->finished([], self::STEP_IDLE);
    }

    /**
     * The model a fetch is filling, when one is running.
     */
    public function runningModel(): ?string
    {
        $job = $this->readJob();

        return empty($job['model']) ? null : (string)$job['model'];
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

    /**
     * Is this zip entry something a model wants?
     */
    public function isModelContent(string $name): bool
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

    private function copyFromDisk(string $source, string $target): array
    {
        $this->emptyContent($target);
        $messages = [];

        if (is_dir($source . '/files')) {
            $whole = $this->files->copyRecursive($source . '/files', $target . '/files');
            $messages[] = _t('FERME_MODEL_FILES_COPIED') . ' ' . $this->countFiles($target . '/files');
            if ($whole !== true) {
                $messages[] = _t('FERME_COPY_INCOMPLETE') . ' ' . $this->files->failureSummary();
            }
        }

        if (is_dir($source . '/custom')) {
            @mkdir($target . '/custom', 0777, true);
            foreach (scandir($source . '/custom') as $entry) {
                if (in_array($entry, ['.', '..'], true) || in_array($entry, self::CUSTOM_SKIPPED, true)) {
                    continue;
                }
                if ($this->files->copyRecursive($source . '/custom/' . $entry, $target . '/custom/' . $entry) !== true) {
                    $messages[] = _t('FERME_COPY_INCOMPLETE') . ' ' . $this->files->failureSummary();
                }
            }
            $messages[] = _t('FERME_MODEL_CUSTOM_COPIED') . ' ' . $this->countFiles($target . '/custom');
        }

        return empty($messages) ? [_t('FERME_MODEL_NO_ASSETS')] : $messages;
    }

    /**
     * Take the custom and files folders out of a backup, and leave the rest of it,
     * starting with the wakka.config.php of a wiki that is not this one. What the
     * model held is dropped here and not a moment earlier, so that a fetch that
     * never got this far leaves the model as it was.
     *
     * @throws \RuntimeException
     */
    private function unpack(string $zipPath, string $target): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(_t('FERME_MODEL_ARCHIVE_UNREADABLE') . ' ' . basename($zipPath));
        }

        try {
            $wanted = $this->wantedEntries($zip);
            $this->assertRoomFor($wanted['bytes'], $target);
            $this->emptyContent($target);

            $extracted = 0;
            foreach ($wanted['names'] as $name) {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                if ($this->writeStream($stream, $target . '/' . $name)) {
                    $extracted++;
                }
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return $extracted;
    }

    /**
     * @return array{names:array,bytes:int}
     */
    private function wantedEntries(\ZipArchive $zip): array
    {
        $names = [];
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false || substr($stat['name'], -1) === '/' || !$this->isModelContent($stat['name'])) {
                continue;
            }
            $names[] = $stat['name'];
            $bytes += (int)$stat['size'];
        }

        return ['names' => $names, 'bytes' => $bytes];
    }

    /**
     * The core weighs the download, no one weighs what comes out of it.
     *
     * @throws \RuntimeException
     */
    private function assertRoomFor(int $bytes, string $target): void
    {
        if ($bytes <= 0) {
            return;
        }
        $free = @disk_free_space(is_dir($target) ? $target : getcwd());
        if ($free !== false && $free < $bytes) {
            $detail = $this->humanSize($bytes) . ' / ' . $this->humanSize((int)$free);

            throw new \RuntimeException(_t('FERME_MODEL_NO_ROOM') . ' ' . $detail);
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['o', 'ko', 'Mo', 'Go', 'To'];
        $value = (float)$bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string)$bytes : number_format($value, 1)) . ' ' . $units[$unit];
    }

    private function emptyContent(string $target): void
    {
        foreach (self::CONTENT_FOLDERS as $dir) {
            $this->files->remove($target . '/' . $dir);
        }
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
                $count++;
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

    /**
     * @return array{running:bool,messages:array,state:array}
     */
    private function finished(array $messages, string $step = self::STEP_DONE): array
    {
        return ['running' => false, 'messages' => $messages, 'state' => ['step' => $step]];
    }

    private function jobPath(): string
    {
        return $this->config->ensureBackupDir() . DIRECTORY_SEPARATOR . self::JOB_FILENAME;
    }

    private function readJob(): array
    {
        $path = $this->jobPath();
        if (!file_exists($path)) {
            return [];
        }
        $job = json_decode((string)file_get_contents($path), true);

        return is_array($job) ? $job : [];
    }

    private function writeJob(array $job): void
    {
        file_put_contents($this->jobPath(), json_encode($job));
    }

    private function deleteJob(): void
    {
        $path = $this->jobPath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
