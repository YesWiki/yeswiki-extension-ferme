<?php

namespace YesWiki\Ferme\Action;

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\WikiRepository;
use YesWiki\Identity\Service\AclService;
use YesWiki\Kernel\Component\Category;
use YesWiki\Kernel\Component\Component;
use YesWiki\Kernel\Component\ProvidesComponents;
use YesWiki\Kernel\Performable\RegisteredAction;

/** `{{contactwikis}}`: the addresses of the people who run the farm's wikis. */
class ContactWikisAction extends YesWikiAction implements RegisteredAction, ProvidesComponents
{
    public static function performableName(): string
    {
        return 'contactwikis';
    }

    public function components(): array
    {
        return [
            Component::for('contactwikis')
                ->category(Category::Admin)
                ->label(_t('FERME_CONTACTWIKIS_LABEL'))
                ->description(_t('FERME_CONTACTWIKIS_DESCRIPTION'))
                ->icon('mail')
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
        $template = $this->getService(FarmConfig::class)->setting('contact_wikis', []);
        $template = is_array($template) && is_string($template['default_template'] ?? null) ? basename($template['default_template']) : 'list-email.twig';
        $wikis = [];
        foreach ($this->getService(WikiRepository::class)->getAll() as $fiche) {
            $wikis[] = [
                'title' => (string)($fiche['title'] ?? $fiche['bf_titre'] ?? ''),
                'referent' => (string)($fiche['bf_referent'] ?? ''),
                'mail' => (string)($fiche['bf_mail'] ?? ''),
                'folder' => (string)($fiche['bf_dossier-wiki'] ?? ''),
                'url' => (string)($fiche['url'] ?? ''),
            ];
        }

        return $this->render('@ferme/' . $template, ['wikiList' => $wikis]);
    }
}
