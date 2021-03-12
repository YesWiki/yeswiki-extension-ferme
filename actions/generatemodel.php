<?php

use YesWiki\Core\Service\TemplateEngine;

if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}

if ($this->UserIsAdmin()) {
    $output = '';
    if (isset($_POST['wiki-import-forms'])) {
        $f = explode('/wakka.php', $_POST["url-import"]);
        $f = explode('/?', $f[0]); 
        $filename = 'files/'.str_replace(
            array('http://', 'https://', '/'),
            array('', '', '--'),
            $f[0]
        ).'.sql';
        $sql = '';

        $pages = json_decode(html_entity_decode($_POST["wiki-import-pages"]), 1);
        if (is_array($pages)) {
            $sql .= '# YesWiki pages'."\n";
            foreach ($pages as $page) {
                $tabpages[] = "('".$page['tag']."',  now(), '".addslashes($page['body'])
                  ."', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
            }
            $sql .= "INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,"
            ." `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
            .implode(','."\n", $tabpages).";\n";
            $sql .= '# end YesWiki pages'."\n\n";
        }

        $forms = json_decode(html_entity_decode($_POST["wiki-import-forms"]), 1);
        if (is_array($forms)) {
            $sql .= '# Bazar forms'."\n";
            foreach ($forms as $form) {
                $tabforms[] = "('".$form['bn_id_nature'] . "', '" . addslashes($form['bn_label_nature'])
                  . "', '" . addslashes($form['bn_description']) . "', '" . addslashes($form['bn_condition'])
                  . "', '" . (!empty($form['bn_sem_context'] ? addslashes($form['bn_sem_context']) : ''))
                  . "', '" . (!empty($form['bn_sem_type'] ? addslashes($form['bn_sem_type']) : ''))
                  . "', '" . (!empty($form['bn_sem_use_template'] ? addslashes($form['bn_sem_use_template']) : "1"))
                  . "', '" . addslashes($form['bn_template'])."', 'fr-FR')";
            }
            $sql .= "INSERT INTO `{{prefix}}nature` (`bn_id_nature`, `bn_label_nature`"
              .", `bn_description`, `bn_condition`, `bn_sem_context`, `bn_sem_type`"
              .", `bn_sem_use_template`, `bn_template`, `bn_ce_i18n`)"
              ." VALUES\n".implode(','."\n", $tabforms).";\n";
            $sql .= '# end Bazar forms'."\n\n";
        }

        $lists = json_decode(html_entity_decode($_POST["wiki-import-lists"]), 1);
        if (is_array($lists)) {
            $sql .= '# Bazar lists'."\n";
            foreach ($lists as $id => $list) {
                $json = json_encode($list);
                $tablists[] = "('".$id."',  now(), '".addslashes($json)
                  ."', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                $tabliststriple[] = "('".$id."', 'http://outils-reseaux.org/_vocabulary/type', 'liste')";
            }
            $sql .= "INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,"
              ." `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
              .implode(','."\n", $tablists).";\n";
            $sql .= "INSERT INTO `{{prefix}}triples` (`resource`, `property`, `value`) VALUES\n"
              .implode(','."\n", $tabliststriple).";\n";
            $sql .= '# end Bazar lists'."\n\n";
        }

        $entries = json_decode(html_entity_decode($_POST["wiki-import-entries"]), 1);
        if (is_array($entries)) {
            $sql .= '# Bazar entries'."\n";
            foreach ($entries as $id => $item) {
                $json = json_encode($item);
                $tabentries[] = "('".$id."',  now(), '".addslashes($json)
                  ."', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                $tabentriestriple[] = "('".$id."', 'http://outils-reseaux.org/_vocabulary/type', 'fiche_bazar')";
            }
            $sql .= "INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,"
              ." `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
              .implode(','."\n", $tabentries).";\n";
            $sql .= "INSERT INTO `{{prefix}}triples` (`resource`, `property`, `value`) VALUES\n"
              .implode(','."\n", $tabentriestriple).";\n";
            $sql .= '# end Bazar entries'."\n\n";
        }

        // creation du fichier sql
        if (!file_put_contents($filename, $sql)) {
            echo '<div class="alert alert-danger">'.
                 '  <strong>'._t('TEMPLATE_ACTION').' {{generatemodel}}</strong> : '
                 ._t('le fichier '.$filename.' n\'a pas pu être créé..').
                 '</div>'."\n";
        } else {
            echo '<div class="alert alert-success">'
                 ._t('Le fichier <a href="'.$filename.'">'.$filename.'</a> vient d\'être enregistré avec succès.')
                 .'</div>'."\n".'<a class="btn btn-primary" href="'
                 .$this->href('', $this->GetPageTag()).'">'
                 ._t('Importer d\'autres modèles').'</a>'."\n";
        }
    } else {
        $file = $this->GetParameter('template');
        if (empty($file)) {
            $file = '@ferme/form-import-wiki.tpl.html';
        }

        $output .= $this->services->get(TemplateEngine::class)->render($file, array(
          'formurl' => $this->href('', $this->GetPageTag()),
        ));

        $GLOBALS['wiki']->AddJavascriptFile('tools/ferme/presentation/javascripts/ferme-import.js');
    }
    echo $output;
} else {
    echo '<div class="alert alert-danger">'
         .'  <strong>'._t('TEMPLATE_ACTION').' {{generatemodel}}</strong> : '
         ._t('TEMPLATE_ACTION_FOR_ADMINS_ONLY')
         .'</div>'."\n";
}
