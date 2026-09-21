<?php

/**
 * Per-request hook for the statistics trickle (see StatsScheduler), on doryphore.
 *
 * Core's maintenance dispatches nothing an extension could subscribe to, so a page
 * view is the signal: Performer runs this before the handler of the same name, on
 * every page of the farm master.
 *
 * Runs with $this bound to the Wiki, and must print nothing.
 */

use YesWiki\Ferme\Service\StatsScheduler;

try {
    $this->services->get(StatsScheduler::class)->triggerAfterResponse();
} catch (Throwable $ex) {
    // measuring the farm must never break the page it was noticed from
}
