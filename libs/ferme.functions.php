<?php
/** initFarmConfig() - test le fichier de configuration et ajoute des valuers par defaut, si besoin
 *
 * @return   void
 */
function initFarmConfig()
{
    // test de l'existence des variables de configuration de la ferme, mise en place de valeurs par défaut sinon
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-root-url'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-root-url'] = str_replace(
            array('wakka.php?wiki=', '?'),
            '',
            $GLOBALS['wiki']->config['base_url']
        );
        $GLOBALS['wiki']->config['yeswiki-farm-root-folder'] = '.';
    } elseif (!isset($GLOBALS['wiki']->config['yeswiki-farm-root-folder'])) {
        exit('<div class="alert alert-danger">Il faut indiquer le chemin relatif des wikis'
            .' avec la valeur "yeswiki-farm-root-folder" dans le fichier de configuration.</div>');
    }
    // themes supplémentaires
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-extra-themes'])
        || !is_array($GLOBALS['wiki']->config['yeswiki-farm-extra-themes'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-extra-themes'] = array();
    }

    // extensions supplémentaires
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-extra-tools'])
        || !is_array($GLOBALS['wiki']->config['yeswiki-farm-extra-tools'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-extra-tools'] = array();
    }

    // theme par defaut
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-themes'])
        or !is_array($GLOBALS['wiki']->config['yeswiki-farm-themes'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-themes'][0]['label'] = 'Yeswiki de base';
        $GLOBALS['wiki']->config['yeswiki-farm-themes'][0]['screenshot'] = 'yeswiki.jpg';
        $GLOBALS['wiki']->config['yeswiki-farm-themes'][0]['theme'] = 'yeswiki';
        $GLOBALS['wiki']->config['yeswiki-farm-themes'][0]['squelette'] = 'responsive-1col.tpl.html';
        $GLOBALS['wiki']->config['yeswiki-farm-themes'][0]['style'] = 'gray.css';
    } else {
        // verifier l'existence des themes
        foreach ($GLOBALS['wiki']->config['yeswiki-farm-themes'] as $key => $theme) {
            if (!isset($theme['label']) or empty($theme['label'])) {
                exit('<div class="alert alert-danger">Au moins un label pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            }
            if (!isset($theme['screenshot']) or empty($theme['screenshot'])) {
                exit('<div class="alert alert-danger">Au moins un screenshot pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            } elseif (!is_file('tools/ferme/screenshots/'.$theme['screenshot'])) {
                exit('<div class="alert alert-danger">Le fichier "tools/ferme/screenshots/'.$theme['screenshot']
                    .'" n\'a pas été trouvé.</div>');
            }
            if (!isset($theme['theme']) or empty($theme['theme'])) {
                exit('<div class="alert alert-danger">Au moins un theme pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            } elseif (!is_dir('themes/'.$theme['theme']) and ($theme['theme'] == "yeswiki" and !is_dir('tools/templates/themes/'.$theme['theme']))) {
                exit('<div class="alert alert-danger">Le dossier "themes/'.$theme['theme']
                    .'" n\'a pas été trouvé.</div>');
            }
            if (!isset($theme['squelette']) or empty($theme['squelette'])) {
                exit('<div class="alert alert-danger">Au moins un squelette pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            } elseif (!is_file('themes/'.$theme['theme'].'/squelettes/'.$theme['squelette']) and ($theme['theme'] == "yeswiki" and !is_file('tools/templates/themes/'.$theme['theme'].'/squelettes/'.$theme['squelette']))) {
                exit('<div class="alert alert-danger">Le squelette "themes/'.$theme['theme']
                    .'/squelettes/'.$theme['squelette'].'" n\'a pas été trouvé.</div>');
            }
            if (!isset($theme['style']) or empty($theme['style'])) {
                exit('<div class="alert alert-danger">Au moins un style css pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            } elseif (!is_file('themes/'.$theme['theme'].'/styles/'.$theme['style']) and ($theme['theme'] == "yeswiki" and !is_file('tools/templates/themes/'.$theme['theme'].'/styles/'.$theme['style']))) {
                exit('<div class="alert alert-danger">Le style css "themes/'.$theme['theme'].'/styles/'.$theme['style']
                    .'" n\'a pas été trouvé.</div>');
            }
        }
    }

    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-bg-img'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-bg-img'] = '';
    }

    // acls
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-acls'])
        or !is_array($GLOBALS['wiki']->config['yeswiki-farm-acls'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-acls'][0]['label'] = 'Wiki ouvert';
        $GLOBALS['wiki']->config['yeswiki-farm-acls'][0]['read'] = '*';
        $GLOBALS['wiki']->config['yeswiki-farm-acls'][0]['write'] = '*';
        $GLOBALS['wiki']->config['yeswiki-farm-acls'][0]['comments'] = '+';
    } else {
        // verifier l'existence des acls
        foreach ($GLOBALS['wiki']->config['yeswiki-farm-acls'] as $key => $acls) {
            if (!isset($acls['label']) or empty($acls['label'])) {
                exit('<div class="alert alert-danger">Au moins un label pour les acls de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            }
            if (!isset($acls['read']) or empty($acls['read'])) {
                exit('<div class="alert alert-danger">Au moins un droit en lecture (read) n\'a'
                    .' pas été bien renseigné.</div>');
            }
            if (!isset($acls['write']) or empty($acls['write'])) {
                exit('<div class="alert alert-danger">Au moins un droit en lecture (write) n\'a'
                    .' pas été bien renseigné.</div>');
            }
            if (!isset($acls['comments']) or empty($acls['comments'])) {
                exit('<div class="alert alert-danger">Au moins un droit des commentaires (comments) n\'a'
                    .' pas été bien renseigné.</div>');
            }
        }
    }

    // sql d'installation par défaut
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-sql'])
        or !is_array($GLOBALS['wiki']->config['yeswiki-farm-sql'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-sql'][0]['label'] = 'Configuration de base';
        $GLOBALS['wiki']->config['yeswiki-farm-sql'][0]['file'] = 'default.sql';

    } else {
        // verifier l'existence des parametres des fichiers sql
        foreach ($GLOBALS['wiki']->config['yeswiki-farm-sql'] as $key => $sql) {
            if (!isset($sql['label']) or empty($sql['label'])) {
                exit('<div class="alert alert-danger">Au moins un label pour les configurations sql de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
            }
            if (!isset($sql['file']) or empty($sql['file'])) {
                exit('<div class="alert alert-danger">Au moins un fichier sql de configuration n\'a'
                    .' pas été bien renseigné.</div>');
            } elseif (!is_file('tools/ferme/sql/'.$sql['file'])) {
                exit('<div class="alert alert-danger">Le fichier sql "tools/ferme/sql/'.$sql['file']
                    .'" n\'a pas été trouvé.</div>');
            }
        }
    }

    // création d'un utilisateur dans le wiki initial (sert pour des cas spécifiques avec une bd centralisée)
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-create-user'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-create-user'] = false;
    }

    // Utilisateur WikiAdmin par défaut (laisser vide pour demander à la création du wiki)
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-default-WikiAdmin'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-default-WikiAdmin'] = 'WikiAdmin';
    }

    // Mot de passe WikiAdmin par défaut (laisser vide pour demander à la création du wiki)
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-password-WikiAdmin'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-password-WikiAdmin'] = '';
    }

    // Email par défaut (laisser vide pour demander à la création du wiki)
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-email-WikiAdmin'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-email-WikiAdmin'] = '';
    }

    // page d'accueil des wikis de la ferme
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-homepage'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-homepage'] = $GLOBALS['wiki']->config['root_page'];
    }

    // prefixe par default
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-prefix'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-prefix'] = 'yeswiki_';
    }

    // admin de la ferme
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-admin-name'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-admin-name'] = '';
    }
    if (!isset($GLOBALS['wiki']->config['yeswiki-farm-admin-pass'])) {
        $GLOBALS['wiki']->config['yeswiki-farm-admin-pass'] = '';
    }
}

function rrmdir($src)
{
    $dir = opendir($src);
    while (false !== ( $file = readdir($dir))) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if (is_dir($full)) {
                rrmdir($full);
            } else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    rmdir($src);
}

function copyRecursive($path, $dest)
{
    if (is_dir($path)) {
        @mkdir($dest);
        $objects = scandir($path);
        if (sizeof($objects) > 0) {
            foreach ($objects as $file) {
                if ($file == "." || $file == ".." || $file == ".git" || $file == "bower_components") {
                    continue;
                }
                // go on
                if (is_dir($path.DIRECTORY_SEPARATOR.$file)) {
                    copyRecursive($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
                } else {
                    copy($path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file);
                }
            }
        }
        return true;
    } elseif (is_file($path)) {
        return copy($path, $dest);
    } else {
        return false;
    }
}

/** yeswiki() - Ajoute un champ permettant de créer des yeswikis supplémentaires (ferme à wiki)
 *
 * @param    mixed   L'objet QuickForm du formulaire
 * @param    mixed   Le tableau des valeurs des différentes option pour l'élément liste
 * @param    string  Type d'action pour le formulaire : saisie, modification, vue,... saisie par défaut
 * @param    mixed  Valeurs de la fiche sélectionné (pour la modif et vue)
 * @return   void
 */
function yeswiki(&$formtemplate, $tableau_template, $mode, $valeurs_fiche)
{
    initFarmConfig();
    if ($mode == 'saisie') {
        $bulledaide = '';
        // echo "<pre>";
        // var_dump($GLOBALS['wiki']->config["yeswiki-farm-sql"]);
        // echo "</pre>";
        if (isset($tableau_template[10]) && $tableau_template[10] != '') {
            $bulledaide = ' &nbsp;&nbsp;<img class="tooltip_aide" title="' .
            htmlentities($tableau_template[10], ENT_QUOTES, TEMPLATES_DEFAULT_CHARSET) .
            '" src="tools/bazar/presentation/images/aide.png" width="16" height="16" alt="image aide" />';
        } else {
            $bulledaide = ' &nbsp;&nbsp;<img class="tooltip_aide" title="'
                ._t('uniquement des caractères alphanumériques et tirets (- et _)')
                .'" src="tools/bazar/presentation/images/aide.png" width="16" height="16" alt="image aide" />';
        }

        $html = '<div class="control-group form-group">' . "\n" . '<label class="control-label col-xs-3">' . "\n";
        if (isset($tableau_template[8]) && $tableau_template[8] == 1) {
            $html.= '<span class="symbole_obligatoire">*&nbsp;</span>' . "\n";
        }

        if (isset($valeurs_fiche[$tableau_template[1]]) && $valeurs_fiche[$tableau_template[1]] != '') {
            $def = $valeurs_fiche[$tableau_template[1]];
            $disable = ' disabled';
            $html .= '<input type="hidden" name="'.$tableau_template[1].'_exists" value="1">';
            $html .= '<input type="hidden" name="'.$tableau_template[1].'" value="'.$def.'">';
        } else {
            $def = '';
            $disable = '';
            $extrafields = '<br>';
            if (empty($GLOBALS['wiki']->config['yeswiki-farm-default-WikiAdmin'])) {
                $extrafields .= '<input type="text" class="form-control" id="'.$tableau_template[1].'_wikiname'
                    .'" name="'.$tableau_template[1].'_wikiname'.'" required placeholder="NomWiki de l\'admin"><br>';
            } else {
                $extrafields .= '<input type="hidden" class="form-control" '
                    .'name="'.$tableau_template[1].'_wikiname'.'" value="'
                    .htmlspecialchars($GLOBALS['wiki']->config['yeswiki-farm-default-WikiAdmin']).'">';
            }
            if (empty($GLOBALS['wiki']->config['yeswiki-farm-email-WikiAdmin'])) {
                $extrafields .= '
                <input type="email" class="form-control" id="'. $tableau_template[1].'_email' . '" name="'
                . $tableau_template[1].'_email'.'" required placeholder="Email de l\'admin">';
            } else {
                $extrafields .= '<input type="hidden" class="form-control" '
                    .'name="'.$tableau_template[1].'_email'.'" value="'
                    .htmlspecialchars($GLOBALS['wiki']->config['yeswiki-farm-email-WikiAdmin']).'"><br>';
            }
            if (empty($GLOBALS['wiki']->config['yeswiki-farm-password-WikiAdmin'])) {
                $extrafields .= '<input type="password" class="form-control" id="'.$tableau_template[1].'_password"'
                    .' name="'.$tableau_template[1].'_password'.'" required '
                    .'placeholder="Mot de passe de l\'admin"><br>';
            } else {
                $extrafields .= '<input type="hidden" class="form-control" '
                    .'name="'.$tableau_template[1].'_password'.'" value="'
                    .htmlspecialchars($GLOBALS['wiki']->config['yeswiki-farm-password-WikiAdmin']).'">';
            }

            // theme utilise
            if (count($GLOBALS['wiki']->config["yeswiki-farm-themes"])>1) {
                $themepreview = '<div id="yeswiki-farm-theme-imgs" style="width:400px;margin: 10px 0 20px;">';
                $extrafields .= '<h5>Thème graphique</h5>'.'<select id="yeswiki-farm-theme" class="form-control" name="yeswiki-farm-theme">';
                $first = true;
                foreach ($GLOBALS['wiki']->config["yeswiki-farm-themes"] as $key => $theme) {
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
                $GLOBALS['wiki']->addJavascript($js);
            } else {
                $extrafields .= '<input type="hidden" name="yeswiki-farm-theme" value="0">'."\n";
            }

            // modele de wiki utilisé
            if (count($GLOBALS['wiki']->config["yeswiki-farm-sql"])>1) {
                $extrafields .= '<h5>Type de wiki</h5>'.'<select id="yeswiki-farm-sql" class="form-control" name="yeswiki-farm-sql">';
                foreach ($GLOBALS['wiki']->config["yeswiki-farm-sql"] as $key => $sql) {
                    $extrafields .= '<option value="'.$key.'">'.$sql["label"].'</option>'."\n";
                }
                $extrafields .= '</select>'."\n";
            } else {
                $extrafields .= '<input type="hidden" name="yeswiki-farm-sql" value="0">'."\n";
            }

            // droits d'acces
            if (count($GLOBALS['wiki']->config["yeswiki-farm-acls"])>1) {
                $extrafields .= '<h5>Droits d\'accès</h5>'.'<select id="yeswiki-farm-acls" class="form-control" name="yeswiki-farm-acls">';
                foreach ($GLOBALS['wiki']->config["yeswiki-farm-acls"] as $key => $acl) {
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
                $GLOBALS['wiki']->addJavascript($js);
            } else {
                $extrafields .= '<input type="hidden" name="yeswiki-farm-acls" value="0">'."\n";
            }

            // options supplementaires
            if (isset($GLOBALS['wiki']->config["yeswiki-farm-options"]) and count($GLOBALS['wiki']->config["yeswiki-farm-options"])>1) {
                foreach ($GLOBALS['wiki']->config["yeswiki-farm-options"] as $key => $option) {
                    $extrafields .= '<div class="checkbox">'."\n"
                      .'<label>'."\n"
                      .'<input type="checkbox"'.($option['checked'] ? ' checked' : '').' name="yeswiki-farm-options['.$key.']" value="'.$key.'">'
                      .$option['label']."\n"
                      .'</label>'."\n"
                      .'</div>'."\n";
                }
            }
        }

        $html .= $tableau_template[2] . $bulledaide . ' : </label>'."\n"
            .'<div class="controls col-xs-8">'."\n"
            .'<div class="input-prepend input-group">'."\n"
            .'<span class="add-on input-group-addon">'.$GLOBALS['wiki']->config['yeswiki-farm-root-url'].'</span>'."\n"
            .'<input type="text" class="form-control" id="'. $tableau_template[1] . '" name="' . $tableau_template[1]
            .'" required'.$disable.' value="'.$def.'" pattern="^[0-9a-zA-Z-]*$" placeholder="nom du site (sans espace, ni caractères spéciaux)">'.'</div>'."\n"
            .$extrafields.'</div>'."\n".'</div>'."\n";
        return $html;
    } elseif ($mode == 'requete') {
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
                    if ($GLOBALS['wiki']->LoadUser($valeurs_fiche[$tableau_template[1].'_wikiname'])) {
                        die('L\'utilisateur '.$valeurs_fiche[$tableau_template[1].'_wikiname']
                            .' existe déjà, veuillez trouver un autre nom pour votre wiki.');
                    }
                }

                // creation d'un user?
                if ($GLOBALS['wiki']->config['yeswiki-farm-create-user']) {
                    if ($GLOBALS['wiki']->LoadUser($valeurs_fiche[$tableau_template[1].'_wikiname'])) {
                        die('L\'utilisateur '.$valeurs_fiche[$tableau_template[1].'_wikiname']
                            .' existe déjà, veuillez trouver un autre nom pour votre wiki.');
                    }
                    $GLOBALS['wiki']->Query(
                        "insert into ".$GLOBALS['wiki']->config["table_prefix"]."users set ".
                        "signuptime = now(), ".
                        "name = '".mysqli_real_escape_string($GLOBALS['wiki']->dblink, $valeurs_fiche[$tableau_template[1].'_wikiname'])."', ".
                        "email = '".mysqli_real_escape_string($GLOBALS['wiki']->dblink, $valeurs_fiche[$tableau_template[1].'_email'])."', ".
                        "password = md5('".mysqli_real_escape_string($GLOBALS['wiki']->dblink, $valeurs_fiche[$tableau_template[1].'_password'])."')"
                    );
                }

                $url = $GLOBALS['wiki']->config['yeswiki-farm-root-url'].$valeurs_fiche[$tableau_template[1]];
                $srcfolder = getcwd().DIRECTORY_SEPARATOR;
                $destfolder = getcwd().DIRECTORY_SEPARATOR.$GLOBALS['wiki']->config['yeswiki-farm-root-folder']
                            .DIRECTORY_SEPARATOR.$valeurs_fiche[$tableau_template[1]].DIRECTORY_SEPARATOR;

                // test l'existence du dossier choisi
                if (is_dir($destfolder)) {
                    die('L\'adresse '.$url.' est déja utilisée, veuillez en prendre une autre.');
                } else {
                    // on copie les fichier du wiki si l'on a accès en écriture
                    if (is_writable($GLOBALS['wiki']->config['yeswiki-farm-root-folder'])) {
                        // le repertoire racine et les fichiers de la racine
                        mkdir($destfolder);
                        copyRecursive($srcfolder.'index.php', $destfolder.'index.php');
                        copyRecursive($srcfolder.'interwiki.conf', $destfolder.'interwiki.conf');
                        copyRecursive($srcfolder.'robots.txt', $destfolder.'robots.txt');
                        copyRecursive($srcfolder.'tools.php', $destfolder.'tools.php');
                        copyRecursive($srcfolder.'wakka.basic.css', $destfolder.'wakka.basic.css');
                        copyRecursive($srcfolder.'wakka.css', $destfolder.'wakka.css');
                        copyRecursive($srcfolder.'wakka.php', $destfolder.'wakka.php');

                        // les dossiers de base des yeswiki
                        copyRecursive($srcfolder.'actions', $destfolder.'actions');
                        mkdir($destfolder.'cache');
                        mkdir($destfolder.'files');
                        mkdir($destfolder.'custom');
                        mkdir($destfolder.'templates');
                        copyRecursive($srcfolder.'formatters', $destfolder.'formatters');
                        copyRecursive($srcfolder.'handlers', $destfolder.'handlers');
                        copyRecursive($srcfolder.'includes', $destfolder.'includes');
                        copyRecursive($srcfolder.'lang', $destfolder.'lang');
                        copyRecursive($srcfolder.'setup', $destfolder.'setup');
                        copyRecursive($srcfolder.'vendor', $destfolder.'vendor');

                        // themes
                        mkdir($destfolder.'themes');

                        // templates
                        copyRecursive(
                            $srcfolder.'themes'.DIRECTORY_SEPARATOR.'tools',
                            $destfolder.'themes'.DIRECTORY_SEPARATOR.'tools'
                        );
                        // themes specifiques
                        foreach ($GLOBALS['wiki']->config['yeswiki-farm-extra-themes'] as $themes) {
                            copyRecursive(
                                $srcfolder.'themes'.DIRECTORY_SEPARATOR.$themes,
                                $destfolder.'themes'.DIRECTORY_SEPARATOR.$themes
                            );
                        }

                        // extensions
                        mkdir($destfolder.'tools');

                        // extensions de base
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'aceditor',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'aceditor'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'attach',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'attach'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'contact',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'contact'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'security',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'security'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'lang',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'lang'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'login',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'login'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'progressBar',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'progressBar'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'tableau',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'tableau'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'toc',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'toc'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'autoupdate',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'autoupdate'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'rss',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'rss'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'tags',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'tags'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'toolsmng',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'toolsmng'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'bazar',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'bazar'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'libs',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'libs'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'prepend.php',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'prepend.php'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'syndication',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'syndication'
                        );
                        copyRecursive(
                            $srcfolder.'tools'.DIRECTORY_SEPARATOR.'templates',
                            $destfolder.'tools'.DIRECTORY_SEPARATOR.'templates'
                        );

                        // extensions supplémentaires
                        foreach ($GLOBALS['wiki']->config['yeswiki-farm-extra-tools'] as $tools) {
                            copyRecursive(
                                $srcfolder.'tools'.DIRECTORY_SEPARATOR.$tools,
                                $destfolder.'tools'.DIRECTORY_SEPARATOR.$tools
                            );
                        }

                        // droits d'accès par aux pages
                        $rights = $GLOBALS['wiki']->config['yeswiki-farm-acls'][$valeurs_fiche['yeswiki-farm-acls']];
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
                        $theme = $GLOBALS['wiki']->config['yeswiki-farm-themes'][$_POST['yeswiki-farm-theme']];
                        $GLOBALS['wiki']->config['yeswiki-farm-fav-theme'] = $theme['theme'];
                        $GLOBALS['wiki']->config['yeswiki-farm-fav-style'] = $theme['style'];
                        $GLOBALS['wiki']->config['yeswiki-farm-fav-squelette'] = $theme['squelette'];
                        $GLOBALS['wiki']->config['yeswiki-farm-bg-img'] = isset($theme['bg-img']) ? $theme['bg-img'] : '';

                        // generation du prefixe
                        $prefix = $GLOBALS['wiki']->config['yeswiki-farm-prefix'].str_replace('-', '_', $valeurs_fiche[$tableau_template[1]]);

                        // ecriture du fichier de configuration
                        $config = array (
                              'wakka_version' => $GLOBALS['wiki']->config['wakka_version'],
                              'wikini_version' => $GLOBALS['wiki']->config['wikini_version'],
                              'yeswiki_version' => $GLOBALS['wiki']->config['yeswiki_version'],
                              'yeswiki_release' => $GLOBALS['wiki']->config['yeswiki_release'],
                              'debug' => $GLOBALS['wiki']->config['debug'],
                              'mysql_host' => $GLOBALS['wiki']->config['mysql_host'],
                              'mysql_database' => $GLOBALS['wiki']->config['mysql_database'],
                              'mysql_user' => $GLOBALS['wiki']->config['mysql_user'],
                              'mysql_password' => $GLOBALS['wiki']->config['mysql_password'],
                              'table_prefix' => $prefix.'__',
                              'root_page' => $GLOBALS['wiki']->config['yeswiki-farm-homepage'],
                              'wakka_name' => addslashes($valeurs_fiche['bf_titre']),
                              'base_url' => $GLOBALS['wiki']->config['yeswiki-farm-root-url']
                                            .$valeurs_fiche[$tableau_template[1]].'/?',
                              'rewrite_mode' => $GLOBALS['wiki']->config['rewrite_mode'],
                              'meta_keywords' => $GLOBALS['wiki']->config['meta_keywords'],
                              'meta_description' => $GLOBALS['wiki']->config['meta_description'],
                              'action_path' => 'actions',
                              'handler_path' => 'handlers',
                              'header_action' => 'header',
                              'footer_action' => 'footer',
                              'navigation_links' => $GLOBALS['wiki']->config['navigation_links'],
                              'referrers_purge_time' => $GLOBALS['wiki']->config['referrers_purge_time'],
                              'pages_purge_time' => $GLOBALS['wiki']->config['pages_purge_time'],
                              'default_write_acl' => $rights["write"],
                              'default_read_acl' => $rights["read"],
                              'default_comment_acl' => $rights["comments"],
                              'preview_before_save' => $GLOBALS['wiki']->config['preview_before_save'],
                              'allow_raw_html' => $GLOBALS['wiki']->config['allow_raw_html'],
                              'default_language' => $GLOBALS['wiki']->config['default_language'],
                              'favorite_theme' => $GLOBALS['wiki']->config['yeswiki-farm-fav-theme'],
                              'favorite_style' => $GLOBALS['wiki']->config['yeswiki-farm-fav-style'],
                              'favorite_squelette' => $GLOBALS['wiki']->config['yeswiki-farm-fav-squelette'],
                              'favorite_background_image' => $GLOBALS['wiki']->config['yeswiki-farm-bg-img'],
                              'source_url' =>  $GLOBALS['wiki']->href('', $valeurs_fiche['id_fiche']),
                              'db_charset' =>  'utf8mb4',
                        );
                        if (isset($GLOBALS['wiki']->config['yeswiki-farm-extra-config'])
                            and is_array($GLOBALS['wiki']->config['yeswiki-farm-extra-config'])
                        ) {
                            $config = array_merge($config, $GLOBALS['wiki']->config['yeswiki-farm-extra-config']);
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

                        if ($sql = file_get_contents('tools/ferme/sql/create-tables.sql')) {
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
                            $sql_report = '<strong>Rapport create-tables.sql</strong><br/>';
                            $queries = [];
                            preg_match_all('/^.*?;$(?:\r\n|\n)/sm', $sql, $queries);
                            foreach ($queries[0] as $index => $query){
                                mysqli_query($link, $query) or die('Erreur à l\'insertion n°' . ($index + 1) . ' : ' . mysqli_error($link));
                                $sql_report .= 'Insertion n°' . ($index + 1) . ' : ' . mysqli_affected_rows($link) . ' ligne(s) affectée(s)<br/>';
                            }
                            $sql_report .= '<br/>';
                        } else {
                            die('Lecture du fichier sql "tools/ferme/sql/create-tables.sql" impossible');
                        }
                        // insertion des donnees du modele dans la base de donnees
                        $sqlfile = $GLOBALS['wiki']->config['yeswiki-farm-sql'][$_POST['yeswiki-farm-sql']]['file'];
                        if ($sql = file_get_contents('tools/ferme/sql/'.$sqlfile)) {
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
                            foreach ($queries[0] as $index => $query){
                                mysqli_query($link, $query) or die('Erreur à l\'insertion n°' . ($index + 1) . ' : ' . mysqli_error($link));
                                $sql_report .= 'Insertion n°' . ($index + 1) . ' : ' . mysqli_affected_rows($link) . ' ligne(s) affectée(s)<br/>';
                            }

                            // TODO find a way to display this message longer
                            $GLOBALS["wiki"]->SetMessage($sql_report);
                        } else {
                            die('Lecture du fichier sql "tools/ferme/sql/'.$sqlfile.'" impossible');
                        }

                        if (!empty($valeurs_fiche["access-username"])) {
                            $GLOBALS["wiki"]->Query("INSERT INTO `".$prefix."__users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES ('".$valeurs_fiche["access-username"]."', md5('".$valeurs_fiche["access-password"]."'), '".$valeurs_fiche[$tableau_template[1].'_email']."', '', '20', '50', 1, now(), 2);");
                        }

                        if (!empty($valeurs_fiche["yeswiki-farm-options"])) {
                            $taboptions = explode(',', $valeurs_fiche["yeswiki-farm-options"]);
                            foreach ($taboptions as $option) {
                                $GLOBALS["wiki"]->Query('UPDATE `'.$prefix.'__pages` SET body=CONCAT(body, "'.utf8_decode($GLOBALS['wiki']->config['yeswiki-farm-options'][$option]['content']).'") WHERE tag="'.$GLOBALS['wiki']->config['yeswiki-farm-options'][$option]['page'].'" AND latest="Y";');
                            }
                        }
                    } else {
                        die('Le dossier '.$GLOBALS['wiki']->config['yeswiki-farm-root-folder']
                            .' n\'est pas accessible en écriture');
                    }
                }
            }

            // creation d'un groupe et ajout des membres
            if (is_array($GLOBALS['wiki']->config['yeswiki-farm-group'])) {
                // generation du prefixe
                $tripletable = $GLOBALS['wiki']->config['yeswiki-farm-prefix'].str_replace('-', '_', $valeurs_fiche[$tableau_template[1]]).'__triples';

                // on efface les anciennes valeurs du groupe
                $remsql = 'DELETE FROM `'.$tripletable
                  .'` WHERE `resource`="ThisWikiGroup:'.$GLOBALS['wiki']->config['yeswiki-farm-group']['groupname']
                       .'" and `property`="http://www.wikini.net/_vocabulary/acls";';
                $GLOBALS['wiki']->Query($remsql);

                // on ajoute les nouvelles valeurs du groupe
                $users = $valeurs_fiche[$GLOBALS['wiki']->config['yeswiki-farm-group']['group_members_field']];
                $addsql = 'INSERT INTO `'.$tripletable.'` (`resource`, `property`, `value`)'
                  .' VALUES (\'ThisWikiGroup:'.$GLOBALS['wiki']->config['yeswiki-farm-group']['groupname'].'\','
                  .' \'http://www.wikini.net/_vocabulary/acls\', \''.implode("\n", explode(',', $users)).'\');';
                $GLOBALS['wiki']->Query($addsql);
            }

            return array(
                $tableau_template[1] => $valeurs_fiche[$tableau_template[1]]
            );
        } else {
            die('La valeur '.$valeurs_fiche[$tableau_template[1]]
                .' n\'est pas valide, il faut des caractères alphanumérique et des tirets (- _) uniquement.');
        }
    } elseif ($mode == 'html') {
        $html = '';
        if (isset($valeurs_fiche[$tableau_template[1]]) && $valeurs_fiche[$tableau_template[1]] != '') {
            $url = $GLOBALS['wiki']->config['yeswiki-farm-root-url'].$valeurs_fiche[$tableau_template[1]];
            $html .= '<div class="BAZ_rubrique" data-id="' . $tableau_template[1] . '">' . "\n"
                .'<span class="BAZ_label">Accèder à l\'espace projet :</span>' . "\n"
                .'<span class="BAZ_texte"><a class="btn btn-primary" href="'.$url.'" target="_blank">'."\n".$url."\n"
                    .'</a></span>' . "\n"
                .'</div> <!-- /.BAZ_rubrique -->' . "\n";
        }

        return $html;
    }
}
