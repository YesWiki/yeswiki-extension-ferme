<?php

namespace YesWiki\Ferme\Field;

use Psr\Container\ContainerInterface;
use YesWiki\Bazar\Field\BazarField;
use YesWiki\Ferme\Service\FarmService;

/**
 * add fields to create custom yeswiki instance on a yeswiki farm
 * yeswiki***bf_dossier-wiki***L\'adresse du site wiki***bf_mail***
 *
 * @Field({"yeswiki"})
 */
class YesWikiField extends BazarField
{
    protected $emailField;

    protected const FIELD_EMAIL_FIELD = 3;

    public function __construct(array $values, ContainerInterface $services)
    {
        parent::__construct($values, $services);

        $this->emailField = $values[self::FIELD_EMAIL_FIELD];
    }

    public function renderInput($entry)
    {
        $farm = $GLOBALS['wiki']->services->get(FarmService::class);
        $farm->initFarmConfig();
        return $this->render("@ferme/inputs/yeswiki.twig", [
            'value' => $this->getValue($entry),
            'rootUrl' => $GLOBALS['wiki']->config['yeswiki-farm-root-url'],
            'defaultWikiAdmin' => $GLOBALS['wiki']->config['yeswiki-farm-default-WikiAdmin'],
        ]);

        if (empty($this->wiki->config['yeswiki-farm-email-WikiAdmin'])) {
            $extrafields .= '
            <input type="email" class="form-control" id="'. $tableau_template[1].'_email' . '" name="'
            . $tableau_template[1].'_email'.'" required placeholder="Email de l\'admin">';
        } else {
            $extrafields .= '<input type="hidden" class="form-control" '
                .'name="'.$tableau_template[1].'_email'.'" value="'
                .htmlspecialchars($this->wiki->config['yeswiki-farm-email-WikiAdmin']).'"><br>';
        }
        if (empty($this->wiki->config['yeswiki-farm-password-WikiAdmin'])) {
            $extrafields .= '<input type="password" class="form-control" id="'.$tableau_template[1].'_password"'
                .' name="'.$tableau_template[1].'_password'.'" required '
                .'placeholder="Mot de passe de l\'admin"><br>';
        } else {
            $extrafields .= '<input type="hidden" class="form-control" '
                .'name="'.$tableau_template[1].'_password'.'" value="'
                .htmlspecialchars($this->wiki->config['yeswiki-farm-password-WikiAdmin']).'">';
        }

        // theme utilise
        if (count($this->wiki->config["yeswiki-farm-themes"])>1) {
            $themepreview = '<div id="yeswiki-farm-theme-imgs" style="width:400px;margin: 10px 0 20px;">';
            $extrafields .= '<h5>Thème graphique</h5>'.'<select id="yeswiki-farm-theme" class="form-control" name="yeswiki-farm-theme">';
            $first = true;
            foreach ($this->wiki->config["yeswiki-farm-themes"] as $key => $theme) {
                $extrafields .= '<option value="'.$key.'">'.$theme["label"].'</option>'."\n";
                if ($first) {
                    $first = false;
                    $hidden = '';
                } else {
                    $hidden = ' hide';
                }
                $themepreview .= '<img class="img-responsive'.$hidden.'" src="tools/ferme/screenshots/'.$theme["screenshot"].'">'."\n";
            }
            $extrafields .= '</select>'.$themepreview.'</div>'."\n";
            $js = "$('#yeswiki-farm-theme').on('change', function() {
                $('#yeswiki-farm-theme-imgs img').addClass('hide');
                $('#yeswiki-farm-theme-imgs img:eq('+parseInt($(this).val())+')').removeClass('hide');
            });";
            $this->wiki->addJavascript($js);
        } else {
            $extrafields .= '<input type="hidden" name="yeswiki-farm-theme" value="0">'."\n";
        }

        // modele de wiki utilisé
        if (count($this->wiki->config["yeswiki-farm-sql"])>1) {
            $extrafields .= '<h5>Type de wiki</h5>'.'<select id="yeswiki-farm-sql" class="form-control" name="yeswiki-farm-sql">';
            foreach ($this->wiki->config["yeswiki-farm-sql"] as $key => $sql) {
                $extrafields .= '<option value="'.$key.'">'.$sql["label"].'</option>'."\n";
            }
            $extrafields .= '</select>'."\n";
        } else {
            $extrafields .= '<input type="hidden" name="yeswiki-farm-sql" value="0">'."\n";
        }

        // droits d'acces
        if (count($this->wiki->config["yeswiki-farm-acls"])>1) {
            $extrafields .= '<h5>Droits d\'accès</h5>'.'<select id="yeswiki-farm-acls" class="form-control" name="yeswiki-farm-acls">';
            foreach ($this->wiki->config["yeswiki-farm-acls"] as $key => $acl) {
                if (isset($acl['create_user']) && $acl['create_user'] == true) {
                    $class = ' class="create_user"';
                } else {
                    $class = '';
                }
                $extrafields .= '<option value="'.$key.'"'.$class.'>'.$acl["label"].'</option>'."\n";
            }
            $extrafields .= '</select>'."\n".'<div id="access-control" class="hide">'
            .'<input type="text" class="form-control" name="access-username" required placeholder="Identifiant d\'accès au wiki (2 majuscules, par ex: NomWiki)">'."\n"
            .'<input type="password" class="form-control" name="access-password" required placeholder="Mot de passe d\'accès au wiki">'."\n"
            .'</div>'."\n";
            $js = "$('#yeswiki-farm-acls').on('change', function() {
                if ($(this).find('option:selected').hasClass('create_user')) {
                $('#access-control').removeClass('hide');
                } else {
                $('#access-control').addClass('hide');
                }
            });";
            $this->wiki->addJavascript($js);
        } else {
            $extrafields .= '<input type="hidden" name="yeswiki-farm-acls" value="0">'."\n";
        }

        // options supplementaires
        if (isset($this->wiki->config["yeswiki-farm-options"]) and count($this->wiki->config["yeswiki-farm-options"])>1) {
            foreach ($this->wiki->config["yeswiki-farm-options"] as $key => $option) {
                $extrafields .= '<div class="checkbox">'."\n"
                    .'<label>'."\n"
                    .'<input type="checkbox"'.($option['checked'] ? ' checked' : '').' name="yeswiki-farm-options['.$key.']" value="'.$key.'">'
                    .$option['label']."\n"
                    .'</label>'."\n"
                    .'</div>'."\n";
            }
        }
    }

    public function formatValuesBeforeSave($entry)
    {
        // Here you can perform operations on each create/update operation
        if (!empty($valeurs_fiche[$tableau_template[1]])
            && preg_match('/^[0-9a-zA-Z-_]*$/', $valeurs_fiche[$tableau_template[1]])) {
            if (isset($valeurs_fiche[$tableau_template[1].'_exists'])
                && $valeurs_fiche[$tableau_template[1].'_exists'] == 1) {
                // si le wiki a déja été créé on zappe
            } else {
                if ($valeurs_fiche[$tableau_template[1].'_wikiname'] == '{{folder}}') {
                    $valeurs_fiche[$tableau_template[1].'_wikiname'] = genere_nom_wiki(
                        $valeurs_fiche[$tableau_template[1]],
                        0
                    );
                    if ($this->wiki->LoadUser($valeurs_fiche[$tableau_template[1].'_wikiname'])) {
                        die('L\'utilisateur '.$valeurs_fiche[$tableau_template[1].'_wikiname']
                            .' existe déjà, veuillez trouver un autre nom pour votre wiki.');
                    }
                }

                // creation d'un user?
                if ($this->wiki->config['yeswiki-farm-create-user']) {
                    if ($this->wiki->LoadUser($valeurs_fiche[$tableau_template[1].'_wikiname'])) {
                        die('L\'utilisateur '.$valeurs_fiche[$tableau_template[1].'_wikiname']
                            .' existe déjà, veuillez trouver un autre nom pour votre wiki.');
                    }
                    $this->wiki->Query(
                        "insert into ".$this->wiki->config["table_prefix"]."users set ".
                        "signuptime = now(), ".
                        "name = '".mysqli_real_escape_string($this->wiki->dblink, $valeurs_fiche[$tableau_template[1].'_wikiname'])."', ".
                        "email = '".mysqli_real_escape_string($this->wiki->dblink, $valeurs_fiche[$tableau_template[1].'_email'])."', ".
                        "password = md5('".mysqli_real_escape_string($this->wiki->dblink, $valeurs_fiche[$tableau_template[1].'_password'])."')"
                    );
                }

                $url = $this->wiki->config['yeswiki-farm-root-url'].$valeurs_fiche[$tableau_template[1]];
                $srcfolder = getcwd().DIRECTORY_SEPARATOR;
                $destfolder = getcwd().DIRECTORY_SEPARATOR.$this->wiki->config['yeswiki-farm-root-folder']
                            .DIRECTORY_SEPARATOR.$valeurs_fiche[$tableau_template[1]].DIRECTORY_SEPARATOR;

                // test l'existence du dossier choisi
                if (is_dir($destfolder)) {
                    die('L\'adresse '.$url.' est déja utilisée, veuillez en prendre une autre.');
                } else {
                    // on copie les fichier du wiki si l'on a accès en écriture
                    if (is_writable($this->wiki->config['yeswiki-farm-root-folder'])) {
                        // le repertoire racine et les fichiers de la racine
                        mkdir($destfolder);
                        mkdir($destfolder.'cache');
                        mkdir($destfolder.'files');
                        mkdir($destfolder.'custom');
                        mkdir($destfolder.'templates');
                        mkdir($destfolder.'themes');
                        mkdir($destfolder.'tools');
                        
                        // main yeswiki files
                        foreach ($this->wiki->config['yeswiki-files'] as $file) {
                            copyRecursive($srcfolder.$file, $destfolder.$file);
                        }

                        // extra themes
                        foreach ($this->wiki->config['yeswiki-farm-extra-themes'] as $themes) {
                            copyRecursive(
                                $srcfolder.'themes'.DIRECTORY_SEPARATOR.$themes,
                                $destfolder.'themes'.DIRECTORY_SEPARATOR.$themes
                            );
                        }

                        // extensions supplémentaires
                        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] as $tools) {
                            copyRecursive(
                                $srcfolder.'tools'.DIRECTORY_SEPARATOR.$tools,
                                $destfolder.'tools'.DIRECTORY_SEPARATOR.$tools
                            );
                        }

                        // droits d'accès par aux pages
                        $rights = $this->wiki->config['yeswiki-farm-acls'][$valeurs_fiche['yeswiki-farm-acls']];
                        if ($rights["write"] == '{{user}}') {
                            if (!empty($valeurs_fiche["access-username"])) {
                                $rights["write"] = $valeurs_fiche["access-username"];
                            } else {
                                $rights["write"] = $valeurs_fiche[$tableau_template[1].'_wikiname'];
                            }
                        }
                        if ($rights["read"] == '{{user}}') {
                            if (!empty($valeurs_fiche["access-username"])) {
                                $rights["read"] = $valeurs_fiche["access-username"];
                            } else {
                                $rights["read"] = $valeurs_fiche[$tableau_template[1].'_wikiname'];
                            }
                        }
                        if ($rights["comments"] == '{{user}}') {
                            if (!empty($valeurs_fiche["access-username"])) {
                                $rights["comments"] = $valeurs_fiche["access-username"];
                            } else {
                                $rights["comments"] = $valeurs_fiche[$tableau_template[1].'_wikiname'];
                            }
                        }

                        // theme choisi
                        $theme = $this->wiki->config['yeswiki-farm-themes'][$_POST['yeswiki-farm-theme']];
                        $this->wiki->config['yeswiki-farm-fav-theme'] = $theme['theme'];
                        $this->wiki->config['yeswiki-farm-fav-style'] = $theme['style'];
                        $this->wiki->config['yeswiki-farm-fav-squelette'] = $theme['squelette'];
                        $this->wiki->config['yeswiki-farm-bg-img'] = isset($theme['bg-img']) ? $theme['bg-img'] : '';

                        // generation du prefixe
                        $prefix = empty($valeurs_fiche['bf_prefixe']) ?
                            $this->wiki->config['yeswiki-farm-prefix'].str_replace('-', '_', $valeurs_fiche[$tableau_template[1]]) . '__' :
                            $valeurs_fiche['bf_prefixe'];

                        // ecriture du fichier de configuration
                        $config = array(
                              'wakka_version' => $this->wiki->config['wakka_version'],
                              'wikini_version' => $this->wiki->config['wikini_version'],
                              'yeswiki_version' => $this->wiki->config['yeswiki_version'],
                              'yeswiki_release' => $this->wiki->config['yeswiki_release'],
                              'debug' => $this->wiki->config['debug'],
                              'mysql_host' => $this->wiki->config['mysql_host'],
                              'mysql_database' => $this->wiki->config['mysql_database'],
                              'mysql_user' => $this->wiki->config['mysql_user'],
                              'mysql_password' => $this->wiki->config['mysql_password'],
                              'table_prefix' => $prefix,
                              'root_page' => $this->wiki->config['yeswiki-farm-homepage'],
                              'wakka_name' => addslashes($valeurs_fiche['bf_titre']),
                              'base_url' => $this->wiki->config['yeswiki-farm-root-url']
                                            .$valeurs_fiche[$tableau_template[1]].'/?',
                              'rewrite_mode' => $this->wiki->config['rewrite_mode'],
                              'meta_keywords' => $this->wiki->config['meta_keywords'],
                              'meta_description' => $this->wiki->config['meta_description'],
                              'action_path' => 'actions',
                              'handler_path' => 'handlers',
                              'header_action' => 'header',
                              'footer_action' => 'footer',
                              'navigation_links' => $this->wiki->config['navigation_links'],
                              'referrers_purge_time' => $this->wiki->config['referrers_purge_time'],
                              'pages_purge_time' => $this->wiki->config['pages_purge_time'],
                              'default_write_acl' => $rights["write"],
                              'default_read_acl' => $rights["read"],
                              'default_comment_acl' => $rights["comments"],
                              'preview_before_save' => $this->wiki->config['preview_before_save'],
                              'allow_raw_html' => $this->wiki->config['allow_raw_html'],
                              'default_language' => $this->wiki->config['default_language'],
                              'favorite_theme' => $this->wiki->config['yeswiki-farm-fav-theme'],
                              'favorite_style' => $this->wiki->config['yeswiki-farm-fav-style'],
                              'favorite_squelette' => $this->wiki->config['yeswiki-farm-fav-squelette'],
                              'favorite_background_image' => $this->wiki->config['yeswiki-farm-bg-img'],
                              'source_url' =>  $this->wiki->href('', $valeurs_fiche['id_fiche']),
                              'db_charset' =>  'utf8mb4',
                        );
                        if (isset($this->wiki->config['yeswiki-farm-extra-config'])
                            and is_array($this->wiki->config['yeswiki-farm-extra-config'])
                        ) {
                            $config = array_merge($config, $this->wiki->config['yeswiki-farm-extra-config']);
                        }

                        if (isset($valeurs_fiche['bf_description'])) {
                            $config['meta_description'] = addslashes(
                                substr(
                                    str_replace('<br>', ' ', strip_tags($valeurs_fiche['bf_description'], '<br>')),
                                    0,
                                    150
                                )
                            );
                        }

                        // convert config array into PHP code
                        $configCode = "<?php\n// wakka.config.php "._t('CREATED')." ".strftime("%c")."\n// ".
                                        _t('DONT_CHANGE_YESWIKI_VERSION_MANUALLY')." !\n\n\$wakkaConfig = ";
                        $configCode .= var_export($config, true) . ";\n?>";

                        if ($fp = @fopen($destfolder.'wakka.config.php', "w")) {
                            fwrite($fp, $configCode);
                            // write
                            fclose($fp);
                        } else {
                            die('Ecriture du fichier de configuration impossible');
                        }
                        // creation des tables de la base de données
                        /* create sql connection*/
                        $link = mysqli_connect(
                            $GLOBALS["wiki"]->config['mysql_host'],
                            $GLOBALS["wiki"]->config['mysql_user'],
                            $GLOBALS["wiki"]->config['mysql_password'],
                            $GLOBALS["wiki"]->config['mysql_database'],
                            isset($GLOBALS["wiki"]->config['mysql_port']) ? $GLOBALS["wiki"]->config['mysql_port'] : ini_get("mysqli.default_port")
                        );
                        // necessaire pour les versions de mysql qui ont un autre encodage par defaut
                        mysqli_set_charset($link, 'utf8mb4');

                        // dans certains cas (ovh), set_charset ne passe pas, il faut faire une requete sql
                        $charset = mysqli_character_set_name($link);
                        if ($charset != 'utf8mb4') {
                            mysqli_query($link, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
                        }

                        if ($sql = file_get_contents('setup/sql/create-tables.sql')) {
                            $sql = str_replace(
                                '{{prefix}}',
                                $prefix,
                                $sql
                            );
                            $sql = str_replace('{{WikiName}}', $valeurs_fiche[$tableau_template[1].'_wikiname'], $sql);
                            $sql = str_replace('{{password}}', $valeurs_fiche[$tableau_template[1].'_password'], $sql);
                            $sql = str_replace('{{email}}', $valeurs_fiche[$tableau_template[1].'_email'], $sql);
                           
                            /* Execute queries */
                            $sql_report = '<strong>Rapport create-tables.sql</strong><br/>';
                            $queries = [];
                            preg_match_all('/^.*?;$(?:\r\n|\n)/sm', $sql, $queries);
                            foreach ($queries[0] as $index => $query) {
                                mysqli_query($link, $query) or die('Erreur à l\'insertion n°' . ($index + 1) . ' : ' . mysqli_error($link));
                                $sql_report .= 'Insertion n°' . ($index + 1) . ' : ' . mysqli_affected_rows($link) . ' ligne(s) affectée(s)<br/>';
                            }
                            $sql_report .= '<br/>';
                        } else {
                            die('Lecture du fichier sql "setup/sql/create-tables.sql" impossible');
                        }
                        // insertion des donnees du modele dans la base de donnees
                        $sqlfile = $this->wiki->config['yeswiki-farm-sql'][$_POST['yeswiki-farm-sql']]['file'];
                        if ($sql = file_get_contents('custom/wiki-models/'.$sqlfile)) {
                            $sql = str_replace(
                                '{{prefix}}',
                                $prefix,
                                $sql
                            );
                            $sql = str_replace('{{WikiName}}', $valeurs_fiche[$tableau_template[1].'_wikiname'], $sql);
                            $sql = str_replace('{{password}}', $valeurs_fiche[$tableau_template[1].'_password'], $sql);
                            $sql = str_replace('{{email}}', $valeurs_fiche[$tableau_template[1].'_email'], $sql);
                            $sql = str_replace('{{foldername}}', $valeurs_fiche[$tableau_template[1]], $sql);

                            /* Execute queries */
                            $sql_report .= '<strong>Rapport ' . $sqlfile . '</strong><br/>';
                            $queries = [];
                            preg_match_all('/^.*?\);$(?:\r\n|\n)/sm', $sql, $queries);
                            foreach ($queries[0] as $index => $query) {
                                mysqli_query($link, $query) or die('Erreur à l\'insertion n°' . ($index + 1) . ' : ' . mysqli_error($link));
                                $sql_report .= 'Insertion n°' . ($index + 1) . ' : ' . mysqli_affected_rows($link) . ' ligne(s) affectée(s)<br/>';
                            }

                            if (!empty($_GET['debug'])) {
                                $GLOBALS["wiki"]->SetMessage($sql_report);
                            }
                        } else {
                            die('Lecture du fichier sql "custom/wiki-models/'.$sqlfile.'" impossible');
                        }

                        if (!empty($valeurs_fiche["access-username"])) {
                            $GLOBALS["wiki"]->Query("INSERT INTO `".$prefix."__users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES ('".$valeurs_fiche["access-username"]."', md5('".$valeurs_fiche["access-password"]."'), '".$valeurs_fiche[$tableau_template[1].'_email']."', '', '20', '50', 1, now(), 2);");
                        }

                        if (!empty($valeurs_fiche["yeswiki-farm-options"])) {
                            $taboptions = explode(',', $valeurs_fiche["yeswiki-farm-options"]);
                            foreach ($taboptions as $option) {
                                $GLOBALS["wiki"]->Query('UPDATE `'.$prefix.'__pages` SET body=CONCAT(body, "'.utf8_decode($this->wiki->config['yeswiki-farm-options'][$option]['content']).'") WHERE tag="'.$this->wiki->config['yeswiki-farm-options'][$option]['page'].'" AND latest="Y";');
                            }
                        }
                    } else {
                        die('Le dossier '.$this->wiki->config['yeswiki-farm-root-folder']
                            .' n\'est pas accessible en écriture');
                    }
                }
            }

            // creation d'un groupe et ajout des membres
            if (is_array($this->wiki->config['yeswiki-farm-group'])) {
                // generation du prefixe
                $tripletable = $this->wiki->config['yeswiki-farm-prefix'].str_replace('-', '_', $valeurs_fiche[$tableau_template[1]]).'__triples';

                // on efface les anciennes valeurs du groupe
                $remsql = 'DELETE FROM `'.$tripletable
                  .'` WHERE `resource`="ThisWikiGroup:'.$this->wiki->config['yeswiki-farm-group']['groupname']
                       .'" and `property`="http://www.wikini.net/_vocabulary/acls";';
                $this->wiki->Query($remsql);

                // on ajoute les nouvelles valeurs du groupe
                $users = $valeurs_fiche[$this->wiki->config['yeswiki-farm-group']['group_members_field']];
                $addsql = 'INSERT INTO `'.$tripletable.'` (`resource`, `property`, `value`)'
                  .' VALUES (\'ThisWikiGroup:'.$this->wiki->config['yeswiki-farm-group']['groupname'].'\','
                  .' \'http://www.wikini.net/_vocabulary/acls\', \''.implode("\n", explode(',', $users)).'\');';
                $this->wiki->Query($addsql);
            }

            return array(
                $tableau_template[1] => $valeurs_fiche[$tableau_template[1]]
            );
        } else {
            die('La valeur '.$valeurs_fiche[$tableau_template[1]]
                .' n\'est pas valide, il faut des caractères alphanumérique et des tirets (- _) uniquement.');
        }
    }

    public function renderStatic($entry)
    {
        $value = $this->getValue($entry);
        if ($value && !empty($GLOBALS['wiki']->config['yeswiki-farm-root-url'])) {
            $url = $GLOBALS['wiki']->config['yeswiki-farm-root-url'].$value;
            return $this->render("@ferme/yeswiki-field.twig", [
                'url' => $url
            ]);
        } else {
            return null;
        }
    }
}
