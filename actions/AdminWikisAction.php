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

            return $this->render(
                '@ferme/wikis-table.twig',
                [
                    'api_url'         => $this->wiki->href('', 'api/ferme/wikis'),
                    'upgrade_api_url' => $this->wiki->href('', 'api/ferme/wikis/upgrade'),
                    'delete_api_url'  => $this->wiki->href('', 'api/ferme/wikis/delete'),
                    'search_api_url'  => $this->wiki->href('', 'api/ferme/wikis/search'),
                ]
            );
        } else { // User isn't admin
            return '<div class="alert alert-danger">' . _t('FERME_ADMIN_REQUIRED') . '</div>';
        }
    }
}
