<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\FolderBusyException;

/**
 * One wiki folder, one worker at a time. Creating, updating and deleting a wiki all
 * walk the same folder, and two of them at once leave a half-copied tree behind.
 *
 * The lock is an flock on a file of its own, so the kernel releases it whatever way
 * the process leaves, fatal included. Whoever holds it writes down what it is doing,
 * which is what the next one reports instead of waiting.
 */
class FolderLock
{
    public const DIR = 'cache/ferme-locks';

    private $dir = self::DIR;
    private $handles = [];
    private $depth = [];

    /** Where the lock files live. The farm uses the default; tests use their own. */
    public function useDirectory(string $dir): void
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
    }

    /**
     * Run the work with the folder held, or refuse when somebody else has it.
     *
     * @return mixed whatever the work returns
     */
    public function during(string $folder, string $what, callable $work)
    {
        if (!$this->acquire($folder, $what)) {
            throw new FolderBusyException($folder, $this->heldFor($folder));
        }

        try {
            return $work();
        } finally {
            $this->release($folder);
        }
    }

    public function acquire(string $folder, string $what = ''): bool
    {
        $key = $this->key($folder);
        if (isset($this->handles[$key])) {
            $this->depth[$key]++;

            return true;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = $this->open($key);
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return false;
            }

            if (!$this->stillAtPath($handle, $this->path($key))) {
                flock($handle, LOCK_UN);
                fclose($handle);

                continue;
            }

            ftruncate($handle, 0);
            fwrite($handle, json_encode(['pid' => getmypid(), 'what' => $what, 'since' => date('c')]));
            fflush($handle);

            $this->handles[$key] = $handle;
            $this->depth[$key] = 1;

            return true;
        }

        return false;
    }

    public function release(string $folder): void
    {
        $key = $this->key($folder);
        if (!isset($this->handles[$key]) || --$this->depth[$key] > 0) {
            return;
        }

        @unlink($this->path($key));
        flock($this->handles[$key], LOCK_UN);
        fclose($this->handles[$key]);
        unset($this->handles[$key], $this->depth[$key]);
    }

    /**
     * Sweep the lock files a killed process left behind: only those nobody holds and
     * that nothing has touched for a while, so a long update is never swept from
     * under its own feet.
     */
    public function prune(int $olderThan = 86400): int
    {
        $swept = 0;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.lock') ?: [] as $file) {
            if (time() - (int)@filemtime($file) < $olderThan) {
                continue;
            }

            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                if ($this->stillAtPath($handle, $file) && @unlink($file)) {
                    $swept++;
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }

        return $swept;
    }

    /**
     * The lock protects a name, and a name can be given to another file. Holding a
     * file that is no longer the one at that path means holding nothing, which is
     * how two workers would both believe they had the folder.
     *
     * @param resource $handle
     */
    private function stillAtPath($handle, string $path): bool
    {
        $held = fstat($handle);
        $named = @stat($path);

        return is_array($named) && $named['ino'] === $held['ino'] && $named['dev'] === $held['dev'];
    }

    public function isHeldHere(string $folder): bool
    {
        return isset($this->handles[$this->key($folder)]);
    }

    /** What the process holding the folder said it was doing, as far as it wrote it down. */
    public function heldFor(string $folder): string
    {
        $written = @file_get_contents($this->path($this->key($folder)));
        $held = is_string($written) ? json_decode($written, true) : null;
        if (!is_array($held)) {
            return '';
        }

        return trim((string)($held['what'] ?? '') . ' ' . (string)($held['since'] ?? ''));
    }

    protected function open(string $key)
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $this->dir);
        }

        $handle = fopen($this->path($key), 'c+');
        if ($handle === false) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $this->path($key));
        }

        return $handle;
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.lock';
    }

    private function key(string $folder): string
    {
        $folder = rtrim($folder, DIRECTORY_SEPARATOR);
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $folder);

        return trim((string)$slug, '-.') . '-' . substr(sha1($folder), 0, 8);
    }
}
