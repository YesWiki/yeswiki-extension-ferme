<?php

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmService;

class AdminWikisAction extends YesWikiAction
{
    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $farm = $this->getService(FarmService::class);

            if (isset($_GET['superadmin']) and !empty($_GET['superadmin'])) {
                $farm->addFarmAdmin($_GET['superadmin']);
            }
            if (isset($_GET['nosuperadmin']) and !empty($_GET['nosuperadmin'])) {
                $farm->removeFarmAdmin($_GET['nosuperadmin']);
            }
            if (isset($_GET['maj']) and !empty($_GET['maj'])) {
                $farm->updateWiki($_GET['maj']);
            }

            $wikis = $farm->getWikiList();
            return $this->render(
                '@ferme/wikis-table.twig',
                [
                    'wikis' => $wikis
                ]
            );
        } else { // User isn't admin
            return '<div class="alert alert-danger">'._t('FERME_ADMIN_REQUIRED').'</div>';
        }
    }
}
