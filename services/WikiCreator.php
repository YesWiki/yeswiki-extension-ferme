<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Creates a wiki from a bazar entry: copies the source tree, writes the wiki's config,
 * builds its tables from a model, then applies the entry's own options.
 */
class WikiCreator
{
    protected $wiki;
    protected $config;
    protected $files;
    protected $yeswicli;

    public function __construct(Wiki $wiki, FarmConfig $config, FileSystem $files, Yeswicli $yeswicli)
    {
        $this->wiki = $wiki;
        $this->config = $config;
        $this->files = $files;
        $this->yeswicli = $yeswicli;
    }

    /**
     * @param array  $entry     the bazar entry data
     * @param string $fieldName the field name holding the wiki folder
     * @param string $theme     index into the yeswiki-farm-themes config array
     * @param string $model     model name ('default-content' or a custom model folder)
     *
     * @throws \Exception on validation or I/O failure
     */
    public function createFromEntry(array $entry, string $fieldName, string $theme = '0', string $model = 'default-content'): void
    {
        $entry = $this->resolveWikiName($entry, $fieldName);
        $entry = $this->resolveEmail($entry, $fieldName);

        if ($this->wiki->config['yeswiki-farm-create-user']) {
            $this->createFarmUser($entry, $fieldName);
        }

        $folder = $entry[$fieldName];
        $destfolder = $this->config->wikiDir($folder);

        if (is_dir($destfolder)) {
            throw new \Exception('L\'adresse ' . $this->config->rootUrl() . $folder . ' est déja utilisée, veuillez en prendre une autre.');
        }
        if (!is_writable($this->config->rootFolder())) {
            throw new \Exception('Le dossier ' . $this->config->rootFolder() . ' n\'est pas accessible en écriture');
        }

        $this->copyWikiFiles(getcwd() . DIRECTORY_SEPARATOR, $destfolder);

        $prefix = $this->tablePrefix($entry, $fieldName);
        $config = $this->buildWikiConfig($entry, $fieldName, $prefix, $this->resolveRights($entry, $fieldName), $this->config->theme($theme));
        $this->writeWikiConfig($destfolder, $config);

        $link = $this->createDbConnection();
        $sqlReport = $this->createWikiDatabase($link, $prefix, [
            'prefix' => $prefix,
            'siteTitle' => $config['wakka_name'],
            'WikiName' => $entry[$fieldName . '_wikiname'],
            'hashedpassword' => md5($entry[$fieldName . '_password']),
            'email' => $entry[$fieldName . '_email'],
            'rootPage' => $config['root_page'],
        ], $model);

        $this->reportSql($sqlReport);

        if ($model !== 'default-content') {
            $this->copyModelFiles($model, $destfolder);
        }

        if (!empty($entry['access-username'])) {
            $this->createWikiUser($link, $prefix, $entry, $fieldName);
        }

        if (!empty($entry['yeswiki-farm-options'])) {
            $this->applyOptions($prefix, $entry['yeswiki-farm-options']);
        }

        $this->yeswicli->migrate($destfolder);

        $this->createGroup($prefix, $entry);
    }

    /*
     * -------------------------------------------------------------- the entry
     */

    /**
     * '{{folder}}' means "name the admin account after the wiki folder".
     */
    private function resolveWikiName(array $entry, string $fieldName): array
    {
        if ($entry[$fieldName . '_wikiname'] !== '{{folder}}') {
            return $entry;
        }

        $entry[$fieldName . '_wikiname'] = genere_nom_wiki($entry[$fieldName], 0);
        if ($this->wiki->LoadUser($entry[$fieldName . '_wikiname'])) {
            throw new \Exception('L\'utilisateur ' . $entry[$fieldName . '_wikiname'] . ' existe déjà, veuillez trouver un autre nom pour votre wiki.');
        }

        return $entry;
    }

    /**
     * The email field may hold the name of another field to read the address from.
     */
    private function resolveEmail(array $entry, string $fieldName): array
    {
        $key = $fieldName . '_email';
        if (!empty($entry[$key]) && !empty($entry[$entry[$key]])) {
            $entry[$key] = $entry[$entry[$key]];
        }

        return $entry;
    }

    /**
     * '{{user}}' in an acl means the wiki's own admin account.
     */
    private function resolveRights(array $entry, string $fieldName): array
    {
        $rights = $this->config->acl($entry['yeswiki-farm-acls']);
        $username = !empty($entry['access-username']) ? $entry['access-username'] : $entry[$fieldName . '_wikiname'];

        foreach (['write', 'read', 'comments'] as $right) {
            if ($rights[$right] == '{{user}}') {
                $rights[$right] = $username;
            }
        }

        return $rights;
    }

    /**
     * Table prefix for the new wiki, already carrying its trailing '__'.
     */
    private function tablePrefix(array $entry, string $fieldName): string
    {
        return empty($entry['bf_prefixe'])
            ? $this->wiki->config['yeswiki-farm-prefix'] . str_replace('-', '_', $entry[$fieldName]) . '__'
            : $entry['bf_prefixe'];
    }

    /*
     * --------------------------------------------------------------- the files
     */

    /**
     * Copy all YesWiki source files and symlinks into the new wiki destination folder.
     */
    private function copyWikiFiles(string $srcfolder, string $destfolder): void
    {
        $symlinked = $this->wiki->config['yeswiki_symlinked_files'];

        mkdir($destfolder, 0755, true);
        foreach ($this->wiki->config['yeswiki_empty_folders'] as $folder) {
            if (!in_array($folder, $symlinked)) {
                mkdir($destfolder . $folder, 0777, true);
            }
        }

        foreach ($this->wiki->config['yeswiki_files'] as $file) {
            if (!in_array($file, $symlinked)) {
                $this->files->copyRecursive($srcfolder . $file, $destfolder . $file);
            }
        }

        foreach ($symlinked as $file) {
            symlink($srcfolder . $file, $destfolder . $file);
        }

        foreach (['themes' => 'yeswiki-farm-extra-themes', 'tools' => 'yeswiki-farm-extra-tools'] as $parent => $configKey) {
            foreach ($this->wiki->config[$configKey] as $dir) {
                $this->files->copyRecursive(
                    $srcfolder . $parent . DIRECTORY_SEPARATOR . $dir,
                    $destfolder . $parent . DIRECTORY_SEPARATOR . $dir
                );
            }
        }
    }

    private function copyModelFiles(string $model, string $destfolder): void
    {
        foreach (['files', 'custom'] as $dir) {
            $source = 'custom/wiki-models/' . $model . '/' . $dir;
            if (is_dir($source)) {
                $this->files->copyRecursive($source, $destfolder . $dir);
            }
        }
    }

    /**
     * Build the wakka.config.php array for a new wiki.
     */
    private function buildWikiConfig(array $entry, string $fieldName, string $prefix, array $rights, array $theme): array
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
            'base_url' => $this->config->rootUrl() . $entry[$fieldName] . '/?',
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
            'favorite_theme' => $theme['theme'],
            'favorite_style' => $theme['style'],
            'favorite_squelette' => $theme['squelette'],
            'favorite_preset' => $theme['preset'] ?? '',
            'favorite_background_image' => $theme['bg-img'] ?? '',
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

    /*
     * ------------------------------------------------------------ the database
     */

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
            WikiRepository::WIKI_TABLES,
            function ($tableName) use ($link, $prefix) {
                return mysqli_num_rows(mysqli_query($link, "SHOW TABLES LIKE '$prefix$tableName'")) === 0;
            }
        );

        $modelFile = $model === 'default-content'
            ? 'setup/sql/default-content.sql'
            : 'custom/wiki-models/' . $model . '/default-content.sql';

        mysqli_begin_transaction($link);
        mysqli_autocommit($link, false);
        try {
            $sqlReport = $this->runSqlFile($link, 'setup/sql/create-tables.sql', $replacements) . '<hr />';
            $sqlReport .= $this->runSqlFile($link, $modelFile, $replacements);
        } catch (\Throwable $th) {
            $this->resetSQLTransactionWhenError($link, $notExistingTables, $prefix);
            throw $th;
        }
        mysqli_commit($link);
        mysqli_autocommit($link, true);

        return $sqlReport;
    }

    /**
     * Roll back, then drop any table this run created and left empty, so a failed
     * creation does not leave half a wiki behind.
     */
    private function resetSQLTransactionWhenError($link, $notExistingTables, $prefix): void
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
    public function runSqlFile($dblink, $sqlFile, $replacements = [])
    {
        $sqlReport = '<h4>' . _t('FERME_REPORT') . ' ' . $sqlFile . '</h4>';
        if (!$sql = file_get_contents($sqlFile)) {
            throw new \Exception(_t('SQL_FILE_NOT_FOUND') . ' "' . $sqlFile . '".');
        }

        foreach ($replacements as $keyword => $replace) {
            $sql = str_replace('{{' . $keyword . '}}', mysqli_real_escape_string($dblink, $replace), $sql);
        }

        $index = 1;
        if (!mysqli_multi_query($dblink, $sql)) {
            throw new \Exception(str_replace(['{num}', '{file}', '{errorMsg}'], [$index, $sqlFile, mysqli_error($dblink)], _t('FERME_INSERTION_ERROR')));
        }
        $sqlReport .= $this->insertionReport($index, mysqli_affected_rows($dblink));

        while (mysqli_more_results($dblink)) {
            ++$index;
            if (!mysqli_next_result($dblink)) {
                throw new \Exception(str_replace(['{num}', '{file}', '{errorMsg}'], [$index, $sqlFile, mysqli_error($dblink)], _t('FERME_INSERTION_ERROR')));
            }
            $sqlReport .= $this->insertionReport($index, mysqli_affected_rows($dblink));
        }

        return $sqlReport;
    }

    private function insertionReport(int $index, int $rows): string
    {
        return str_replace(['{num}', '{nbRows}'], [$index, $rows], _t('FERME_INSERTION')) . '<br/>';
    }

    private function reportSql(string $sqlReport): void
    {
        if (empty($_GET['debug']) && $this->wiki->config['debug'] != 'yes') {
            return;
        }

        if (function_exists('flash')) {
            flash($sqlReport, 'success');
        } else {
            $this->wiki->SetMessage($sqlReport);
        }
    }

    /*
     * ------------------------------------------------------- users and options
     */

    /**
     * Some farms keep one central database and want the account in the farm wiki too.
     */
    private function createFarmUser(array $entry, string $fieldName): void
    {
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

    /**
     * A second account inside the new wiki, when the entry asked for one.
     */
    private function createWikiUser(\mysqli $link, string $prefix, array $entry, string $fieldName): void
    {
        $this->wiki->Query("INSERT INTO `{$prefix}users` " .
            '(`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) ' .
            "VALUES ('" . mysqli_real_escape_string($link, $entry['access-username']) . "', " .
            "md5('" . mysqli_real_escape_string($link, $entry['access-password']) . "'), " .
            "'" . $entry[$fieldName . '_email'] . "', '', '20', '50', 1, now(), 2);");
    }

    /**
     * Append each chosen option's content to the page it belongs to.
     */
    private function applyOptions(string $prefix, string $options): void
    {
        foreach (explode(',', $options) as $option) {
            $this->wiki->Query('UPDATE `' . $prefix . 'pages` SET body=CONCAT(body, "'
                . $this->wiki->config['yeswiki-farm-options'][$option]['content'] . '") WHERE tag="'
                . $this->wiki->config['yeswiki-farm-options'][$option]['page'] . '" AND latest="Y";');
        }
    }

    /**
     * Create the configured group in the new wiki and fill it from one of the entry's fields.
     */
    private function createGroup(string $prefix, array $entry): void
    {
        if (
            !isset($this->wiki->config['yeswiki-farm-group'])
            || !is_array($this->wiki->config['yeswiki-farm-group'])
        ) {
            return;
        }

        $groupName = $this->wiki->config['yeswiki-farm-group']['groupname'];
        $resource = GROUP_PREFIX . $groupName;
        $tripletable = $prefix . 'triples';

        $this->wiki->Query('DELETE FROM `' . $tripletable . '` WHERE `resource`="' . $resource
            . '" and `property`="' . WIKINI_VOC_ACLS_URI . '";');

        $users = $entry[$this->wiki->config['yeswiki-farm-group']['group_members_field']];
        $this->wiki->Query('INSERT INTO `' . $tripletable . '` (`resource`, `property`, `value`)'
            . ' VALUES (\'' . $resource . '\', \'' . WIKINI_VOC_ACLS_URI . '\', \''
            . implode("\n", explode(',', $users)) . '\');');
    }
}
