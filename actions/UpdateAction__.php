<?php

/**
 * Runs after the core's update page, which is where the master changes version.
 *
 * The wikis of the farm borrow the master's files, so they start running the new
 * code at once while their own databases are still the old ones: this is the
 * moment to migrate them. FarmMigrationWatch decides whether anything moved, and
 * the work is left for after the page has been served.
 */

namespace YesWiki\Ferme;

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmMigrationWatch;

class UpdateAction__ extends YesWikiAction
{
    public function run()
    {
        try {
            $this->getService(FarmMigrationWatch::class)->triggerAfterResponse();
        } catch (\Throwable $throwable) {
            // the farm's wikis must never break the master's own update
        }

        return '';
    }
}
