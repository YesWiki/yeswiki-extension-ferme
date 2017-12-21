<?php
if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}

if ($this->UserIsAdmin()) {
    echo '<h1>Administration de tous les wikis</h1>';
    if (isset($_GET['maj'])) {
        $srcfolder = getcwd().DIRECTORY_SEPARATOR;
        $destfolder = getcwd().DIRECTORY_SEPARATOR.$GLOBALS['wiki']->config['yeswiki-farm-root-folder']
                  .DIRECTORY_SEPARATOR.$_GET['maj'].DIRECTORY_SEPARATOR;
        echo '<div class="alert alert-info">Mise a jour de '.$destfolder.' à partir de '.$srcfolder.'.</div>';
        // nettoyage des anciens tools non utilises
        if (is_dir($destfolder.'tools/despam')) {
            rrmdir($destfolder.'tools/despam');
        }
        if (is_dir($destfolder.'tools/hashcash')) {
            rrmdir($destfolder.'tools/hashcash');
        }
        if (is_dir($destfolder.'tools/ipblock')) {
            rrmdir($destfolder.'tools/ipblock');
        }
        if (is_dir($destfolder.'tools/nospam')) {
            rrmdir($destfolder.'tools/nospam');
        }
        copyRecursive($srcfolder.'index.php', $destfolder.'index.php');
        copyRecursive($srcfolder.'interwiki.conf', $destfolder.'interwiki.conf');
        copyRecursive($srcfolder.'robots.txt', $destfolder.'robots.txt');
        copyRecursive($srcfolder.'tools.php', $destfolder.'tools.php');
        copyRecursive($srcfolder.'wakka.basic.css', $destfolder.'wakka.basic.css');
        copyRecursive($srcfolder.'wakka.css', $destfolder.'wakka.css');
        copyRecursive($srcfolder.'wakka.php', $destfolder.'wakka.php');

        // les dossiers de base des yeswiki
        copyRecursive($srcfolder.'actions', $destfolder.'actions');
        copyRecursive($srcfolder.'formatters', $destfolder.'formatters');
        copyRecursive($srcfolder.'handlers', $destfolder.'handlers');
        copyRecursive($srcfolder.'includes', $destfolder.'includes');
        copyRecursive($srcfolder.'lang', $destfolder.'lang');
        copyRecursive($srcfolder.'setup', $destfolder.'setup');

        // themes
        copyRecursive($srcfolder.'themes', $destfolder.'themes');

        // templates
        copyRecursive(
            $srcfolder.'themes'.DIRECTORY_SEPARATOR.'tools',
            $destfolder.'themes'.DIRECTORY_SEPARATOR.'tools'
        );
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
        echo '<div class="alert alert-success">Le wiki '.$_GET['maj'].' a bien été mise a jour.</div>';
    }
    $sql = 'SELECT body FROM '.$this->config['table_prefix'].'pages WHERE latest="Y" and body like \'%"bf_dossier-wiki":"%\'';
    $results = $this->LoadAll($sql);
    $results = searchResultstoArray($results, array(), '');
    $GLOBALS['ordre'] = 'asc';
    $GLOBALS['champ'] = 'bf_titre';
    usort($results, 'champCompare');
    $list = '';
    foreach ($results as $fiche) {
        include_once $fiche['bf_dossier-wiki'].'/wakka.config.php';
        //$fiche = json_decode($res['body'], true);

        $url = $wakkaConfig['base_url'].$wakkaConfig['root_page'];
        $sql = 'SELECT tag,time FROM '.$wakkaConfig['table_prefix'].'pages WHERE latest="Y" ORDER BY time DESC LIMIT 8';
        $wikiresults = $this->LoadAll($sql);
        $list .= '<li class="item-wiki"><a href="'.$url.'">'.$fiche['bf_titre'].' - <small>'.$url.'</small></a>';
        $list .= '<br><strong>Version de yeswiki : '.$wakkaConfig['yeswiki_release'].'</strong> <a class="btn btn-danger" href="'.$GLOBALS['wiki']->href('', $GLOBALS['wiki']->GetPageTag(), 'maj='.$fiche['bf_dossier-wiki']).'">Mettre à jour</a>';
        $list .= '<br><strong>Dernières pages modifiées :</strong><small>';
        foreach ($wikiresults as $page) {
            $list .= '<br><a href="'.$wakkaConfig['base_url'].$page['tag'].'">'.$page['tag'].' <small>('.$page['time'].')</small></a>';
        }
        $list .= '</small><hr></li>';
    }
    if ($list) {
        echo '<ol class="list-wiki">'.$list.'</ol>';
    }
} else {
    echo '<div class="alert alert-danger">Il faut faire parti du groupe @admins pour administrer les wikis.</div>';
}
