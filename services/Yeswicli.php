<?php

namespace YesWiki\Ferme\Service;

/**
 * Runs a wiki's own yeswicli binary. Both wiki creation and wiki update need this,
 * so neither owns it.
 */
class Yeswicli
{
    /**
     * Apply pending migrations to a wiki, if that wiki is recent enough to have them.
     *
     * @param string $wikiFolder absolute path to the wiki, with a trailing separator
     */
    public function migrate(string $wikiFolder): void
    {
        if (!file_exists($wikiFolder . 'tools/autoupdate/services/MigrationService.php')) {
            return;
        }

        chmod($wikiFolder . 'yeswicli', 0755);
        $currentDir = getcwd();
        chdir($wikiFolder);
        exec('./yeswicli migrate');
        chdir($currentDir);
    }
}
