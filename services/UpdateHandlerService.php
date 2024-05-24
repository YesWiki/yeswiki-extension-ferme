<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\DbService;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Wiki;

class UpdateHandlerService
{
    public const PATHS = [
        'lists' => [
            'ListeOuiNon' => 'tools/ferme/setup/lists/ListeOuiNon.json',
        ],
        'forms' => [
            'Farm description' => 'tools/ferme/setup/forms/Form - Farm.json',
            'Farm template' => 'tools/ferme/setup/forms/Form - Farm - template.txt',
        ],
        'pages' => [
            'AdminWikis' => 'tools/ferme/setup/pages/AdminWikis.txt',
            'AjouterWiki' => 'tools/ferme/setup/pages/AjouterWiki.txt',
            // 'ContactWikis' => 'tools/ferme/setup/pages/ContactWikis.txt',
            'ModelesWiki' => 'tools/ferme/setup/pages/ModelesWiki.txt',
            'PageRapideHaut' => 'tools/ferme/setup/pages/PageRapideHaut.txt',
        ],
    ];

    protected $aclService;
    protected $dbService;
    protected $entryManager;
    protected $formManager;
    protected $pageManager;
    protected $params;
    protected $tripleStore;
    protected $wiki;

    public function __construct(
        AclService $aclService,
        DbService $dbService,
        EntryManager $entryManager,
        FormManager $formManager,
        PageManager $pageManager,
        ParameterBagInterface $params,
        TripleStore $tripleStore,
        Wiki $wiki
    ) {
        $this->aclService = $aclService;
        $this->dbService = $dbService;
        $this->entryManager = $entryManager;
        $this->formManager = $formManager;
        $this->pageManager = $pageManager;
        $this->params = $params;
        $this->tripleStore = $tripleStore;
        $this->wiki = $wiki;
    }

    public function createWikiModelsFolder(array &$messages)
    {
        $customWikiModelDir = 'custom/wiki-models/';
        $lines = ["ℹ️ Creating the folder <em>$customWikiModelDir</em> for the wiki models"];
        $text = "Creating the folder \"$customWikiModelDir\" for the wiki models";
        $status = _t('AU_ERROR');
        // Structure de répertoire désirée
        if (!is_dir($customWikiModelDir)) {
            if (mkdir($customWikiModelDir, 0777, true)) {
                $lines[] = '✅Done !';
                $status = _t('AU_OK');
            } else {
                $lines[] = "! Not possible to create folder !";
                $text .= "! Not possible to create folder !";
            }
        } else {
            $lines[] = "✅ The folder <em>$customWikiModelDir</em> for the wiki models already exists.";
            $status = _t('AU_OK');
        }
        $messages[] = compact(['lines','text','status']);
    }

    public function installListeOuiNon(array &$messages)
    {
        $lines = ['ℹ️ Adding the <em>Oui Non</em> list'];
        $text = 'Adding the "Oui Non" list';
        $status = _t('AU_ERROR');
        // if the OuiNon Lms list doesn't exist, create it
        if (!$this->pageManager->getOne('ListeOuiNon')) {
            // save the page with the list value
            $this->pageManager->save('ListeOuiNon', $this->loadFileContent('lists', 'ListeOuiNon'));
            // in case, there is already some triples for 'ListOuinonLms', delete them
            $this->tripleStore->delete('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', null);
            // create the triple to specify this page is a list
            $this->tripleStore->create('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', 'liste', '', '');
            $lines[] = '✅ Done !';
        } else {
            $lines[] = '✅ The <em>Oui Non</em> list already exists.';
        }
        $status = _t('AU_OK');
        $messages[] = compact(['lines','text','status']);
    }

    public function installFarmForm(array &$messages)
    {
        // test if the FARM form exists, if not, install it
        $formDescription = json_decode($this->loadFileContent('forms', 'Farm description'), true);
        $formTemplate = $this->loadFileContent('forms', 'Farm template');
        $formTemplate = str_replace('{UtilisationDonnees}', $this->wiki->Href('', 'UtilisationDonnees'), $formTemplate);
        $formTemplate = str_replace('{Contact}', $this->wiki->Href('', 'Contact'), $formTemplate);
        if (empty($formTemplate)) {
            $messages[] = [
                'lines' => ["! not possible to add <em>farm</em> form !"],
                'text' => '! not possible to add "farm" form !',
                'status' => _t('AU_ERROR')
            ];
        } else {
            $this->checkAndAddForm(
                $messages,
                $this->params->get('bazar_farm_id'),
                $formDescription["FARM_FORM_NOM"],
                $formDescription["FARM_FORM_DESCRIPTION"],
                $formTemplate
            );
        }
    }

    protected function checkAndAddForm(
        array &$messages,
        $formId,
        $formName,
        $formDescription,
        $formTemplate
    ) {
        $lines = ["ℹ️ Adding <em>{$formName}</em> form into <em>{$this->dbService->prefixTable('nature')}</em> table."];
        $text = "Adding \"{$formName}\" form into \"{$this->dbService->prefixTable('nature')}\" table.";
        $status = _t('AU_ERROR');
        $form = $this->formManager->getOne($formId);
        if (!empty($form)) {
            $lines[] = "✅ The <em>{$formName}</em> form already exists in the <em>{$this->dbService->prefixTable('nature')}</em> table.";
            $status = _t('AU_OK');
        } else {
            $this->formManager->create([
                'bn_id_nature' => $formId,
                'bn_label_nature' => $formName,
                'bn_template' => $formTemplate,
                'bn_description' => $formDescription,
                'bn_sem_context' => $formDescription,
                'bn_sem_type' => '',
                'bn_sem_use_template' => '1',
                'bn_condition' => '',
            ]);

            $lines[] = '✅ Done !';
            $status = _t('AU_OK');
        }
        $messages[] = compact(['lines','text','status']);
    }

    public function removeBfDossierField(array &$messages)
    {
        $lines = ["ℹ️ Removing bf_dossier fields from bazar entries in {$this->dbService->prefixTable('pages')} table."];
        $text = "Removing bf_dossier fields from bazar entries in {$this->dbService->prefixTable('pages')} table.";
        $status = _t('AU_ERROR');
        // remove bf_dossier fields
        if (method_exists(EntryManager::class, 'removeAttributes')) {
            if ($this->entryManager->removeAttributes([], ['bf_dossier-wiki_wikiname','bf_dossier-wiki_email','bf_dossier-wiki_password'], true)) {
                $lines[] = '✅ Done !';
            } else {
                $lines[] = "✅ The table {$this->dbService->prefixTable('pages')} is already free of bf_dossier fields in bazar entries !";
            }
            $status = _t('AU_OK');
        } else {
            $lines[] = "! Not possible to remove bf_dossier fields from bazar entries in {$this->dbService->prefixTable('pages')} table.";
        }
        $messages[] = compact(['lines','text','status']);
    }

    /**
     * @param array $messages
     */
    public function updatePageRapideHaut(array &$messages)
    {
        $lines = ['ℹ️ Updating page "PageRapideHaut"'];
        $text = 'Updating page "PageRapideHaut"';
        $status = _t('AU_ERROR');
        $pageRapideHaut = $this->pageManager->getOne('PageRapideHaut');
        if (empty($pageRapideHaut)) {
            $lines[] = "! The <em>$pageName</em> page does not exist.";
            $text .= ' The page does not exist !';
        } elseif (strstr($pageRapideHaut['body'], 'AdminWikis')) {
            $lines[] = "! The menu items in <em>PageRapideHaut</em> already exist.";
            $status = _t('AU_OK');
        } else {
            $content = $this->loadFileContent('pages', 'PageRapideHaut');
            $lines[] = "ℹ️ Adding menu item in <em>PageRapideHaut</em> for the farm";
            $this->pageManager->save('PageRapideHaut', str_replace('{{end elem="buttondropdown"}}', "$content\n{{end elem=\"buttondropdown\"}}", $pageRapideHaut['body']), "", true);
            $lines[] = '✅ Done !';
            $status = _t('AU_OK');
        }
        $messages[] = compact(['lines','text','status']);
    }

    public function updatePage(
        string $pageName,
        array &$messages,
        array $replacements = []
    ): string {
        $lines = ["ℹ️ Adding the <em>$pageName</em> page"];
        $text = "Adding the \"$pageName\" page";
        $status = _t('AU_ERROR');
        if (!empty($this->pageManager->getOne($pageName))) {
            $lines[] = "✅ The <em>$pageName</em> page already exists.";
            $status = _t('AU_OK');
        } else {
            $content = $this->loadFileContent('pages', $pageName);
            if (!empty($replacements)) {
                $content = str_replace(array_keys($replacements), array_values($replacements), $content);
            }
            $this->aclService->delete($pageName); // to clear acl cache
            $this->aclService->save($pageName, 'read', '@admins');
            $this->aclService->save($pageName, 'write', '@admins');
            $this->pageManager->save($pageName, $content, "", true);
            $lines[] = '✅ Done !';
            $status = _t('AU_OK');
        }
        $messages[] = compact(['lines','text','status']);
    }

    protected function loadFileContent(string $type, string $name): string
    {
        if (!isset(self::PATHS[$type]) || !isset(self::PATHS[$type][$name])) {
            return '';
        }
        $path = self::PATHS[$type][$name];
        return file_get_contents($path);
    }
}
