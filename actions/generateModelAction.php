<?php

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmService;

class GenerateModelAction extends YesWikiAction
{
    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $farm = $this->getService(FarmService::class);
            $farm->initFarmConfig();
            // a model will be imported
            if (isset($_POST['wiki-import-forms'])) {
                $output .= $this->generateSqlModel($_POST);
            }
            // a model will be deleted
            if (!empty($_GET['delete_model'])) {
                $output .= $this->deleteModel($_GET['delete_model']);
            }

            // get all custom models
            $modelsFolder = glob('custom/wiki-models/*', GLOB_ONLYDIR);
            $defaultModelIsAvailable = array_search('default-content', $this->wiki->config['yeswiki-farm-models']);
            $models = [];
            foreach ($modelsFolder as $modelFolder) {
                if (is_file($modelFolder.'/default-content.sql') && is_file($modelFolder.'/infos.json')) {
                    $model = str_replace('custom/wiki-models/', '', $modelFolder);
                    $json = json_decode(file_get_contents($modelFolder.'/infos.json', true), true);
                    $models[$model]['label'] = $json['label'];
                    $models[$model]['model'] = $model;
                    $models[$model]['url'] = 'https://'.str_replace(['--'], ['/', ''], $model);
                    $models[$model]['deleteurl'] = $this->wiki->href('', '', 'delete_model='.$model);
                    $models[$model]['isavailable'] = array_search($model, $this->wiki->config['yeswiki-farm-models']);
                }
            }

            $template = $this->wiki->GetParameter('template');
            if (empty($template)) {
                $template = 'generate-model.twig';
            }
            $output .= $this->render(
                '@ferme/'.$template,
                [
                  'formurl' => $this->wiki->href('', $this->wiki->GetPageTag()),
                  'models' => $models,
                  'defaultModelIsAvailable' => $defaultModelIsAvailable,

                ]
            );
            $GLOBALS['wiki']->AddJavascriptFile('tools/ferme/javascripts/ferme-import.js');
        } else {
            $output .=  '<div class="alert alert-danger">'
            .'  <strong>'._t('TEMPLATE_ACTION').' {{generatemodel}}</strong> : '
            ._t('TEMPLATE_ACTION_FOR_ADMINS_ONLY')
            .'</div>'."\n";
        }
        return $output;
    }

    public function generateSqlModel($data)
    {
        $output = '';
        $f = explode('/wakka.php', $data["url-import"]);
        $f = explode('/?', $f[0]);
        $filename = 'custom/wiki-models/'.str_replace(
            array('http://', 'https://', '/'),
            array('', '', '--'),
            $f[0]
        ).'.sql';
        $sql = '';
  
        $pages = json_decode(html_entity_decode($data["wiki-import-pages"]), 1);
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
  
        $forms = json_decode(html_entity_decode($data["wiki-import-forms"]), 1);
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
  
        $lists = json_decode(html_entity_decode($data["wiki-import-lists"]), 1);
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
  
        $entries = json_decode(html_entity_decode($data["wiki-import-entries"]), 1);
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
            $output .= '<div class="alert alert-danger">'.
                   '  <strong>'._t('TEMPLATE_ACTION').' {{generatemodel}}</strong> : '
                   ._t('le fichier '.$filename.' n\'a pas pu être créé..').
                   '</div>'."\n";
        } else {
            $output .=  '<div class="alert alert-success">'
                   ._t('Le fichier <a href="'.$filename.'">'.$filename.'</a> vient d\'être enregistré avec succès.')
                   .'</div>'."\n";
        }
    }

    public function deleteModel($model)
    {
        if (is_dir('custom/wiki-models/'.$model)) {
            $this->rrmdir('custom/wiki-models/'.$model);
            $output = '<div class="alert alert-success">Le modèle "custom/wiki-models/'.$model.'" vient d\'être supprimé.</div>';
        } else {
            $output = '<div class="alert alert-warning">Le modèle "custom/wiki-models/'.$model.'" n\'a pas été trouvé.</div>';
        }
        return $output;
    }


    /**
     * recursive remove file or folder
     *
     * @param string $src path
     * @return void
     */
    protected function rrmdir($src)
    {
        $dir = opendir($src);
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
