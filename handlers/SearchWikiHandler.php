<?php
/**
 * search the server for new wikis to add to the wiki list
 * needed ones.
 *
 * @category YesWiki
 * @package  ferme
 * @author   Florian Schmitt <mrflos@lilo.org>
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 * @link     https://yeswiki.net
 */

namespace YesWiki\Ferme;

use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\YesWikiHandler;

class SearchWikiHandler extends YesWikiHandler
{
    public function run()
    {
        if ($this->wiki->UserIsAdmin()) {
            $adminMail = $this->wiki->GetUser()['email'];
            $output = '';
            $entryManager = $this->wiki->services->get(EntryManager::class);
            $pageManager = $this->wiki->services->get(PageManager::class);
            $tripleStore = $this->wiki->services->get(TripleStore::class);
            $wikis = $entryManager->search([
                'formsIds' => [ $this->params->get('bazar_farm_id') ]
            ]);
            $wikisFolder = array_column($wikis, 'bf_dossier-wiki');
            $wikisOnServer = glob(\getcwd()."/*/wakka.config.php");
            $wikisToImport = [];
            $output = '<ol>';
            foreach ($wikisOnServer as $path) {
                $folder = str_replace([getcwd().'/', '/wakka.config.php'], '', $path);
                $wikiExistsInBazar = \in_array($folder, $wikisFolder);
                $output .= '<li>';
                $wakkaConfig = [];
                include $path;
                $url = $wakkaConfig['base_url'].$wakkaConfig['root_page'];
                $output .= '<a href="'.$url.'" target="_blank">'.$folder.'</a> ';
                if (!$wikiExistsInBazar) {
                    $output .= 'est importé dans la ferme.';
                } else {
                    $output .= 'existe déja dans la ferme.';
                }
                $conn = new \mysqli($wakkaConfig['mysql_host'], $wakkaConfig['mysql_user'], $wakkaConfig['mysql_password'], $wakkaConfig['mysql_database']);
                if ($conn->connect_error) {
                    $output .= 'Connexion SQL KO : ' . $conn->connect_error;
                } else {
                    $output .= ' - Connexion SQL OK';
                    $tables = ['acls','links','nature','pages','referrers','triples','users'];
                    $allTablesfound = true;
                    foreach ($tables as $table) {
                        $result = mysqli_query($conn, 'SHOW TABLES LIKE "'.$wakkaConfig['table_prefix'].'acls"');
                        if (mysqli_num_rows($result) == 0) {
                            $output .= ' - Table '.$table.' non trouvée';
                            $allTablesfound = false;
                        }
                    }
                    if ($allTablesfound) {
                        $output .= ' - Toutes les tables sont présentes';
                    }
                }

                $headers = @get_headers($url);
                if ($headers && strpos($headers[0], '200')) {
                    $status = ' - La page d\'accueil répond.';
                } else {
                    $status = ' - La page d\'accueil ne répond pas...';
                }
                $output .= $status;
                if (!$wikiExistsInBazar) {
                    $wikisToImport[] = [
                        "id_fiche" => genere_nom_wiki($wakkaConfig['wakka_name']),
                        "id_typeannonce" => strval($this->params->get('bazar_farm_id')),
                        "bf_titre" => $wakkaConfig['wakka_name'],
                        "bf_description" => $wakkaConfig['meta_description'],
                        "bf_referent" => 'À préciser (importé)',
                        "bf_mail" => $adminMail,
                        "bf_dossier-wiki" => $folder,
                        "radioListeOuiNon" => 'oui',
                        "imagebf_image" => 'wiki-imported-placeholder.png',
                        "date_creation_fiche" => date("Y-m-d H:i:s"),
                        "statut_fiche" => "1",
                        "date_maj_fiche" => date("Y-m-d H:i:s")
                    ];
                }
                $output .= '</li>';
            }
            $output .= '</ol>';
            # wikis to import!
            if (count($wikisToImport) > 0) {
                if (!file_exists('files/wiki-imported-placeholder.png')) {
                    copy(
                        'tools/ferme/images/wiki-imported-placeholder.png',
                        'files/wiki-imported-placeholder.png'
                    );
                }
                foreach ($wikisToImport as $w) {
                    // on sauve les valeurs d'une fiche dans une PageWiki, retourne 0 si succès
                    $saved = $pageManager->save(
                        $w['id_fiche'],
                        json_encode($w),
                        '',
                        true // Ignore les ACLs
                    );

                    // on cree un triple pour specifier que la page wiki creee est une fiche
                    // bazar
                    if ($saved == 0) {
                        $tripleStore->create(
                            $w['id_fiche'],
                            TripleStore::TYPE_URI,
                            'fiche_bazar',
                            '',
                            ''
                        );
                    }
                }
            }

            return $this->renderInSquelette('@ferme/searchwiki.twig', [
                'output' => $output,
                'wikis' => $wikis,
                'wikisonserver' => $wikisOnServer
            ]);
        }
    }
}
