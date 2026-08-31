<?php

namespace YesWiki\Ferme\Service;

/**
 * Recursive file operations and path normalisation, shared by every service that
 * moves a wiki folder around.
 */
class FileSystem
{
    /**
     * recursive remove file or folder.
     *
     * @param string $src path
     *
     * @return void
     */
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

    /**
     * recursive copy file or folder.
     *
     * @param string $path : source path
     * @param string $dest : destination path
     *
     * @return void
     */
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
                    // go on
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

    /**
     * Returns the real path of given path even for non existent path, with trailing /.
     *
     * @param string $path
     *
     * @return string
     */
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
