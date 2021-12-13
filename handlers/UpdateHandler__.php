<?php
/**
 * Handler called after the 'update' handler. to install the farm database template and create default pages
 * needed ones.
 *
 * @category YesWiki
 * @package  ferme
 * @author   Adrien Cheype <adrien.cheype@gmail.com>
 * @author   Florian Schmitt <mrflos@lilo.org>
 * @author   Jérémy Dufraisse <jeremy.dufraisse-info@orange.fr>
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 * @link     https://yeswiki.net
 */

namespace YesWiki\Ferme;

use Exception;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\DbService;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Core\YesWikiHandler;

class UpdateHandler__ extends YesWikiHandler
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
            // 'ContactWikis' => 'tools/ferme/setup/pages/ContactWikis.txt',
            'ModelesWiki' => 'tools/ferme/setup/pages/ModelesWiki.txt',
            'PageRapideHaut' => 'tools/ferme/setup/pages/PageRapideHaut.txt',
        ],
    ];

    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $pageManager = $this->getService(PageManager::class);
            $tripleStore = $this->getService(TripleStore::class);
            $dbService = $this->getService(DbService::class);
            $entryManager = $this->getService(EntryManager::class);

            $output .= '<strong>Extension Ferme</strong><br/>';
        
            // Structure de répertoire désirée
            $customWikiModelDir = 'custom/wiki-models/';
            if (!is_dir($customWikiModelDir)) {
                if (!mkdir($customWikiModelDir, 0777, true)) {
                    throw new Exception('Folder creation failed...');
                } else {
                    $output .= "ℹ️ Creating the folder <em>$customWikiModelDir</em> for the wiki models<br/>✅Done !<br />";
                }
            } else {
                $output .= "✅ The folder <em>$customWikiModelDir</em> for the wiki models exists.<br />";
            }
        
        
            // if the OuiNon Lms list doesn't exist, create it
            if (!$pageManager->getOne('ListeOuiNon')) {
                $output .= 'ℹ️ Adding the <em>Oui Non</em> list<br />';
                // save the page with the list value
                $pageManager->save('ListeOuiNon', $this->loadFileContent('lists', 'ListeOuiNon'));
                // in case, there is already some triples for 'ListOuinonLms', delete them
                $tripleStore->delete('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', null);
                // create the triple to specify this page is a list
                $tripleStore->create('ListeOuiNon', 'http://outils-reseaux.org/_vocabulary/type', 'liste', '', '');
                $output .= '✅ Done !<br />';
            } else {
                $output .= '✅ The <em>Oui Non</em> list already exists.<br />';
            }
        
            // test if the FARM form exists, if not, install it
            $formDescription = json_decode($this->loadFileContent('forms', 'Farm description'), true);
            $formTemplate = $this->loadFileContent('forms', 'Farm template');
            $formTemplate = str_replace('{UtilisationDonnees}', $this->wiki->Href('', 'UtilisationDonnees'), $formTemplate);
            $formTemplate = str_replace('{Contact}', $this->wiki->Href('', 'Contact'), $formTemplate);
            if (empty($formTemplate)) {
                $output .= "! not possible to add <em>famr</em> form !<br />";
            } else {
                $output = $this->checkAndAddForm(
                    $output,
                    $this->params->get('bazar_farm_id'),
                    $formDescription["FARM_FORM_NOM"],
                    $formDescription["FARM_FORM_DESCRIPTION"],
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
                if ($entryManager->removeAttributes([], ['bf_dossier-wiki_wikiname','bf_dossier-wiki_email','bf_dossier-wiki_password'], true)) {
                    $output .= "ℹ️ Removing bf_dossier fields from bazar entries in {$dbService->prefixTable('pages')} table.<br />";
                    $output .= '✅ Done !<br />';
                } else {
                    $output .= "✅ The table {$dbService->prefixTable('pages')} is already free of bf_dossier fields in bazar entries !<br />";
                }
            } else {
                $output .= "! Not possible to remove bf_dossier fields from bazar entries in {$dbService->prefixTable('pages')} table.<br />";
            }
            
            $output .= '<hr />';
        }
        
        // add the content before footer
        $this->output = str_replace(
            '<!-- end handler /update -->',
            $output.'<!-- end handler /update -->',
            $this->output
        );
    }

    private function checkAndAddForm($output, $formId, $formName, $formDescription, $formTemplate)
    {
        $dbService = $this->getService(DbService::class);
        $formManager = $this->getService(FormManager::class);

        // test if the FARM form exists, if not, install it
        $form = $formManager->getOne($formId);
        if (empty($form)) {
            $output .= "ℹ️ Adding <em>{$formName}</em> form into <em>{$dbService->prefixTable('nature')}</em> table.<br />";

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

            $output .= '✅ Done !<br />';
        } else {
            $output .= "✅ The <em>{$formName}</em> form already exists in the <em>{$dbService->prefixTable('nature')}</em> table.<br />";
        }
        return $output;
    }

    private function loadFileContent(string $type, string $name):string
    {
        if (!isset(self::PATHS[$type]) || !isset(self::PATHS[$type][$name])) {
            return '';
        }
        $path = self::PATHS[$type][$name];
        return file_get_contents($path);
    }

    private function updatePage(string $pageName, string $output, array $replacements = []):string
    {
        $aclService = $this->getService(AclService::class);
        $pageManager = $this->getService(PageManager::class);
        // if the page doesn't exist, create it with a default version
        if (!$pageManager->getOne($pageName)) {
            $output .= "ℹ️ Adding the <em>$pageName</em> page<br />";
            $content = $this->loadFileContent('pages', $pageName);
            if (!empty($replacements)) {
                $content = str_replace(array_keys($replacements), array_values($replacements), $content);
            }
            $aclService->delete($pageName); // to clear acl cache
            $aclService->save($pageName, 'read', '@admins');
            $aclService->save($pageName, 'write', '@admins');
            $pageManager->save($pageName, $content, "", true);
            $output .= '✅ Done !<br />';
        } else {
            $output .= "✅ The <em>$pageName</em> page already exists.<br />";
        }
        return $output;
    }

    private function updatePageRapideHaut(string $output): string
    {
        $pageManager = $this->getService(PageManager::class);
        // if the page doesn't exist, error
        $pageRapideHaut = $pageManager->getOne('PageRapideHaut');
        if (empty($pageRapideHaut)) {
            $output .= "! The <em>$pageName</em> page does not exist.<br />";
        } else {
            if (!strstr($pageRapideHaut['body'], 'AdminWikis')) {
                $content = $this->loadFileContent('pages', 'PageRapideHaut');
                $output .= "ℹ️ Adding menu item in <em>PageRapideHaut</em> for the farm<br />";
                $pageManager->save('PageRapideHaut', str_replace('{{end elem="buttondropdown"}}', "$content\n{{end elem=\"buttondropdown\"}}", $pageRapideHaut['body']), "", true);
                $output .= '✅ Done !<br />';
            } else {
                $output .= "! The menu items in <em>PageRapideHaut</em> already exist.<br />";
            }
        }
        return $output;
    }
}
