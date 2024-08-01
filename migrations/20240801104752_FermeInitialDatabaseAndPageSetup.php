<?php

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Core\YesWikiMigration;

class FermeInitialDatabaseAndPageSetup extends YesWikiMigration
{
    private const PATHS = [
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
            'ModelesWiki' => 'tools/ferme/setup/pages/ModelesWiki.txt',
            'PageRapideHaut' => 'tools/ferme/setup/pages/PageRapideHaut.txt',
        ],
    ];

    public function run()
    {
        $output = '';
        $pageManager = $this->getService(PageManager::class);
        $tripleStore = $this->getService(TripleStore::class);
        $entryManager = $this->getService(EntryManager::class);

        // Structure de répertoire désirée
        $customWikiModelDir = 'custom/wiki-models/';
        if (!is_dir($customWikiModelDir)) {
            if (!mkdir($customWikiModelDir, 0777, true)) {
                throw new Exception('Folder creation failed...');
            } else {
                $output .= "Creating the folder $customWikiModelDir for the wiki models ✅ Done!\n";
            }
        } else {
            $output .= "✅ The folder $customWikiModelDir for the wiki models exists.\n";
        }

        // if the OuiNon list doesn't exist, create it
        $output .= 'Adding the "Oui Non" list ';
        if (!$pageManager->getOne('ListeOuiNon')) {
            // save the page with the list value
            $pageManager->save('ListeOuiNon', $this->loadFileContent('lists', 'ListeOuiNon'));
            // in case, there is already some triples for 'ListOuinonLms', delete them
            $tripleStore->delete('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', null);
            // create the triple to specify this page is a list
            $tripleStore->create('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', 'liste', '', '');
            $output .= '✅ Done!' . "\n";
        } else {
            $output .= '✅ Already existing.' . "\n";
        }

        // test if the FARM form exists, if not, install it
        $formDescription = json_decode($this->loadFileContent('forms', 'Farm description'), true);
        $formTemplate = $this->loadFileContent('forms', 'Farm template');
        $formTemplate = str_replace('{UtilisationDonnees}', $this->wiki->Href('', 'UtilisationDonnees'), $formTemplate);
        $formTemplate = str_replace('{Contact}', $this->wiki->Href('', 'Contact'), $formTemplate);
        if (empty($formTemplate)) {
            $output .= "ERROR! Not possible to add farm form!\n";
        } else {
            $output = $this->checkAndAddForm(
                $output,
                $this->params->get('bazar_farm_id'),
                $formDescription['FARM_FORM_NOM'],
                $formDescription['FARM_FORM_DESCRIPTION'],
                $formTemplate
            );
        }

        if (empty($pageManager->getOne('AdminWikis'))) {
            $output = $this->updatePageRapideHaut($output);
        }
        $output = $this->updatePage('AdminWikis', $output);
        $output = $this->updatePage('AjouterWiki', $output, ['{FarmFormId}' => $this->params->get('bazar_farm_id')]);
        // $output = $this->updatePage('ContactWikis', $output);
        $output = $this->updatePage('ModelesWiki', $output);

        // remove bf_dossier fields
        if (method_exists(EntryManager::class, 'removeAttributes')) {
            $output .= "Removing bf_dossier fields from bazar entries in {$this->dbService->prefixTable('pages')} table. ";
            if ($entryManager->removeAttributes([], ['bf_dossier-wiki_wikiname', 'bf_dossier-wiki_email', 'bf_dossier-wiki_password'], true)) {
                $output .= '✅ Done!' . "\n";
            } else {
                $output .= "✅ Already free of bf_dossier fields in all entries!\n";
            }
        }

        echo $output;
    }

    private function checkAndAddForm($output, $formId, $formName, $formDescription, $formTemplate)
    {
        $formManager = $this->getService(FormManager::class);

        // test if the FARM form exists, if not, install it
        $form = $formManager->getOne($formId);
        $output .= "Adding {$formName} form into {$this->dbService->prefixTable('nature')} table. ";
        if (empty($form)) {
            $formManager->create([
                'bn_id_nature' => $formId,
                'bn_label_nature' => $formName,
                'bn_template' => $formTemplate,
                'bn_description' => $formDescription,
                'bn_sem_context' => $formDescription,
                'bn_sem_type' => '',
                'bn_sem_use_template' => '1',
                'bn_condition' => '',
            ]);
            $output .= '✅ Done!' . "\n";
        } else {
            $output .= "✅ The form already exists.\n";
        }

        return $output;
    }

    private function loadFileContent(string $type, string $name): string
    {
        if (!isset(self::PATHS[$type]) || !isset(self::PATHS[$type][$name])) {
            return '';
        }
        $path = self::PATHS[$type][$name];

        return file_get_contents($path);
    }

    private function updatePage(string $pageName, string $output, array $replacements = []): string
    {
        $aclService = $this->getService(AclService::class);
        $pageManager = $this->getService(PageManager::class);
        // if the page doesn't exist, create it with a default version
        $output .= "Adding the $pageName page ";
        if (!$pageManager->getOne($pageName)) {
            $content = $this->loadFileContent('pages', $pageName);
            if (!empty($replacements)) {
                $content = str_replace(array_keys($replacements), array_values($replacements), $content);
            }
            $aclService->delete($pageName); // to clear acl cache
            $aclService->save($pageName, 'read', '@admins');
            $aclService->save($pageName, 'write', '@admins');
            $pageManager->save($pageName, $content, '', true);
            $output .= '✅ Done!' . "\n";
        } else {
            $output .= "✅ Page already exists.\n";
        }

        return $output;
    }

    private function updatePageRapideHaut(string $output): string
    {
        $pageManager = $this->getService(PageManager::class);
        // if the page doesn't exist, error
        $pageRapideHaut = $pageManager->getOne('PageRapideHaut');
        if (empty($pageRapideHaut)) {
            $output .= "The PageRapideHaut page does not exist.\n";
        } else {
            $output .= 'Adding menu item in PageRapideHaut for the farm ';
            if (!strstr($pageRapideHaut['body'], 'AdminWikis')) {
                $content = $this->loadFileContent('pages', 'PageRapideHaut');
                $pageManager->save('PageRapideHaut', str_replace('{{end elem="buttondropdown"}}', "$content\n{{end elem=\"buttondropdown\"}}", $pageRapideHaut['body']), '', true);
                $output .= '✅ Done!' . "\n";
            } else {
                $output .= "✅ The item already exists.\n";
            }
        }

        return $output;
    }
}
