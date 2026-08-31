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
                    if (is_dir($full)) {
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
        } else {
            return false;
        }
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
