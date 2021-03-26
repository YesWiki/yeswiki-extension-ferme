<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

class FarmService
{
    protected $wiki;
    protected $sourceWikiVersion = '';

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
        $this->sourceWikiVersion = $this->wiki->config['yeswiki_release'];
        $this->initFarmConfig();
    }

    /** initFarmConfig() - test le fichier de configuration et ajoute des valuers par defaut, si besoin
     *
     * @return   void
     */
    public function initFarmConfig()
    {
        // test de l'existence des variables de configuration de la ferme, mise en place de valeurs par défaut sinon
        if (!isset($this->wiki->config['yeswiki-farm-root-url'])) {
            $this->wiki->config['yeswiki-farm-root-url'] = str_replace(
                array('wakka.php?wiki=', '?'),
                '',
                $this->wiki->config['base_url']
            );
            $this->wiki->config['yeswiki-farm-root-folder'] = '.';
        } elseif (!isset($this->wiki->config['yeswiki-farm-root-folder'])) {
            exit('<div class="alert alert-danger">Il faut indiquer le chemin relatif des wikis'
            .' avec la valeur "yeswiki-farm-root-folder" dans le fichier de configuration.</div>');
        }
        // themes supplémentaires
        if (!isset($this->wiki->config['yeswiki-farm-extra-themes'])
        || !is_array($this->wiki->config['yeswiki-farm-extra-themes'])) {
            $this->wiki->config['yeswiki-farm-extra-themes'] = array();
        }

        // extensions supplémentaires
        if (!isset($this->wiki->config['yeswiki-farm-extra-tools'])
        || !is_array($this->wiki->config['yeswiki-farm-extra-tools'])) {
            $this->wiki->config['yeswiki-farm-extra-tools'] = array();
        }

        // theme par defaut
        if (!isset($this->wiki->config['yeswiki-farm-themes'])
        or !is_array($this->wiki->config['yeswiki-farm-themes'])) {
            $this->wiki->config['yeswiki-farm-themes'][0]['label'] = 'Margot (theme de base)';
            $this->wiki->config['yeswiki-farm-themes'][0]['screenshot'] = 'margot.jpg';
            $this->wiki->config['yeswiki-farm-themes'][0]['theme'] = 'margot';
            $this->wiki->config['yeswiki-farm-themes'][0]['squelette'] = '1col.tpl.html';
            $this->wiki->config['yeswiki-farm-themes'][0]['style'] = 'margot.css';
        } else {
            // verifier l'existence des themes
            foreach ($this->wiki->config['yeswiki-farm-themes'] as $key => $theme) {
                if (!isset($theme['label']) or empty($theme['label'])) {
                    exit('<div class="alert alert-danger">Au moins un label pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
                }
                if (!isset($theme['screenshot']) or empty($theme['screenshot'])) {
                    exit('<div class="alert alert-danger">Au moins un screenshot pour les themes de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
                } elseif (!is_file('tools/ferme/screenshots/'.$theme['screenshot'])) {
                    $theme['screenshot'] = false;
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

        if (!isset($this->wiki->config['yeswiki-farm-bg-img'])) {
            $this->wiki->config['yeswiki-farm-bg-img'] = '';
        }

        // acls
        if (!isset($this->wiki->config['yeswiki-farm-acls'])
        or !is_array($this->wiki->config['yeswiki-farm-acls'])) {
            $this->wiki->config['yeswiki-farm-acls'][0]['label'] = 'Wiki ouvert';
            $this->wiki->config['yeswiki-farm-acls'][0]['read'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['write'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['comments'] = '+';
        } else {
            // verifier l'existence des acls
            foreach ($this->wiki->config['yeswiki-farm-acls'] as $key => $acls) {
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
        if (!isset($this->wiki->config['yeswiki-farm-sql'])
        or !is_array($this->wiki->config['yeswiki-farm-sql'])) {
            $this->wiki->config['yeswiki-farm-sql'][0]['label'] = 'Configuration de base';
            $this->wiki->config['yeswiki-farm-sql'][0]['file'] = 'default-content.sql';
        } else {
            // verifier l'existence des parametres des fichiers sql
            foreach ($this->wiki->config['yeswiki-farm-sql'] as $key => $sql) {
                if (!isset($sql['label']) or empty($sql['label'])) {
                    exit('<div class="alert alert-danger">Au moins un label pour les configurations sql de la ferme n\'a'
                    .' pas été bien renseigné.</div>');
                }
                if (!isset($sql['file']) or empty($sql['file'])) {
                    exit('<div class="alert alert-danger">Au moins un fichier sql de configuration n\'a'
                    .' pas été bien renseigné.</div>');
                } elseif (!is_file('custom/wiki-models/'.$sql['file']) && !is_file('setup/sql/'.$sql['file'])) {
                    exit('<div class="alert alert-danger">Le fichier sql "custom/wiki-models/'.$sql['file']
                    .'" n\'a pas été trouvé.</div>');
                }
            }
        }

        // création d'un utilisateur dans le wiki initial (sert pour des cas spécifiques avec une bd centralisée)
        if (!isset($this->wiki->config['yeswiki-farm-create-user'])) {
            $this->wiki->config['yeswiki-farm-create-user'] = false;
        }

        // Utilisateur WikiAdmin par défaut (laisser vide pour demander à la création du wiki)
        if (!isset($this->wiki->config['yeswiki-farm-default-WikiAdmin'])) {
            $this->wiki->config['yeswiki-farm-default-WikiAdmin'] = 'WikiAdmin';
        }

        // Mot de passe WikiAdmin par défaut (laisser vide pour demander à la création du wiki)
        if (!isset($this->wiki->config['yeswiki-farm-password-WikiAdmin'])) {
            $this->wiki->config['yeswiki-farm-password-WikiAdmin'] = '';
        }

        // Email par défaut (laisser vide pour demander à la création du wiki)
        if (!isset($this->wiki->config['yeswiki-farm-email-WikiAdmin'])) {
            $this->wiki->config['yeswiki-farm-email-WikiAdmin'] = '';
        }

        // page d'accueil des wikis de la ferme
        if (!isset($this->wiki->config['yeswiki-farm-homepage'])) {
            $this->wiki->config['yeswiki-farm-homepage'] = $this->wiki->config['root_page'];
        }

        // prefixe par default
        if (!isset($this->wiki->config['yeswiki-farm-prefix'])) {
            $this->wiki->config['yeswiki-farm-prefix'] = 'yeswiki_';
        }

        // admin de la ferme
        if (!isset($this->wiki->config['yeswiki-farm-admin-name'])) {
            $this->wiki->config['yeswiki-farm-admin-name'] = '';
        }
        if (!isset($this->wiki->config['yeswiki-farm-admin-pass'])) {
            $this->wiki->config['yeswiki-farm-admin-pass'] = '';
        }
    }

    public function getWikiConfig($wiki) {
        $wakkaConfig = [];
        if ($this->wiki->config['yeswiki-farm-root-folder'] == '.') {
            $path = getcwd().DIRECTORY_SEPARATOR.$wiki.'/wakka.config.php';
        } else {
            $path = getcwd().DIRECTORY_SEPARATOR
                .$this->wiki->config['yeswiki-farm-root-folder'].DIRECTORY_SEPARATOR
                .$wiki.'/wakka.config.php';
        }
        if (file_exists($path)) {
            include_once realpath($path);
        }
        return $wakkaConfig;
    }

    public function hasFarmAdmin($wiki)
    {
        return ;
    }

    public function addFarmAdmin($wiki)
    {
        $wikiConf = $this->getWikiConfig($wiki);
        if (!empty($this->wiki->config['yeswiki-farm-admin-name']) && !empty($this->wiki->config['yeswiki-farm-admin-pass'])) {
            if (!empty($wikiConf['table_prefix'])) {
                $sql = 'SELECT value FROM `'.$wikiConf['table_prefix'].'triples` WHERE resource = "ThisWikiGroup:admins";';
                $list = $this->wiki->LoadSingle($sql);
                $list = explode("\n", $list['value']);
                if (!in_array($this->wiki->config['yeswiki-farm-admin-name'], $list)) {
                    $list[] = $this->wiki->config['yeswiki-farm-admin-name'];
                }
                $list = array_map('trim', $list);
                $list = implode("\n", $list);
                $sql = 'UPDATE `'.$wikiConf['table_prefix'].'triples` SET value="'.addslashes($list).'" WHERE resource = "ThisWikiGroup:admins";';
                $this->wiki->Query($sql);

                $sql = 'INSERT INTO `'.$wikiConf['table_prefix'].'users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES (\''.$this->wiki->config['yeswiki-farm-admin-name'].'\', MD5(\''.$this->wiki->config['yeswiki-farm-admin-pass'].'\'), \'\', \'\', \'20\', \'50\', \'Y\', NOW(), \'N\')';
                $this->wiki->Query($sql);
                return [
                    'success' => [_t('Super user added for the wiki').' :'. $wiki . '.']
                ];
            } else {
                return [
                    'errors' => [_t('No table prefix found for the wiki').' :'. $wiki . '.']
                ];
            }
        } else {
            return [
                'errors' => [_t('No yeswiki-farm-admin-name or yeswiki-farm-admin-pass in config.')]
            ];
        }
    }

    public function removeFarmAdmin($wiki)
    {
        $wikiConf = $this->getWikiConfig($wiki);
        if (!empty($wikiConf['table_prefix'])) {
            $sql = 'SELECT value FROM `'.$wikiConf['table_prefix'].'triples` WHERE resource = "ThisWikiGroup:admins";';
            $list = $this->wiki->LoadSingle($sql);
            $list = explode("\n", $list['value']);
            if (in_array($this->wiki->config['yeswiki-farm-admin-name'], $list)) {
                $list = array_diff($list, array($this->wiki->config['yeswiki-farm-admin-name']));
            }
            $list = array_map('trim', $list);
            $list = implode("\n", $list);
            $sql = 'UPDATE `'.$wikiConf['table_prefix'].'triples` SET value="'.addslashes($list).'" WHERE resource = "ThisWikiGroup:admins";';
            $this->wiki->Query($sql);

            $sql = 'DELETE FROM '.$wikiConf['table_prefix'].'users WHERE name="'.$this->wiki->config['yeswiki-farm-admin-name'].'";';
            $this->wiki->Query($sql);
        }
    }

    public function getWikiList()
    {
        return ['titi', 'tata', 'toto'];
    }

    /**
     * recursive remove file or folder
     *
     * @param string $src path
     * @return void
     */
    public function rrmdir($src)
    {
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
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

    /**
     * recursive copy file or filder
     *
     * @param string $path : source path
     * @param string $dest : destination path
     * @return void
     */
    public function copyRecursive($path, $dest)
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
}
