<?php

// Verification de securite
if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}
use YesWiki\Ferme\Service\FarmService;

$farm = $this->services->get(FarmService::class);
$farm->deleteWikiFromEntry($this->getPageTag());
