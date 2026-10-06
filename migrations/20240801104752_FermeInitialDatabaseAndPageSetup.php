<?php

use YesWiki\Content\Entity\MenuNode;
use YesWiki\Content\Entity\PageBody;
use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\FormManager;
use YesWiki\Content\Service\PageManager;
use YesWiki\Core\YesWikiMigration;
use YesWiki\Identity\Service\AclService;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Render\Service\LayoutService;

/** The farm's form, its list, its menu and its pages, created once and left alone after; and no password left in an old entry. */
class FermeInitialDatabaseAndPageSetup extends YesWikiMigration
{
    private const SETUP = __DIR__ . '/../setup';
    private const PAGES = ['AdminWikis', 'AjouterWiki', 'ModelesWiki'];
    private const LEFTOVERS = ['bf_dossier-wiki_wikiname', 'bf_dossier-wiki_email', 'bf_dossier-wiki_password'];

    public function run()
    {
        $farmId = $this->farmId();
        $this->createList('ListeOuiNon');
        $this->createForm($farmId);
        $this->createMenu('MenuFerme');
        foreach (self::PAGES as $tag) {
            $this->createPage($tag, ['{FarmFormId}' => $farmId]);
        }
        $this->addToQuickMenu();
        $this->forgetPasswords($farmId);
    }

    private function farmId(): string
    {
        $id = $this->params->has('bazar_farm_id') ? $this->params->get('bazar_farm_id') : '';
        $id = is_scalar($id) ? trim((string)$id) : '';

        return ctype_digit($id) ? $id : '1100';
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        $decoded = json_decode((string)file_get_contents(self::SETUP . '/' . $relative), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function createList(string $tag): void
    {
        $pages = $this->getService(PageManager::class);
        if ($pages->tagExists($tag)) {
            return;
        }
        $list = $this->json('lists/' . $tag . '.json');
        $pages->save($tag, ['title' => (string)($list['title'] ?? $tag), 'nodes' => is_array($list['nodes'] ?? null) ? $list['nodes'] : []], '', true, null, PageType::LIST);
        $pages->cacheType($tag, PageType::LIST);
        $this->say("ferme: list {$tag} created");
    }

    private function createForm(string $farmId): void
    {
        $forms = $this->getService(FormManager::class);
        if ($forms->getOne($farmId) !== null) {
            return;
        }
        $form = $this->json('forms/farm.json');
        $urls = $this->getService(UrlFormatter::class);
        $form['template'] = json_decode(str_replace(
            ['{UtilisationDonnees}', '{Contact}'],
            [$urls->href('', 'UtilisationDonnees', null, false), $urls->href('', 'Contact', null, false)],
            (string)json_encode($form['template'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ), true);
        $form['id'] = $farmId;
        $forms->create($form);
        $this->say("ferme: form {$farmId} created");
    }

    private function createMenu(string $tag): void
    {
        $pages = $this->getService(PageManager::class);
        if ($pages->tagExists($tag)) {
            return;
        }
        $menu = $this->json('menus/' . $tag . '.json');
        $nodes = array_values(array_filter(array_map(static fn ($node): ?array => is_array($node) ? MenuNode::fromArray($node)?->toArray() : null, is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [])));
        $pages->save($tag, ['title' => (string)($menu['title'] ?? $tag), 'nodes' => $nodes], '', true, null, PageType::MENU);
        $pages->cacheType($tag, PageType::MENU);
        $this->say("ferme: menu {$tag} created");
    }

    /** @param array<string, string> $replacements */
    private function createPage(string $tag, array $replacements): void
    {
        $pages = $this->getService(PageManager::class);
        if ($pages->tagExists($tag)) {
            return;
        }
        $content = strtr((string)file_get_contents(self::SETUP . '/pages/' . $tag . '.txt'), $replacements);
        $pages->save($tag, [PageBody::CONTENT => $content], '', true, null, PageType::PAGE);
        $pages->cacheType($tag, PageType::PAGE);
        $acls = $this->getService(AclService::class);
        $acls->save($tag, 'read', '@admins');
        $acls->save($tag, 'write', '@admins');
        $this->say("ferme: page {$tag} created");
    }

    /** The farm's screens in the quick menu, once: the menu hides them from whoever cannot read them. */
    private function addToQuickMenu(): void
    {
        $tag = $this->params->has(LayoutService::QUICK_MENU) ? $this->params->get(LayoutService::QUICK_MENU) : '';
        $tag = is_string($tag) ? trim($tag) : '';
        $pages = $this->getService(PageManager::class);
        $menu = $tag === '' ? null : $pages->getOne($tag, null, false, true);
        if ($menu === null || ($menu['type'] ?? '') !== PageType::MENU) {
            return;
        }
        $body = is_array($menu['body'] ?? null) ? $menu['body'] : [];
        $nodes = is_array($body['nodes'] ?? null) ? $body['nodes'] : [];
        foreach ($nodes as $node) {
            if (is_array($node) && (($node['id'] ?? '') === 'ferme' || ($node['link'] ?? '') === 'AdminWikis')) {
                return;
            }
        }
        $node = MenuNode::fromArray(['id' => 'ferme', 'label' => _t('FERME_MENU_LABEL'), 'link' => 'AdminWikis', 'icon' => ['source' => 'sprite', 'value' => 'server']]);
        if ($node === null) {
            return;
        }
        $body['nodes'] = [...$nodes, $node->toArray()];
        $pages->save($tag, $body, '', true, null, PageType::MENU);
        $this->say("ferme: {$tag} links to the farm's screens");
    }

    /**
     * Doryphore kept the creator's account name, email and password in the entry: a farm migrated from it loses them, every revision included.
     */
    private function forgetPasswords(string $farmId): void
    {
        $forms = $this->getService(FormManager::class);
        if ($forms->getOne($farmId) === null) {
            return;
        }
        $authentication = $this->getService(AuthenticationService::class);
        $loggedIn = $authentication->getLoggedUser() !== '';

        try {
            if (!$loggedIn && $authentication->connectFirstAdmin() === null) {
                return;
            }
            if ($this->getService(EntryManager::class)->removeAttributes(['formsIds' => [$farmId]], self::LEFTOVERS, true)) {
                $this->say('ferme: account names and passwords removed from the farm entries');
            }
        } catch (Throwable $failed) {
            $this->say('ferme: ' . $failed->getMessage());
        } finally {
            if (!$loggedIn) {
                $authentication->logout();
            }
        }
    }
}
