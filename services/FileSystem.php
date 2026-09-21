<?php

namespace YesWiki\Ferme\Service;

class FileSystem
{
    public const FAILURES_KEPT = 10;

    private $failures = [];
    private $failed = 0;
    private $copied = 0;
    private $depth = 0;

    public function rrmdir($src)
    {
        $dir = opendir($src);
        if ($dir) {
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..')) {
                    $full = $src . '/' . $file;
                    // a symlink to a directory is a directory to is_dir(), and
                    // recursing into one empties whatever it points at
                    if (is_link($full)) {
                        unlink($full);
                    } elseif (is_dir($full)) {
                        $this->rrmdir($full);
                    } else {
                        unlink($full);
                    }
                }
            }
            closedir($dir);
            rmdir($src);
        }
    }

    /**
     * Delete a file, a folder or a symlink, without ever following the symlink.
     */
    public function remove($path)
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (is_dir($path)) {
            $this->rrmdir($path);

            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Copy a file or a whole folder, and say so only when everything arrived. A copy
     * that lost files used to pass for a good one, which is how a wiki was created
     * with an empty tree and nobody heard about it.
     */
    public function copyRecursive($path, $dest): bool
    {
        if ($this->depth === 0) {
            $this->failures = [];
            $this->failed = 0;
            $this->copied = 0;
        }

        $this->depth++;

        try {
            return $this->copyInto((string)$path, (string)$dest);
        } finally {
            $this->depth--;
        }
    }

    /** How many files the last copy wrote. */
    public function copiedFiles(): int
    {
        return $this->copied;
    }

    /** How many it could not, and the first of them by name. */
    public function failedFiles(): int
    {
        return $this->failed;
    }

    /**
     * @return array<int,string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function failureSummary(): string
    {
        if ($this->failed === 0) {
            return '';
        }

        $summary = $this->failed . ' ' . _t('FERME_COPY_FAILED_FILES') . ' : ' . implode(', ', $this->failures);

        return $this->failed > count($this->failures) ? $summary . '…' : $summary;
    }

    private function copyInto(string $path, string $dest): bool
    {
        if (is_dir($path)) {
            return $this->copyFolder($path, $dest);
        }

        if (is_file($path)) {
            return @copy($path, $dest) ? $this->counted() : $this->missed($path);
        }

        return $this->missed($path);
    }

    private function copyFolder(string $path, string $dest): bool
    {
        if (!is_dir($dest) && !@mkdir($dest, 0777, true) && !is_dir($dest)) {
            return $this->missed($dest);
        }

        $objects = @scandir($path);
        if ($objects === false) {
            return $this->missed($path);
        }

        $whole = true;
        foreach ($objects as $file) {
            if (in_array($file, ['.', '..', '.git', 'bower_components'], true)) {
                continue;
            }

            $from = $path . DIRECTORY_SEPARATOR . $file;
            $to = $dest . DIRECTORY_SEPARATOR . $file;
            $whole = (is_dir($from) ? $this->copyFolder($from, $to) : $this->copyInto($from, $to)) && $whole;
        }

        return $whole;
    }

    private function counted(): bool
    {
        $this->copied++;

        return true;
    }

    private function missed(string $path): bool
    {
        $this->failed++;
        if (count($this->failures) < self::FAILURES_KEPT) {
            $this->failures[] = $path;
        }

        return false;
    }

    public function getAbsolutePath($path)
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' == $part) {
                continue;
            }
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $absolutes) . DIRECTORY_SEPARATOR;
    }
}
