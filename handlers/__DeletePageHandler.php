<?php

/**
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 *
 * @see     https://yeswiki.net
 */

namespace YesWiki\Ferme;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\YesWikiHandler;
use YesWiki\Ferme\Service\FarmService;

class __DeletePageHandler extends YesWikiHandler
{
    public function run()
    {
        $output = '';
        $tag = $this->wiki->GetPageTag();
        $userCanDelete = $this->wiki->UserIsAdmin() || $this->wiki->UserIsOwner();
        $entryManager = $this->wiki->services->get(EntryManager::class);
        if ($entryManager->isEntry($tag) && !empty($_GET['confirme']) && $_GET['confirme'] == 'oui' && $userCanDelete) {
            try {
                if ($this->wiki->services->get(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false)) {
                    $farm = $this->wiki->services->get(FarmService::class);
                    $farm->deleteWikiFromEntry($tag);
                }
            } catch (\Throwable $th) {
                exit('No CSRF token'); // do nothing
            }
        }

        return $output;
    }
}
