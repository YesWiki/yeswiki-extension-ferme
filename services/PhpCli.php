<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The PHP command-line binary that runs a wiki console, also when the farm is served by php-fpm.
 */
class PhpCli
{
    /**
     * The CLI binary of the running PHP version: PHP_BINARY under the CLI, the same version's php beside it under fpm.
     */
    public static function binary(string $sapi = PHP_SAPI): string
    {
        $configured = getenv('ASYNC_PHP_BINARY');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if ($sapi === 'cli') {
            return PHP_BINARY;
        }
        $sameVersion = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (is_executable($sameVersion)) {
            return $sameVersion;
        }
        $found = (new PhpExecutableFinder())->find(false);

        return is_string($found) && $found !== '' && !str_contains(basename($found), 'fpm') ? $found : 'php';
    }
}
