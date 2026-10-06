<?php

namespace YesWiki\Ferme\Action;

use Symfony\Component\Security\Csrf\CsrfTokenManager;
use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmTable;
use YesWiki\Identity\Service\AclService;
use YesWiki\Kernel\Component\Category;
use YesWiki\Kernel\Component\Component;
use YesWiki\Kernel\Component\ProvidesComponents;
use YesWiki\Kernel\Performable\RegisteredAction;
use YesWiki\Kernel\Service\UrlFormatter;

/** `{{adminwikis}}`: the farm's wikis, their numbers, and what can be done to each of them. */
class AdminWikisAction extends YesWikiAction implements RegisteredAction, ProvidesComponents
{
    public static function performableName(): string
    {
        return 'adminwikis';
    }

    public function components(): array
    {
        return [
            Component::for('adminwikis')
                ->category(Category::Admin)
                ->label(_t('FERME_ADMINWIKIS_LABEL'))
                ->description(_t('FERME_ADMINWIKIS_DESCRIPTION'))
                ->icon('server')
                ->previewHeight('200px')
                ->adminOnly(),
        ];
    }

    public function formatArguments($arg): array
    {
        return [];
    }

    public function run(): string
    {
        if (!$this->getService(AclService::class)->isAdmin()) {
            return $this->render('@core/alert-message.twig', ['type' => 'danger', 'message' => _t('FERME_ADMIN_REQUIRED')]);
        }
        $urls = $this->getService(UrlFormatter::class);

        return $this->render('@ferme/wikis-table.twig', $this->getService(FarmTable::class)->view($this->getRequest()->query->all()) + [
            'csrfToken' => $this->getService(CsrfTokenManager::class)->getToken('main')->getValue(),
            'api' => [
                'run' => $urls->href('', 'api/ferme/wikis/run'),
                'delete' => $urls->href('', 'api/ferme/wikis/delete'),
                'mail' => $urls->href('', 'api/ferme/wikis/mail'),
                'lifetime' => $urls->href('', 'api/ferme/wikis/lifetime'),
                'select' => $urls->href('', 'api/ferme/wikis/select'),
                'search' => $urls->href('', 'api/ferme/wikis/search'),
                'import' => $urls->href('', 'api/ferme/wikis/import'),
                'detail' => $urls->href('', 'api/ferme/wikis/detail'),
                'approve' => $urls->href('', 'api/ferme/wikis/approve-spam-page'),
            ],
        ]);
    }
}
