<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Wiki;

class FarmService
{
    protected $wiki;
    protected $sourceWikiVersion = '';
    protected $params;

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
        $this->params = $this->wiki->services->get(ParameterBagInterface::class);
        $this->sourceWikiVersion = $this->params->get('yeswiki_release');
        $this->initFarmConfig();
    }

    /**
     * Tests the configuration file and adds default values if needed.
     *
     * @throws \RuntimeException if required configuration is missing or invalid
     */
    public function initFarmConfig()
    {
        if (!isset($this->wiki->config['yeswiki-farm-root-url'])) {
            $this->wiki->config['yeswiki-farm-root-url'] = str_replace(
                ['wakka.php?wiki=', '?'],
                '',
                $this->wiki->config['base_url']
            );
            $this->wiki->config['yeswiki-farm-root-folder'] = '.';
        } elseif (!isset($this->wiki->config['yeswiki-farm-root-folder'])) {
            throw new \RuntimeException('Il faut indiquer le chemin relatif des wikis avec la valeur "yeswiki-farm-root-folder" dans le fichier de configuration.');
        }

        // themes supplémentaires
        if (
            !isset($this->wiki->config['yeswiki-farm-extra-themes'])
            || !is_array($this->wiki->config['yeswiki-farm-extra-themes'])
        ) {
            $this->wiki->config['yeswiki-farm-extra-themes'] = [];
        }

        // extensions supplémentaires
        if (
            !isset($this->wiki->config['yeswiki-farm-extra-tools'])
            || !is_array($this->wiki->config['yeswiki-farm-extra-tools'])
        ) {
            $this->wiki->config['yeswiki-farm-extra-tools'] = [];
        }

        // theme par defaut
        if (
            !isset($this->wiki->config['yeswiki-farm-themes'])
            or !is_array($this->wiki->config['yeswiki-farm-themes'])
        ) {
            $this->wiki->config['yeswiki-farm-themes'][0]['label'] = 'Margot (theme de base)';
            $this->wiki->config['yeswiki-farm-themes'][0]['screenshot'] = 'margot.jpg';
            $this->wiki->config['yeswiki-farm-themes'][0]['theme'] = THEME_PAR_DEFAUT;
            $this->wiki->config['yeswiki-farm-themes'][0]['squelette'] = SQUELETTE_PAR_DEFAUT;
            $this->wiki->config['yeswiki-farm-themes'][0]['style'] = CSS_PAR_DEFAUT;
        } else {
            foreach ($this->wiki->config['yeswiki-farm-themes'] as $key => $theme) {
                if (!isset($theme['label']) or empty($theme['label'])) {
                    throw new \RuntimeException('Au moins un label pour les themes de la ferme n\'a pas été bien renseigné.');
                }
                if (!isset($theme['screenshot']) or empty($theme['screenshot'])) {
                    throw new \RuntimeException('Au moins un screenshot pour les themes de la ferme n\'a pas été bien renseigné.');
                } elseif (!is_file('tools/ferme/screenshots/' . $theme['screenshot'])) {
                    $theme['screenshot'] = false;
                }
                if (!isset($theme['theme']) or empty($theme['theme'])) {
                    throw new \RuntimeException('Au moins un theme pour les themes de la ferme n\'a pas été bien renseigné.');
                } elseif (!is_dir('themes/' . $theme['theme']) and ($theme['theme'] == 'yeswiki' and !is_dir('tools/templates/themes/' . $theme['theme']))) {
                    throw new \RuntimeException('Le dossier "themes/' . $theme['theme'] . '" n\'a pas été trouvé.');
                }
                if (!isset($theme['squelette']) or empty($theme['squelette'])) {
                    throw new \RuntimeException('Au moins un squelette pour les themes de la ferme n\'a pas été bien renseigné.');
                } elseif (!is_file('themes/' . $theme['theme'] . '/squelettes/' . $theme['squelette']) and ($theme['theme'] == 'yeswiki' and !is_file('tools/templates/themes/' . $theme['theme'] . '/squelettes/' . $theme['squelette']))) {
                    throw new \RuntimeException('Le squelette "themes/' . $theme['theme'] . '/squelettes/' . $theme['squelette'] . '" n\'a pas été trouvé.');
                }
                if (!isset($theme['style']) or empty($theme['style'])) {
                    throw new \RuntimeException('Au moins un style css pour les themes de la ferme n\'a pas été bien renseigné.');
                } elseif (!is_file('themes/' . $theme['theme'] . '/styles/' . $theme['style']) and ($theme['theme'] == 'yeswiki' and !is_file('tools/templates/themes/' . $theme['theme'] . '/styles/' . $theme['style']))) {
                    throw new \RuntimeException('Le style css "themes/' . $theme['theme'] . '/styles/' . $theme['style'] . '" n\'a pas été trouvé.');
                }
            }
        }

        if (is_null($this->wiki->config['yeswiki_symlinked_files'])) {
            $this->wiki->config['yeswiki_symlinked_files'] = [];
        }

        if (!isset($this->wiki->config['yeswiki-farm-bg-img'])) {
            $this->wiki->config['yeswiki-farm-bg-img'] = '';
        }

        // acls
        if (
            !isset($this->wiki->config['yeswiki-farm-acls'])
            or !is_array($this->wiki->config['yeswiki-farm-acls'])
        ) {
            $this->wiki->config['yeswiki-farm-acls'][0]['label'] = 'Wiki ouvert';
            $this->wiki->config['yeswiki-farm-acls'][0]['read'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['write'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['comments'] = 'comments-closed';
        } else {
            foreach ($this->wiki->config['yeswiki-farm-acls'] as $key => $acls) {
                if (!isset($acls['label']) or empty($acls['label'])) {
                    throw new \RuntimeException('Au moins un label pour les acls de la ferme n\'a pas été bien renseigné.');
                }
                if (!isset($acls['read']) or empty($acls['read'])) {
                    throw new \RuntimeException('Au moins un droit en lecture (read) n\'a pas été bien renseigné.');
                }
                if (!isset($acls['write']) or empty($acls['write'])) {
                    throw new \RuntimeException('Au moins un droit en écriture (write) n\'a pas été bien renseigné.');
                }
                if (!isset($acls['comments']) or empty($acls['comments'])) {
                    throw new \RuntimeException('Au moins un droit des commentaires (comments) n\'a pas été bien renseigné.');
                }
            }
        }

        // sql d'installation par défaut
        if (
            !isset($this->wiki->config['yeswiki-farm-models'])
            or !is_array($this->wiki->config['yeswiki-farm-models'])
        ) {
            $this->wiki->config['yeswiki-farm-models'][] = 'default-content';
        } else {
            foreach ($this->wiki->config['yeswiki-farm-models'] as $key => $folder) {
                if ($folder != 'default-content') {
                    if (!is_dir('custom/wiki-models/' . $folder)) {
                        unset($this->wiki->config['yeswiki-farm-models'][$key]);
                        trigger_error('le dossier "custom/wiki-models/' . $folder . '" ne semble pas exister.');
                    } elseif (!is_file('custom/wiki-models/' . $folder . '/default-content.sql')) {
                        unset($this->wiki->config['yeswiki-farm-models'][$key]);
                        trigger_error('Le fichier sql "custom/wiki-models/' . $folder . '/default-content.sql" n\'a pas été trouvé.');
                    }
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
            $this->wiki->config['yeswiki-farm-email-WikiAdmin'] = 'bf_mail';
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

    public function getWikiConfig($wiki)
    {
        $wakkaConfig = [];
        if ($this->wiki->config['yeswiki-farm-root-folder'] == '.') {
            $path = getcwd() . DIRECTORY_SEPARATOR . $wiki . '/wakka.config.php';
        } else {
            $path = getcwd() . DIRECTORY_SEPARATOR
                . $this->wiki->config['yeswiki-farm-root-folder'] . DIRECTORY_SEPARATOR
                . $wiki . '/wakka.config.php';
        }
        if (file_exists($path)) {
            include_once realpath($path);
        }

        return $wakkaConfig;
    }

    public function addFarmAdmin($wiki)
    {
        $wikiConf = $this->getWikiConfig($wiki);
        if (!empty($this->wiki->config['yeswiki-farm-admin-name']) && !empty($this->wiki->config['yeswiki-farm-admin-pass'])) {
            if (!empty($wikiConf['table_prefix'])) {
                // change database
                $sql = 'USE ' . $wikiConf['mysql_database'] . ';';
                $this->wiki->query($sql);

                $sql = 'SELECT value FROM `' . $wikiConf['table_prefix'] . 'triples` WHERE resource = "ThisWikiGroup:admins";';
                $list = $this->wiki->LoadSingle($sql);
                $list = explode("\n", $list['value']);
                if (!in_array($this->wiki->config['yeswiki-farm-admin-name'], $list)) {
                    $list[] = $this->wiki->config['yeswiki-farm-admin-name'];
                }
                $list = array_map('trim', $list);
                $list = implode("\n", $list);
                $sql = 'UPDATE `' . $wikiConf['table_prefix'] . 'triples` SET value="' . addslashes($list) . '" WHERE resource = "ThisWikiGroup:admins";';
                $this->wiki->Query($sql);

                // Only insert if user doesn't already exist in target wiki
                $existing = $this->wiki->LoadSingle(
                    'SELECT name FROM `' . $wikiConf['table_prefix'] . 'users` WHERE name="' . addslashes($this->wiki->config['yeswiki-farm-admin-name']) . '"'
                );
                if (empty($existing)) {
                    $sql = 'INSERT INTO `' . $wikiConf['table_prefix'] . 'users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES (\'' . $this->wiki->config['yeswiki-farm-admin-name'] . '\', MD5(\'' . $this->wiki->config['yeswiki-farm-admin-pass'] . '\'), \'\', \'\', \'20\', \'50\', \'Y\', NOW(), \'N\')';
                    $this->wiki->Query($sql);
                }

                // back to main database
                $sql = 'USE ' . $this->wiki->config['mysql_database'] . ';';
                $this->wiki->query($sql);

                return [
                    'success' => [_t('Super user added for the wiki') . ' :' . $wiki . '.'],
                ];
            } else {
                return [
                    'errors' => [_t('No table prefix found for the wiki') . ' :' . $wiki . '.'],
                ];
            }
        } else {
            return [
                'errors' => [_t('No yeswiki-farm-admin-name or yeswiki-farm-admin-pass in config.')],
            ];
        }
    }

    public function removeFarmAdmin($wiki)
    {
        $wikiConf = $this->getWikiConfig($wiki);
        if (!empty($wikiConf['table_prefix'])) {
            // change database
            $sql = 'USE ' . $wikiConf['mysql_database'] . ';';
            $this->wiki->query($sql);

            $sql = 'SELECT value FROM `' . $wikiConf['table_prefix'] . 'triples` WHERE resource = "ThisWikiGroup:admins";';
            $list = $this->wiki->LoadSingle($sql);
            $list = explode("\n", $list['value']);
            if (in_array($this->wiki->config['yeswiki-farm-admin-name'], $list)) {
                $list = array_diff($list, [$this->wiki->config['yeswiki-farm-admin-name']]);
            }
            $list = array_map('trim', $list);
            $list = implode("\n", $list);
            $sql = 'UPDATE `' . $wikiConf['table_prefix'] . 'triples` SET value="' . addslashes($list) . '" WHERE resource = "ThisWikiGroup:admins";';
            $this->wiki->Query($sql);

            $sql = 'DELETE FROM ' . $wikiConf['table_prefix'] . 'users WHERE name="' . addslashes($this->wiki->config['yeswiki-farm-admin-name']) . '";';
            $this->wiki->Query($sql);

            // back to main database
            $sql = 'USE ' . $this->wiki->config['mysql_database'] . ';';
            $this->wiki->query($sql);
        }
    }

    /**
     * Create a new wiki from a bazar entry.
     *
     * @param array  $entry     The bazar entry data
     * @param string $fieldName The field name for the wiki folder
     * @param string $theme     Index into yeswiki-farm-themes config array
     * @param string $model     Model name ('default-content' or a custom model folder)
     *
     * @throws \Exception on validation or I/O failure
     */
    public function createWikiFromEntry($entry, $fieldName, string $theme = '0', string $model = 'default-content')
    {
        if ($entry[$fieldName . '_wikiname'] == '{{folder}}') {
            $entry[$fieldName . '_wikiname'] = genere_nom_wiki($entry[$fieldName], 0);
            if ($this->wiki->LoadUser($entry[$fieldName . '_wikiname'])) {
                throw new \Exception('L\'utilisateur ' . $entry[$fieldName . '_wikiname'] . ' existe déjà, veuillez trouver un autre nom pour votre wiki.');
            }
        }

        // replace e_mail with the right email if referenced via other field like bf_mail
        $entry[$fieldName . '_email'] = (!empty($entry[$fieldName . '_email']) && !empty($entry[$entry[$fieldName . '_email']]))
            ? $entry[$entry[$fieldName . '_email']] : $entry[$fieldName . '_email'];

        // creation d'un user?
        if ($this->wiki->config['yeswiki-farm-create-user']) {
            if ($this->wiki->LoadUser($entry[$fieldName . '_wikiname'])) {
                throw new \Exception('L\'utilisateur ' . $entry[$fieldName . '_wikiname'] . ' existe déjà, veuillez trouver un autre nom pour votre utilisateur.');
            }
            $this->wiki->Query(
                'insert into ' . $this->wiki->config['table_prefix'] . 'users set ' .
                    'signuptime = now(), ' .
                    "name = '" . mysqli_real_escape_string($this->wiki->dblink, $entry[$fieldName . '_wikiname']) . "', " .
                    "email = '" . mysqli_real_escape_string($this->wiki->dblink, $entry[$fieldName . '_email']) . "', " .
                    "password = md5('" . mysqli_real_escape_string($this->wiki->dblink, $entry[$fieldName . '_password']) . "')"
            );
        }

        $url = $this->wiki->config['yeswiki-farm-root-url'] . $entry[$fieldName];
        $srcfolder = getcwd() . DIRECTORY_SEPARATOR;
        $destfolder = $this->getAbsolutePath(
            getcwd() . DIRECTORY_SEPARATOR
                . $this->wiki->config['yeswiki-farm-root-folder'] . DIRECTORY_SEPARATOR
                . $entry[$fieldName]
        );

        if (is_dir($destfolder)) {
            throw new \Exception('L\'adresse ' . $url . ' est déja utilisée, veuillez en prendre une autre.');
        }

        if (!is_writable($this->wiki->config['yeswiki-farm-root-folder'])) {
            throw new \Exception('Le dossier ' . $this->wiki->config['yeswiki-farm-root-folder'] . ' n\'est pas accessible en écriture');
        }

        $this->copyWikiFiles($srcfolder, $destfolder);

        // droits d'accès par aux pages
        $rights = $this->wiki->config['yeswiki-farm-acls'][$entry['yeswiki-farm-acls']];
        $username = !empty($entry['access-username']) ? $entry['access-username'] : $entry[$fieldName . '_wikiname'];
        foreach (['write', 'read', 'comments'] as $right) {
            if ($rights[$right] == '{{user}}') {
                $rights[$right] = $username;
            }
        }

        // theme choisi
        $themeConfig = $this->wiki->config['yeswiki-farm-themes'][$theme];
        $this->wiki->config['yeswiki-farm-fav-theme'] = $themeConfig['theme'];
        $this->wiki->config['yeswiki-farm-fav-style'] = $themeConfig['style'];
        $this->wiki->config['yeswiki-farm-fav-squelette'] = $themeConfig['squelette'];
        $this->wiki->config['yeswiki-farm-fav-preset'] = $themeConfig['preset'] ?? '';
        $this->wiki->config['yeswiki-farm-bg-img'] = $themeConfig['bg-img'] ?? '';

        // generation du prefixe
        $prefix = empty($entry['bf_prefixe']) ?
            $this->wiki->config['yeswiki-farm-prefix'] . str_replace('-', '_', $entry[$fieldName]) . '__' :
            $entry['bf_prefixe'];

        // ecriture du fichier de configuration
        $config = $this->buildWikiConfig($entry, $fieldName, $prefix, $rights);
        $this->writeWikiConfig($destfolder, $config);

        // creation des tables de la base de données
        $link = $this->createDbConnection();
        $replacements = [
            'prefix' => $prefix,
            'siteTitle' => $config['wakka_name'],
            'WikiName' => $entry[$fieldName . '_wikiname'],
            'password' => $entry[$fieldName . '_password'],
            'email' => $entry[$fieldName . '_email'],
            'rootPage' => $config['root_page'],
        ];

        $sqlReport = $this->createWikiDatabase($link, $prefix, $replacements, $model);

        if (!empty($_GET['debug']) || $this->wiki->config['debug'] == 'yes') {
            if (function_exists('flash')) {
                flash($sqlReport, 'success');
            } else {
                $this->wiki->SetMessage($sqlReport);
            }
        }

        if ($model !== 'default-content') {
            // copy model files
            $modelFiles = 'custom/wiki-models/' . $model . '/files';
            if (is_dir($modelFiles)) {
                $this->copyRecursive($modelFiles, $destfolder . 'files');
            }

            // copy model custom files
            $modelCustomFiles = 'custom/wiki-models/' . $model . '/custom';
            if (is_dir($modelCustomFiles)) {
                $this->copyRecursive($modelCustomFiles, $destfolder . 'custom');
            }
        }

        if (!empty($entry['access-username'])) {
            $this->wiki->Query("INSERT INTO `{$prefix}__users` " .
                '(`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) ' .
                "VALUES ('" . mysqli_real_escape_string($link, $entry['access-username']) . "', " .
                "md5('" . mysqli_real_escape_string($link, $entry['access-password']) . "'), " .
                "'" . $entry[$fieldName . '_email'] . "', '', '20', '50', 1, now(), 2);");
        }

        if (!empty($entry['yeswiki-farm-options'])) {
            $taboptions = explode(',', $entry['yeswiki-farm-options']);
            foreach ($taboptions as $option) {
                $this->wiki->Query('UPDATE `' . $prefix . '__pages` SET body=CONCAT(body, "' . $this->wiki->config['yeswiki-farm-options'][$option]['content'] . '") WHERE tag="' . $this->wiki->config['yeswiki-farm-options'][$option]['page'] . '" AND latest="Y";');
            }
        }

        $this->runMigrations($destfolder);

        // création d'un groupe et ajout des membres
        if (isset($this->wiki->config['yeswiki-farm-group']) && is_array($this->wiki->config['yeswiki-farm-group'])) {
            $tripletable = $this->wiki->config['yeswiki-farm-prefix'] . str_replace('-', '_', $entry[$fieldName]) . '__triples';

            $remsql = 'DELETE FROM `' . $tripletable
                . '` WHERE `resource`="ThisWikiGroup:' . $this->wiki->config['yeswiki-farm-group']['groupname']
                . '" and `property`="http://www.wikini.net/_vocabulary/acls";';
            $this->wiki->Query($remsql);

            $users = $entry[$this->wiki->config['yeswiki-farm-group']['group_members_field']];
            $addsql = 'INSERT INTO `' . $tripletable . '` (`resource`, `property`, `value`)'
                . ' VALUES (\'ThisWikiGroup:' . $this->wiki->config['yeswiki-farm-group']['groupname'] . '\','
                . ' \'http://www.wikini.net/_vocabulary/acls\', \'' . implode("\n", explode(',', $users)) . '\');';
            $this->wiki->Query($addsql);
        }
    }

    /**
     * Copy all YesWiki source files and symlinks into the new wiki destination folder.
     */
    private function copyWikiFiles(string $srcfolder, string $destfolder): void
    {
        mkdir($destfolder, 0755, true);
        foreach ($this->wiki->config['yeswiki_empty_folders'] as $folder) {
            if (!in_array($folder, $this->wiki->config['yeswiki_symlinked_files'])) {
                mkdir($destfolder . $folder, 0777, true);
            }
        }

        foreach ($this->wiki->config['yeswiki_files'] as $file) {
            if (!in_array($file, $this->wiki->config['yeswiki_symlinked_files'])) {
                $this->copyRecursive($srcfolder . $file, $destfolder . $file);
            }
        }

        foreach ($this->wiki->config['yeswiki_symlinked_files'] as $file) {
            symlink($srcfolder . $file, $destfolder . $file);
        }

        foreach ($this->wiki->config['yeswiki-farm-extra-themes'] as $themeDir) {
            $this->copyRecursive(
                $srcfolder . 'themes' . DIRECTORY_SEPARATOR . $themeDir,
                $destfolder . 'themes' . DIRECTORY_SEPARATOR . $themeDir
            );
        }

        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] as $toolDir) {
            $this->copyRecursive(
                $srcfolder . 'tools' . DIRECTORY_SEPARATOR . $toolDir,
                $destfolder . 'tools' . DIRECTORY_SEPARATOR . $toolDir
            );
        }
    }

    /**
     * Build the wakka.config.php array for a new wiki.
     */
    private function buildWikiConfig(array $entry, string $fieldName, string $prefix, array $rights): array
    {
        $config = [
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
            'wakka_name' => addslashes($entry['bf_titre']),
            'base_url' => $this->wiki->config['yeswiki-farm-root-url']
                . $entry[$fieldName] . '/?',
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
            'default_write_acl' => $rights['write'],
            'default_read_acl' => $rights['read'],
            'default_comment_acl' => $rights['comments'],
            'preview_before_save' => $this->wiki->config['preview_before_save'],
            'allow_raw_html' => $this->wiki->config['allow_raw_html'],
            'default_language' => $this->wiki->config['default_language'],
            'favorite_theme' => $this->wiki->config['yeswiki-farm-fav-theme'],
            'favorite_style' => $this->wiki->config['yeswiki-farm-fav-style'],
            'favorite_squelette' => $this->wiki->config['yeswiki-farm-fav-squelette'],
            'favorite_preset' => $this->wiki->config['yeswiki-farm-fav-preset'],
            'favorite_background_image' => $this->wiki->config['yeswiki-farm-bg-img'],
            'source_url' => $this->wiki->href('', $entry['id_fiche'] ?? genere_nom_wiki($entry['bf_titre'])),
            'db_charset' => 'utf8mb4',
        ];

        if (
            isset($this->wiki->config['yeswiki-farm-extra-config'])
            and is_array($this->wiki->config['yeswiki-farm-extra-config'])
        ) {
            $config = array_merge($config, $this->wiki->config['yeswiki-farm-extra-config']);
        }

        if (isset($entry['bf_description'])) {
            $config['meta_description'] = addslashes(
                substr(
                    str_replace('<br>', ' ', strip_tags($entry['bf_description'], '<br>')),
                    0,
                    150
                )
            );
        }

        return $config;
    }

    /**
     * Write the wakka.config.php file for a new wiki.
     *
     * @throws \Exception if the file cannot be written
     */
    private function writeWikiConfig(string $destfolder, array $config): void
    {
        $configCode = "<?php\n// wakka.config.php " . _t('CREATED') . ' ' . date('Y-m-d H:i:s') . "\n// " .
            _t('DONT_CHANGE_YESWIKI_VERSION_MANUALLY') . " !\n\n\$wakkaConfig = ";
        $configCode .= var_export($config, true) . ";\n?>";

        if ($fp = @fopen($destfolder . 'wakka.config.php', 'w')) {
            fwrite($fp, $configCode);
            fclose($fp);
        } else {
            throw new \Exception('Ecriture du fichier de configuration impossible');
        }
    }

    /**
     * Open a MySQLi connection to the farm's database, ensuring utf8mb4 charset.
     */
    private function createDbConnection(): \mysqli
    {
        $link = mysqli_connect(
            $this->wiki->config['mysql_host'],
            $this->wiki->config['mysql_user'],
            $this->wiki->config['mysql_password'],
            $this->wiki->config['mysql_database'],
            isset($this->wiki->config['mysql_port']) ? $this->wiki->config['mysql_port'] : ini_get('mysqli.default_port')
        );
        mysqli_set_charset($link, 'utf8mb4');
        // dans certains cas (ovh), set_charset ne passe pas, il faut faire une requete sql
        if (mysqli_character_set_name($link) != 'utf8mb4') {
            mysqli_query($link, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        return $link;
    }

    /**
     * Create the database tables for a new wiki and populate them from the chosen model.
     * Wraps everything in a transaction and rolls back on error.
     *
     * @throws \Throwable on SQL error
     */
    private function createWikiDatabase(\mysqli $link, string $prefix, array $replacements, string $model): string
    {
        $notExistingTables = array_filter(
            ['pages', 'links', 'acls', 'triples', 'nature', 'referrers', 'users'],
            function ($tableName) use ($link, $prefix) {
                return mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '$prefix$tableName'")) === 0;
            }
        );

        mysqli_begin_transaction($link);
        mysqli_autocommit($link, false);
        try {
            $sqlReport = $this->querySqlFile($link, 'setup/sql/create-tables.sql', $replacements) . '<hr />';
        } catch (\Throwable $th) {
            $this->resetSQLTransactionWhenError($link, $notExistingTables, $prefix);
            throw $th;
        }

        $sqlfilepath = $model === 'default-content'
            ? 'setup/sql/default-content.sql'
            : 'custom/wiki-models/' . $model . '/default-content.sql';
        try {
            $sqlReport .= $this->querySqlFile($link, $sqlfilepath, $replacements);
        } catch (\Throwable $th) {
            $this->resetSQLTransactionWhenError($link, $notExistingTables, $prefix);
            throw $th;
        }
        mysqli_commit($link);
        mysqli_autocommit($link, true);

        return $sqlReport;
    }

    private function runMigrations($wikiFolder)
    {
        // we launch migrations if wiki has the feature
        if (file_exists($wikiFolder . 'tools/autoupdate/services/MigrationService.php')) {
            // ensure yeswicli is executable
            chmod($wikiFolder . 'yeswicli', 0755);
            $currentDir = getcwd();
            chdir($wikiFolder);
            exec('./yeswicli migrate');
            chdir($currentDir);
        }
    }

    private function resetSQLTransactionWhenError($link, $notExistingTables, $prefix)
    {
        mysqli_rollback($link);
        mysqli_autocommit($link, true);
        foreach ($notExistingTables as $tableName) {
            try {
                if (
                    mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE \"$prefix$tableName\";")) !== 0
                    && mysqli_num_rows(mysqli_query($link, "SELECT * FROM `$prefix$tableName`;")) === 0
                ) {
                    mysqli_query($link, "DROP TABLE IF EXISTS `$prefix$tableName`;");
                }
            } catch (\Throwable $th2) {
            }
        }
    }

    public function updateWiki($wiki)
    {
        $output = '';
        $srcfolder = getcwd() . DIRECTORY_SEPARATOR;
        if ($this->wiki->config['yeswiki-farm-root-folder'] == '.') {
            $destfolder = realpath(getcwd() . DIRECTORY_SEPARATOR . $wiki) . DIRECTORY_SEPARATOR;
        } else {
            $destfolder = realpath(getcwd() . DIRECTORY_SEPARATOR
                . $this->wiki->config['yeswiki-farm-root-folder'] . DIRECTORY_SEPARATOR
                . $wiki) . DIRECTORY_SEPARATOR;
        }

        include_once $destfolder . 'wakka.config.php';
        $output .= '<div class="alert alert-info">' . _t('FERME_UPDATING') . $wiki . '.</div>';

        // nettoyage des anciens tools non utilises TODO : make a migration
        $oldFoldersToDelete = ['tools/despam', 'tools/hashcash', 'tools/ipblock', 'tools/nospam'];
        foreach ($oldFoldersToDelete as $folderToDelete) {
            if (is_dir($destfolder . $folderToDelete)) {
                $this->rrmdir($destfolder . $folderToDelete);
            }
        }

        // mise a jour des fichiers de YesWiki qui ne sont pas des symlink
        foreach ($this->wiki->config['yeswiki_files'] as $file) {
            if (!in_array($file, $this->wiki->config['yeswiki_symlinked_files'])) {
                if (
                    file_exists($destfolder . $file)
                    && !in_array($file, $this->wiki->config['yeswiki_empty_folders'])
                ) {
                    $this->rrmdir($destfolder . $file);
                }
                $this->copyRecursive($srcfolder . $file, $destfolder . $file);
            }
        }
        // mise a jour des extensions de YesWiki de la configuration qui ne sont pas des symlink
        foreach ($this->wiki->config['yeswiki-farm-extra-tools'] as $file) {
            $file = 'tools/' . $file;
            if (!in_array($file, $this->wiki->config['yeswiki_symlinked_files'])) {
                if (
                    file_exists($destfolder . $file)
                    && !in_array($file, $this->wiki->config['yeswiki_empty_folders'])
                ) {
                    $this->rrmdir($destfolder . $file);
                }
                $this->copyRecursive($srcfolder . $file, $destfolder . $file);
            }
        }
        // mise a jour des fichiers de YesWiki qui sont des symlink
        foreach ($this->wiki->config['yeswiki_symlinked_files'] as $file) {
            if (
                file_exists($destfolder . $file)
                && !in_array($file, $this->wiki->config['yeswiki_empty_folders'])
            ) {
                $this->rrmdir($destfolder . $file);
            }
            symlink($srcfolder . $file, $destfolder . $file);
        }

        // change the config file to update yeswiki version
        include_once 'tools/templates/libs/Configuration.php';
        $config = new \Configuration($destfolder . 'wakka.config.php');
        $config->load();
        $config->yeswiki_version = $this->wiki->config['yeswiki_version'];
        $config->yeswiki_release = $this->wiki->config['yeswiki_release'];
        $config->write();

        // execute post update
        $this->runMigrations($destfolder);

        $output .= '<div class="alert alert-success">' . _t('FERME_WIKI') . $wiki . _t('FERME_UPDATED') . '</div>';

        return $output;
    }

    public function deleteWikiForApi(string $idFiche): array
    {
        if (!$this->wiki->UserIsAdmin() && !$this->wiki->UserIsOwner()) {
            return ['success' => false, 'error' => 'Unauthorized'];
        }

        $entryManager = $this->wiki->services->get(EntryManager::class);
        if (!$entryManager->isEntry($idFiche)) {
            return ['success' => false, 'error' => 'Entry not found: ' . $idFiche];
        }

        try {
            $this->wiki->services->get(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (\Throwable $th) {
            return ['success' => false, 'error' => 'Invalid CSRF token'];
        }

        $tab_valeurs = $entryManager->getOne($idFiche);
        if (empty($tab_valeurs['bf_dossier-wiki'])) {
            return ['success' => false, 'error' => 'Wiki folder not set for entry: ' . $idFiche];
        }

        $folder = $tab_valeurs['bf_dossier-wiki'];
        $this->deleteWikiData($folder, $this->getWikiConfig($folder));

        try {
            $entryManager->delete($idFiche, true);
        } catch (\Throwable $th) {
            return ['success' => false, 'error' => 'Entry deletion failed: ' . $th->getMessage()];
        }

        return ['success' => true];
    }

    /**
     * Delete a wiki's files and DB tables from an entry page tag.
     * CSRF must be verified by the caller before invoking this method.
     */
    public function deleteWikiFromEntry($id)
    {
        if (!$this->wiki->UserIsAdmin() && !$this->wiki->UserIsOwner()) {
            return;
        }

        $entryManager = $this->wiki->services->get(EntryManager::class);
        if (!$entryManager->isEntry($id)) {
            return;
        }

        $tab_valeurs = $entryManager->getOne($id);
        if (empty($tab_valeurs['bf_dossier-wiki'])) {
            return;
        }

        $folder = $tab_valeurs['bf_dossier-wiki'];
        $this->deleteWikiData($folder, $this->getWikiConfig($folder));
    }

    /**
     * Delete the wiki folder from disk and drop its database tables.
     */
    private function deleteWikiData(string $folder, array $config): void
    {
        $rootFolder = !empty($this->wiki->config['yeswiki-farm-root-folder'])
            ? $this->wiki->config['yeswiki-farm-root-folder']
            : '.';
        $src = realpath(getcwd() . '/' . $rootFolder . '/' . $folder);

        if ($src && is_dir($src)) {
            $this->rrmdir($src);
            if (!empty($config['table_prefix'])) {
                $prefix = $config['table_prefix'];
                $this->wiki->Query('DROP TABLE IF EXISTS `' . $prefix . 'acls`, `' . $prefix . 'links`, `' . $prefix . 'nature`, `' . $prefix . 'pages`, `' . $prefix . 'referrers`, `' . $prefix . 'triples`, `' . $prefix . 'users`;');
            }
        }
    }

    /**
     * Scan the server for wiki folders and optionally import missing ones into bazar.
     *
     * @param string $adminMail Email to set on auto-imported entries
     * @param bool   $checkHttp Whether to perform an HTTP reachability check per wiki (slow for large farms)
     */
    public function searchWikisOnServer(string $adminMail, bool $checkHttp = true): array
    {
        $entryManager = $this->wiki->services->get(EntryManager::class);
        $pageManager = $this->wiki->services->get(PageManager::class);
        $tripleStore = $this->wiki->services->get(TripleStore::class);

        $wikis = $entryManager->search(['formsIds' => [$this->params->get('bazar_farm_id')]]);
        $wikisFolder = array_column($wikis, 'bf_dossier-wiki');

        $rootFolder = $this->wiki->config['yeswiki-farm-root-folder'] ?? '.';
        $basePath = $rootFolder === '.'
            ? getcwd()
            : getcwd() . DIRECTORY_SEPARATOR . $rootFolder;

        $wikisOnServer = glob($basePath . '/*/wakka.config.php') ?: [];

        $results = [];
        $wikisToImport = [];

        foreach ($wikisOnServer as $path) {
            $folder = basename(dirname($path));
            $wikiExistsInBazar = in_array($folder, $wikisFolder);

            $wakkaConfig = [];
            include $path;

            $url = ($wakkaConfig['base_url'] ?? '') . ($wakkaConfig['root_page'] ?? '');
            $result = [
                'folder' => $folder,
                'url' => $url,
                'existsInBazar' => $wikiExistsInBazar,
                'sqlOk' => false,
                'tablesOk' => false,
                'httpOk' => false,
                'missingTables' => [],
            ];

            $conn = @new \mysqli(
                $wakkaConfig['mysql_host'] ?? '',
                $wakkaConfig['mysql_user'] ?? '',
                $wakkaConfig['mysql_password'] ?? '',
                $wakkaConfig['mysql_database'] ?? ''
            );
            if (!$conn->connect_error) {
                $result['sqlOk'] = true;
                $tables = ['acls', 'links', 'nature', 'pages', 'referrers', 'triples', 'users'];
                foreach ($tables as $table) {
                    $res = mysqli_query($conn, "SHOW TABLES LIKE \"{$wakkaConfig['table_prefix']}$table\"");
                    if (mysqli_num_rows($res) === 0) {
                        $result['missingTables'][] = $table;
                    }
                }
                $result['tablesOk'] = empty($result['missingTables']);
            } else {
                $result['sqlError'] = $conn->connect_error;
            }

            if ($checkHttp) {
                $headers = @get_headers($url);
                $result['httpOk'] = $headers && strpos($headers[0], '200') !== false;
            }

            if (!$wikiExistsInBazar) {
                $wikisToImport[] = [
                    'id_fiche' => genere_nom_wiki($wakkaConfig['wakka_name'] ?? $folder),
                    'id_typeannonce' => strval($this->params->get('bazar_farm_id')),
                    'bf_titre' => $wakkaConfig['wakka_name'] ?? $folder,
                    'bf_description' => $wakkaConfig['meta_description'] ?? '',
                    'bf_referent' => 'À préciser (importé)',
                    'bf_mail' => $adminMail,
                    'bf_dossier-wiki' => $folder,
                    'radioListeOuiNon' => 'oui',
                    'imagebf_image' => 'wiki-imported-placeholder.png',
                    'date_creation_fiche' => date('Y-m-d H:i:s'),
                    'statut_fiche' => '1',
                    'date_maj_fiche' => date('Y-m-d H:i:s'),
                ];
            }

            $results[] = $result;
        }

        $imported = [];
        if (!empty($wikisToImport)) {
            if (!file_exists('files/wiki-imported-placeholder.png')) {
                copy('tools/ferme/images/wiki-imported-placeholder.png', 'files/wiki-imported-placeholder.png');
            }
            foreach ($wikisToImport as $w) {
                $saved = $pageManager->save($w['id_fiche'], json_encode($w), '', true);
                if ($saved == 0) {
                    $tripleStore->create($w['id_fiche'], TripleStore::TYPE_URI, 'fiche_bazar', '', '');
                    $imported[] = $w['bf_dossier-wiki'];
                }
            }
        }

        return [
            'wikisInBazar' => count($wikis),
            'wikisOnServer' => count($wikisOnServer),
            'results' => $results,
            'imported' => $imported,
        ];
    }

    public function getWikiList()
    {
        $fiches = $this->getAllWikiFiches();
        usort($fiches, function ($a, $b) {
            return strcasecmp($a['bf_titre'] ?? '', $b['bf_titre'] ?? '');
        });

        foreach ($fiches as $i => $fiche) {
            $fiches[$i] = $this->processWikiEntry($fiche);
        }

        return $fiches;
    }

    public function getWikiListPaginated(int $start, int $length, string $search, int $orderCol, string $orderDir): array
    {
        $fiches = $this->getAllWikiFiches();
        $total = count($fiches);

        // Filter on basic bazar fields (no per-wiki DB queries needed)
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $fiches = array_values(array_filter($fiches, function ($f) use ($needle) {
                return strpos(mb_strtolower($f['bf_titre'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_referent'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_mail'] ?? ''), $needle) !== false
                    || strpos(mb_strtolower($f['bf_dossier-wiki'] ?? ''), $needle) !== false;
            }));
        }
        $filtered = count($fiches);

        // Sort on bazar fields only (sorting on computed wiki data would require loading all wikis)
        // Column order: 0=checkbox, 1=name, 2=referent, 3=last_update, 4=admin, 5=version, 6=actions
        $sortFields = [1 => 'bf_titre', 2 => 'bf_referent', 3 => 'date_maj_fiche'];
        $sortField = $sortFields[$orderCol] ?? 'bf_titre';
        usort($fiches, function ($a, $b) use ($sortField, $orderDir) {
            $cmp = strcasecmp($a[$sortField] ?? '', $b[$sortField] ?? '');

            return $orderDir === 'desc' ? -$cmp : $cmp;
        });

        // Slice to requested page, then process only those wikis
        $fiches = array_slice($fiches, $start, $length);
        foreach ($fiches as $i => $fiche) {
            $fiches[$i] = $this->processWikiEntry($fiche);
        }

        return ['total' => $total, 'filtered' => $filtered, 'fiches' => $fiches];
    }

    private function getAllWikiFiches(): array
    {
        $entryManager = $this->wiki->services->get(EntryManager::class);
        $bazarFarmId = $this->params->get('bazar_farm_id');
        // check id if wakka.config.php contains a bad value (like string not corresponding to a form's id)
        $bazarFarmId = (!empty($bazarFarmId) && (strval($bazarFarmId) == strval(intval($bazarFarmId)))) ? $bazarFarmId : '1100';

        return $entryManager->search(['formsIds' => [$bazarFarmId]]);
    }

    /**
     * Enrich a bazar entry with live wiki data (version, last modification, admin presence).
     * Returns structured data for version and admin — callers are responsible for rendering.
     */
    private function processWikiEntry(array $fiche): array
    {
        if ($this->wiki->config['yeswiki-farm-root-folder'] == '.') {
            $wikiConfigFile = realpath(getcwd() . '/' . $fiche['bf_dossier-wiki'] . '/wakka.config.php');
        } else {
            $wikiConfigFile = realpath(getcwd() . '/' . $this->wiki->config['yeswiki-farm-root-folder'] . '/' . $fiche['bf_dossier-wiki'] . '/wakka.config.php');
        }

        if (!file_exists($wikiConfigFile)) {
            $fiche['error'] = _t('FERME_FILE') . $fiche['bf_dossier-wiki'] . '/wakka.config.php' . _t('FERME_NOT_FOUND');

            return $fiche;
        }

        $wakkaConfig = [];
        include $wikiConfigFile;

        if (empty($wakkaConfig['table_prefix'])) {
            return $fiche;
        }

        $fiche['url'] = $wakkaConfig['base_url'] . $wakkaConfig['root_page'];

        // Version as structured data — rendering is the caller's responsibility
        $wikiVersion = $wakkaConfig['yeswiki_version'] ?? '';
        $wikiRelease = $wakkaConfig['yeswiki_release'] ?? '';
        if ($this->wiki->config['yeswiki_version'] !== $wikiVersion) {
            $versionStatus = 'different';
            $updateUrl = '';
        } elseif (empty($wikiRelease) || $wikiRelease < $this->wiki->config['yeswiki_release']) {
            $versionStatus = 'outdated';
            $updateUrl = $this->wiki->href('', $this->wiki->GetPageTag(), 'maj=' . $fiche['bf_dossier-wiki']);
        } else {
            $versionStatus = 'up-to-date';
            $updateUrl = '';
        }
        $fiche['version'] = [
            'version' => $wikiVersion,
            'release' => $wikiRelease,
            'status' => $versionStatus,
            'update_url' => $updateUrl,
            'source_version' => $this->wiki->config['yeswiki_version'],
        ];

        // Admin as structured data
        $adminName = $this->wiki->config['yeswiki-farm-admin-name'];
        $adminPass = $this->wiki->config['yeswiki-farm-admin-pass'];
        $fiche['admin'] = null;
        if (!empty($adminName) && !empty($adminPass)) {
            $fiche['admin'] = [
                'name' => $adminName,
                'present' => false,
                'add_url' => $this->wiki->href('', $this->wiki->GetPageTag(), 'superadmin=' . $fiche['bf_dossier-wiki']),
                'remove_url' => $this->wiki->href('', $this->wiki->GetPageTag(), 'nosuperadmin=' . $fiche['bf_dossier-wiki']),
            ];
        }

        // switch to wiki's own database — always restore main DB in finally
        $this->wiki->query('USE ' . $wakkaConfig['mysql_database'] . ';');
        try {
            if ($fiche['admin'] !== null) {
                $sql = 'SELECT name FROM ' . $wakkaConfig['table_prefix'] . 'users WHERE name="' . addslashes($adminName) . '"';
                $fiche['admin']['present'] = count($this->wiki->LoadAll($sql)) > 0;
            }

            $wikiresults = $this->wiki->LoadAll('SELECT time FROM `' . $wakkaConfig['table_prefix'] . 'pages` WHERE latest="Y" ORDER BY time DESC LIMIT 1');
            $fiche['last_modification_iso'] = $wikiresults[0]['time'] ?? '';
            if (!empty($fiche['last_modification_iso'])) {
                $date = new \DateTime($fiche['last_modification_iso']);
                $fiche['last_modification'] = $date->format('d.m.Y H:i:s');
            }
        } finally {
            // always switch back to main wiki database, even on exception
            $this->wiki->query('USE ' . $this->wiki->config['mysql_database'] . ';');
        }

        $fiche['dashboard_link'] = $wakkaConfig['base_url'] . 'TableauDeBord';

        return $fiche;
    }

    /**
     * recursive remove file or folder.
     *
     * @param string $src path
     *
     * @return void
     */
    public function rrmdir($src)
    {
        $dir = opendir($src);
        if ($dir) {
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..')) {
                    $full = $src . '/' . $file;
                    if (is_dir($full)) {
                        $this->rrmdir($full);
                    } else {
                        unlink($full);
                    }
                }
            }
            closedir($dir);
            rmdir($src);
        }
    }

    /**
     * recursive copy file or folder.
     *
     * @param string $path : source path
     * @param string $dest : destination path
     *
     * @return void
     */
    public function copyRecursive($path, $dest)
    {
        if (is_dir($path)) {
            @mkdir($dest, 0777, true);
            $objects = scandir($path);
            if (count($objects) > 0) {
                foreach ($objects as $file) {
                    if ($file == '.' || $file == '..' || $file == '.git' || $file == 'bower_components') {
                        continue;
                    }
                    // go on
                    if (is_dir($path . DIRECTORY_SEPARATOR . $file)) {
                        $this->copyRecursive($path . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file);
                    } else {
                        copy($path . DIRECTORY_SEPARATOR . $file, $dest . DIRECTORY_SEPARATOR . $file);
                    }
                }
            }

            return true;
        } elseif (is_file($path) && file_exists($path)) {
            return copy($path, $dest);
        } else {
            return false;
        }
    }

    /**
     * Returns the real path of given path even for non existent path, with trailing /.
     *
     * @param string $path
     *
     * @return string
     */
    public function getAbsolutePath($path)
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' == $part) {
                continue;
            }
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $absolutes) . DIRECTORY_SEPARATOR;
    }

    /**
     * replace tokens in sql file and query sql
     * inspired from /setup/install.helpers.php ->querySqlFile().
     *
     * @param object $dblink       mysqli link resource
     * @param string $sqlFile      path to sql file
     * @param array  $replacements token to replace in sql file
     *
     * @return string the report of the queries
     */
    public function querySqlFile($dblink, $sqlFile, $replacements = [])
    {
        $sqlReport = '<h4>' . _t('FERME_REPORT') . ' ' . $sqlFile . '</h4>';
        if ($sql = file_get_contents($sqlFile)) {
            foreach ($replacements as $keyword => $replace) {
                $sql = str_replace(
                    '{{' . $keyword . '}}',
                    mysqli_real_escape_string($dblink, $replace),
                    $sql
                );
            }
            // first statements
            $index = 1;
            if (!mysqli_multi_query($dblink, $sql)) {
                throw new \Exception(str_replace(['{num}', '{file}', '{errorMsg}'], [$index, $sqlFile, mysqli_error($dblink)], _t('FERME_INSERTION_ERROR')));
            } else {
                $sqlReport .= str_replace(
                    ['{num}', '{nbRows}'],
                    [$index, mysqli_affected_rows($dblink)],
                    _t('FERME_INSERTION')
                ) . '<br/>';
                while (mysqli_more_results($dblink)) {
                    $index = $index + 1;
                    if (!mysqli_next_result($dblink)) {
                        throw new \Exception(str_replace(['{num}', '{file}', '{errorMsg}'], [$index, $sqlFile, mysqli_error($dblink)], _t('FERME_INSERTION_ERROR')));
                    } else {
                        $sqlReport .= str_replace(
                            ['{num}', '{nbRows}'],
                            [$index, mysqli_affected_rows($dblink)],
                            _t('FERME_INSERTION')
                        ) . '<br/>';
                    }
                }
            }
        } else {
            throw new \Exception(_t('SQL_FILE_NOT_FOUND') . ' "' . $sqlFile . '".');
        }

        return $sqlReport;
    }

    public function getModelLabels()
    {
        // get labels for models
        $models = [];
        foreach ($this->wiki->config['yeswiki-farm-models'] as $model) {
            if ($model != 'default-content') {
                $json = \json_decode(\file_get_contents('custom/wiki-models/' . $model . '/infos.json'), true);
            } else {
                $json = [];
                $json['label'] = _t('FERME_BASIC_INSTALL');
            }
            $models[$model] = $json['label'];
        }

        return $models;
    }
}
