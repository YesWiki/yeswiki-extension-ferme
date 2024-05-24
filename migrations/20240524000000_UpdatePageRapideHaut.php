<?php

use YesWiki\Core\Service\PageManager;
use YesWiki\Core\YesWikiMigration;
use YesWiki\Ferme\Service\UpdateHandlerService;

class UpdatePageRapideHaut extends YesWikiMigration
{
    public function run()
    {
        $updateHandlerService = $this->getService(UpdateHandlerService::class);
        $pageManager = $this->getService(PageManager::class);
        $messages = [];
        if (empty($pageManager->getOne('AdminWikis'))) {
            $updateHandlerService->updatePageRapideHaut($messages);
        }
        $errors = array_column(
            array_filter(
                $messages,
                function ($message) {
                    return $message['status'] != 'ok';
                }
            ),
            'text'
        );
        if (!empty($errors)) {
            throw new Exception('Error Processing ' . implode('|', $errors));
        }
    }
}
