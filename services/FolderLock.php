<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Files\Service\RuntimeLock;

/** One wiki folder, one worker at a time, held through core's runtime locks and saying what it is doing. */
class FolderLock
{
    public const PREFIX = 'cache/ferme-';

    /** @var array<string, resource> */
    private array $handles = [];

    /** @var array<string, int> */
    private array $depth = [];

    private string $directory = '';

    public function __construct(private readonly RuntimeLock $locks)
    {
    }

    /** Keep the lock files somewhere else, which only a test wants. */
    public function useDirectory(string $directory): void
    {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Run the work with the folder held, or refuse when somebody else has it.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function during(string $folder, string $what, callable $work): mixed
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

        $handle = $this->locks->acquire($this->path($key));
        if ($handle === null || !$this->locks->tryLock($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode(['pid' => getmypid(), 'what' => $what, 'since' => date('c')]));
        fflush($handle);

        $this->handles[$key] = $handle;
        $this->depth[$key] = 1;

        return true;
    }

    public function release(string $folder): void
    {
        $key = $this->key($folder);
        if (!isset($this->handles[$key]) || --$this->depth[$key] > 0) {
            return;
        }

        ftruncate($this->handles[$key], 0);
        $this->locks->release($this->handles[$key]);
        unset($this->handles[$key], $this->depth[$key]);
    }

    public function isHeldHere(string $folder): bool
    {
        return isset($this->handles[$this->key($folder)]);
    }

    /** What the process holding the folder said it was doing. */
    public function heldFor(string $folder): string
    {
        $path = $this->absolute($this->path($this->key($folder)));
        $written = is_file($path) ? file_get_contents($path) : false;
        $held = is_string($written) ? json_decode($written, true) : null;
        if (!is_array($held)) {
            return '';
        }

        return trim((string)($held['what'] ?? '') . ' ' . (string)($held['since'] ?? ''));
    }

    private function path(string $key): string
    {
        return $this->directory === '' ? self::PREFIX . $key . '.lock' : $this->directory . '/ferme-' . $key . '.lock';
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return (\defined('YESWIKI_INSTANCE_DIR') ? YESWIKI_INSTANCE_DIR : (string)getcwd()) . '/' . $path;
    }

    private function key(string $folder): string
    {
        $folder = rtrim($folder, '/');
        $slug = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($folder)), '-.');

        return $slug . '-' . substr(sha1($folder), 0, 8);
    }
}
