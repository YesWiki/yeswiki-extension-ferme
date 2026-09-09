<?php

namespace YesWiki\Ferme\Service;

class FileSystem
{
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

    public function copyRecursive($path, $dest)
    {
        if (is_dir($path)) {
            @mkdir($dest, 0777, true);
            $objects = scandir($path);
            if (count($objects) > 0) {
                foreach ($objects as $file) {
                    if ($file == '.' || $file == '..' || $file == '.git' || $file == 'bower_components') {
                        continue;
                    }

                    if (is_dir($path . DIRECTORY_SEPARATOR . $file)) {
                        $this->copyRecursive($path . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file);
                    } else {
                        copy($path . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file);
                    }
                }
            }

            return true;
        } elseif (is_file($path) && file_exists($path)) {
            return copy($path, $dest);
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
