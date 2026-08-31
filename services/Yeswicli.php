<?php

namespace YesWiki\Ferme\Service;

class Yeswicli
{
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
