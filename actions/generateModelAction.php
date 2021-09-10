<?php

use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmService;

class GenerateModelAction extends YesWikiAction
{
    public function formatArguments($args)
    {
        return [
            'template' => !empty($args['template']) ? $args['template'] : 'generate-model.twig',
            'wiki-import-forms' => $_POST['wiki-import-forms'] ?? null,
            'model_label' => !empty($_POST['model_label']) ? $_POST['model_label'] : null,
            'POST' => $_POST,
            'delete_model' => $_GET['delete_model'] ?? null,
        ];
    }

    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $farm = $this->getService(FarmService::class);
            $farm->initFarmConfig();
            // a model will be imported
            if (!is_null($this->arguments['wiki-import-forms'])) {
                $output .= $this->generateSqlModel($this->arguments['POST']);
            }
            // a model will be deleted
            if (!empty($this->arguments['delete_model'])) {
                $output .= $this->deleteModel($this->arguments['delete_model']);
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

            $output .= $this->render(
                '@ferme/'.$this->arguments['template'],
                [
                  'formurl' => $this->wiki->href('', $this->wiki->GetPageTag()),
                  'models' => $models,
                  'defaultModelIsAvailable' => $defaultModelIsAvailable,

                ]
            );
            $this->wiki->AddJavascriptFile('tools/ferme/javascripts/ferme-import.js');
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
        $infos = [];
        $extraction = $this->extractBaseUrlAndRootPage($data["url-import"]);
        if (empty($extraction)) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'warning',
                'message' => _t('FERME_NOT_POSSIBLE_TO_IMPORT_MODEL').' : '.$data["url-import"],
            ]);
        }
        list($baseUrl, $rootPage, $rewriteModeEnabled) = $extraction;

        $foldername = 'custom/wiki-models/'.str_replace(
            array('http://', 'https://', '/'),
            array('', '', '--'),
            $baseUrl
        );
        if (!is_dir($foldername)) {
            @mkdir($foldername, 0777, true);
        }
        $infos['label'] = !empty($this->arguments['model_label']) ? $this->arguments['model_label'] : $baseUrl;
        $infos['sourceUrl'] = $baseUrl;
        $infos['dateOfCreation'] = date("Y-m-d H:i:s");
        $jsonData = json_encode($infos);
        file_put_contents($foldername.'/infos.json', $jsonData);

        $filename = $foldername.'/default-content.sql';
        $sql = '';

        $pages = json_decode(html_entity_decode($data["wiki-import-pages"]), 1);
        if (is_array($pages)) {
            $sql .= '# YesWiki pages'."\n";
            foreach ($pages as $page) {
                // remove hardcoded source urls in pages
                if (!$rewriteModeEnabled) {
                    $page['body'] = str_replace(str_replace('/', '\\/', $baseUrl).'\\/wakka.php?', '{{url}}', $page['body']);
                    $page['body'] = str_replace(str_replace('/', '\\/', $baseUrl).'\\/?', '{{url}}', $page['body']);
                }
                // replace rootPage
                $page['body'] = str_replace($rootPage, '{{rootPage}}', $page['body']);
                $tabpages[] = "('".($page['tag'] ==  $rootPage ? '{{rootPage}}' : $page['tag'])."',  now(), '".addslashes($page['body'])
                    ."', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                // extract images
                $this->extractPageImages($page, $baseUrl, $rewriteModeEnabled, $foldername);
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
                
                // remove not needed fields (to synchronize with EntryManager::formatDataBeforeSave)
                unset($item['valider']);
                unset($item['MAX_FILE_SIZE']);
                unset($item['antispam']);
                unset($item['mot_de_passe_wikini']);
                unset($item['mot_de_passe_repete_wikini']);
                unset($item['html_data']);
                unset($item['url']);
                unset($item['owner']);

                $json = json_encode($item);
                $tabentries[] = "('".$id."',  now(), '".addslashes($json)
                    ."', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                $tabentriestriple[] = "('".$id."', 'http://outils-reseaux.org/_vocabulary/type', 'fiche_bazar')";
                // extract images
                $this->extractEntryImages($item, $baseUrl, $rewriteModeEnabled, $foldername);
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
        return $output;
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

    /**
     * extract baseUrl and rootPage
     * @param string $inputUrl
     * @return array [$baseUrl,$rootPage,$rewriteModeEnabled]
     */
    private function extractBaseUrlAndRootPage(string $inputUrl): array
    {
        $redirectedInputUrl = $this->retrieveUrlAfterRedirect($inputUrl);
        $extraction = $this->extractBaseUrlModeAndTag($redirectedInputUrl);
        if (empty($extraction)) {
            return [];
        }
        list($baseUrl, $rewriteModeEnabled, $tag) = $extraction;
        $redirectedRootUrl = $this->retrieveUrlAfterRedirect($baseUrl.'/');
        $extraction = $this->extractBaseUrlModeAndTag($redirectedInputUrl);
        if (empty($extraction)) {
            return [];
        }
        list($baseUrl, $rewriteModeEnabled, $rootPage) = $extraction;
        return [$baseUrl,$rootPage,$rewriteModeEnabled];
    }

    /**
     * extract baseUrl, rewriteModeEnabled and tag
     * @param string $inputUrl
     * @return array [$baseUrl, $rewriteModeEnabled, $tag]
     */
    private function extractBaseUrlModeAndTag($inputUrl): array
    {
        if (preg_match('/wiki=('.WN_CAMEL_CASE_EVOLVED.')/u', $inputUrl, $matches)) {
            $tag = $matches[1];
            if (preg_match('/(.*)\/wakka.php\?.*wiki='.$tag.'/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = false;
                $baseUrl = $matches[1];
            } elseif (preg_match('/(.*)\/\?.*wiki='.$tag.'/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = false;
                $baseUrl = $matches[1];
            } elseif (preg_match('/(.*)\/[^\/]*wiki='.$tag.'/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = true;
                $baseUrl = $matches[1];
            }
        } elseif (preg_match('/(.*)\/wakka.php\?('.WN_CAMEL_CASE_EVOLVED.')/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = false;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        } elseif (preg_match('/(.*)\/\?('.WN_CAMEL_CASE_EVOLVED.')/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = false;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        } elseif (preg_match('/(https?:\/\/(?:localhost|[0-9]{3}:[0-9]{3}:[0-9]{3}:[0-9]{3}|(?:[^\/]*\.[a-z]{3})).*)\/('.WN_CAMEL_CASE_EVOLVED.')(?:\/)?$/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = true;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        }
        if (empty($baseUrl) || is_null($rewriteModeEnabled) || empty($tag)) {
            return [];
        } else {
            return [$baseUrl,$rewriteModeEnabled,$tag];
        }
    }

    /**
     * retrieve url after redirection
     * @param string $inputUrl
     * @return string $outputUrl
     */
    private function retrieveUrlAfterRedirect(string $inputUrl): string
    {
        $headers = get_headers($inputUrl, true);
        $outputUrl = $inputUrl;
        if (!empty($headers['Location'])) {
            if (is_array($headers['Location'])) {
                $outputUrl = $headers['Location'][count($headers['Location'])-1];
            } elseif (is_string($headers['Location'])) {
                $outputUrl = $headers['Location'];
            }
        }
        return $outputUrl;
    }

    /**
     * extract existing images from pages
     * @param array $page
     * @param string $baseUrl
     * @param bool $rewriteModeEnabled
     * @param string $foldername
     */
    private function extractPageImages(array $page, string $baseUrl, bool $rewriteModeEnabled, string $foldername)
    {
        if (!is_dir($foldername)) {
            return null;
        }
        // download file
        if (preg_match_all('/{{(?:attach|section)[\s]+[^}]*file=\\"([^"]+)?\\"[^}]*}/m', $page['body'], $matches)) {
            foreach ($matches[0] as $id => $match) {
                $fileName = trim($matches[1][$id] ?? '');
                if (preg_match('/(.+)\.([^\.]+)$/u', $fileName, $matches)) {
                    $name = $matches[1];
                    $ext = $matches[2];
                }
                if (empty($ext)) {
                    $newFileName = $page['tag'].'_'.$fileName.'_19880101000000_23001231235959_';
                } elseif (in_array($ext, ['jpg','jpeg','png','bmp'])) {
                    $newFileName = $page['tag'].'_'.$name.'_19880101000000_23001231235959.'.$ext;
                } else {
                    $newFileName = $page['tag'].'_'.$name.'_19880101000000_23001231235959.'.$ext.'_';
                }
                if (!empty($fileName)) {
                    $fileUrl = $baseUrl.'/'.($rewriteModeEnabled ? '' : '?').$page['tag'].'/download'.($rewriteModeEnabled ? '?' : '&').'file='.$fileName;
                    $fileContent = file_get_contents($fileUrl);
                    if (strlen($fileContent) > 0) {
                        if (!is_dir($foldername.'/files')) {
                            mkdir($foldername.'/files');
                        }
                        if (is_dir($foldername.'/files')) {
                            file_put_contents($foldername.'/files/'.$newFileName, $fileContent);
                        }
                    }
                }
            }
        }
    }
    /**
     * extract existing images from entries
     * @param array $page
     * @param string $baseUrl
     * @param bool $rewriteModeEnabled
     * @param string $foldername
     */
    private function extractEntryImages(array $entry, string $baseUrl, bool $rewriteModeEnabled, string $foldername)
    {
        if (!is_dir($foldername)) {
            return null;
        }
        // download file
        $filesToImport = [];
        foreach (['bf_image','imagebf_image','bf_file','filebf_file'] as $key) {
            if (!empty($entry[$key])) {
                $filesToImport[] = $entry[$key];
            }
        }
        foreach ($filesToImport as $file) {
            $fileUrl = $baseUrl.'/files/'.$file;
            $fileContent = file_get_contents($fileUrl);
            if (strlen($fileContent) > 0) {
                if (!is_dir($foldername.'/files')) {
                    mkdir($foldername.'/files');
                }
                if (is_dir($foldername.'/files')) {
                    file_put_contents($foldername.'/files/'.$file, $fileContent);
                }
            }
        }
    }
}
