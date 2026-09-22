<?php

namespace YesWiki\Ferme\Service;

class Yeswicli
{
    /**
     * Run the migrations of a wiki that was just built, and report what stopped them.
     * A wiki whose migrations never ran looks finished and is not, so the caller is
     * told rather than left to find out later.
     *
     * @return array<int,string> the output of a run that failed, empty when it worked
     */
    public function migrate(string $wikiFolder): array
    {
        if (!file_exists($wikiFolder . 'tools/autoupdate/services/MigrationService.php')) {
            return [];
        }

        chmod($wikiFolder . 'yeswicli', 0755);
        $currentDir = getcwd();
        chdir($wikiFolder);
        $output = [];
        $status = 0;
        exec(FarmMigrationWatch::RUNNING . '=1 ./yeswicli migrate 2>&1', $output, $status);
        chdir($currentDir);

        return $status === 0 ? [] : $output;
    }
}
