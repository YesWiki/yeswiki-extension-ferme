<?php

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmService;

class ContactWikisAction extends YesWikiAction
{
    public function run()
    {
        $farm = $this->getService(FarmService::class);
        return $this->render(
            '@ferme/'.$this->wiki->config['contact_wikis']['default_template'],
            [
                'wikiList' => $farm->getWikiList()
            ]
        );
    }
}
