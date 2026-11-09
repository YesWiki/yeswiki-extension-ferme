<?php

namespace YesWiki\Ferme\Service;

class Yeswicli
{
    /** Runs the migrations of a freshly built wiki and returns the output of a failed run, empty when it worked. */
    public function migrate(string $wikiFolder): array
    {
        if (!file_exists($wikiFolder . 'tools/autoupdate/services/MigrationService.php')) {
            return [];
        }

        if (!$this->canExec()) {
            return [_t('FERME_MIGRATE_NO_EXEC')];
        }

        chmod($wikiFolder . 'yeswicli', 0755);
        $currentDir = getcwd();
        chdir($wikiFolder);
        $output = [];
        $status = 0;
        try {
            exec(FarmMigrationWatch::RUNNING . '=1 ./yeswicli migrate 2>&1', $output, $status);
        } finally {
            chdir($currentDir);
        }

        return $status === 0 ? [] : $output;
    }

    private function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}
