<?php

use YesWiki\Core\YesWikiMigration;
use YesWiki\Ferme\Service\UpdateHandlerService;

class CreateWikiModelsFolder extends YesWikiMigration
{
    public function run()
    {
        $updateHandlerService = $this->getService(UpdateHandlerService::class);
        $messages = [];
        $updateHandlerService->createWikiModelsFolder($messages);
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
