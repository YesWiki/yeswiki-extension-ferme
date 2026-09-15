<?php

use Tamtamchik\SimpleFlash\Flash;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\Service\DbService;
use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FarmService;

class GenerateModelAction extends YesWikiAction
{
    protected $dbService;
    private $assetsProgressShown = false;

    public function formatArguments($args)
    {
        return [
            'template' => !empty($args['template']) ? $args['template'] : 'generate-model.twig',
            'wiki-import-forms' => $_POST['wiki-import-forms'] ?? null,
            'model_label' => !empty($_POST['model_label']) ? $_POST['model_label'] : null,
            'source_admin' => [
                'username' => $_POST['source_admin_user'] ?? '',
                'password' => $_POST['source_admin_password'] ?? '',
            ],
            'POST' => array_diff_key($_POST, array_flip(['source_admin_user', 'source_admin_password'])),
            'delete_model' => $_POST['delete_model'] ?? null,
        ];
    }

    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $farm = $this->getService(FarmService::class);
            $this->dbService = $this->getService(DbService::class);

            $farm->initFarmConfig();
            if (!is_null($this->arguments['wiki-import-forms'])) {
                $output .= $this->generateSqlModel($this->arguments['POST']);
            }
            if (!empty($this->arguments['delete_model']) && !$this->tokenIsValid()) {
                $output .= $this->render('@templates/alert-message.twig', [
                    'type' => 'danger',
                    'message' => _t('FERME_INVALID_CSRF'),
                ]);
            } elseif (!empty($this->arguments['delete_model'])) {
                $model = $this->arguments['delete_model'];
                $deleteOutput = $this->deleteModel($model);
                if (in_array($model, $this->wiki->config['yeswiki-farm-models'])) {
                    $yeswikiFarmModels = array_filter(
                        $this->wiki->config['yeswiki-farm-models'],
                        function ($modelInConfig) use ($model) {
                            return $modelInConfig !== $model;
                        }
                    );
                    $dataConfig = [];
                    foreach ($yeswikiFarmModels as $modelName) {
                        $dataConfig[$modelName] = 1;
                    }
                    list($outputTmp, $yeswikiFarmModels) = $this->saveConfig(['config' => $dataConfig]);
                    Flash::info(strip_tags($deleteOutput));
                    Flash::info(strip_tags($outputTmp));
                    $this->wiki->Redirect($this->wiki->Href('', $this->wiki->GetPageTag()));
                }
                $output .= $deleteOutput;
            }

            if (isset($this->arguments['POST']['save_config'])) {
                list($outputTmp, $yeswikiFarmModels) = $this->saveConfig($this->arguments['POST']);
                $output .= $outputTmp;
                if (!empty($this->arguments['POST']['model_labels']) && is_array($this->arguments['POST']['model_labels'])) {
                    $this->saveModelLabels($this->arguments['POST']['model_labels']);
                }
            }

            $modelsFolder = glob(FarmConfig::MODELS_DIR . '/*', GLOB_ONLYDIR);
            $defaultModelIsAvailable = (isset($yeswikiFarmModels) && in_array('default-content', $yeswikiFarmModels))
                || (!isset($yeswikiFarmModels) && in_array('default-content', $this->wiki->config['yeswiki-farm-models']));
            $models = [];
            foreach ($modelsFolder as $modelFolder) {
                if (is_file($modelFolder . '/default-content.sql') && is_file($modelFolder . '/infos.json')) {
                    $model = str_replace(FarmConfig::MODELS_DIR . '/', '', $modelFolder);
                    $json = json_decode(file_get_contents($modelFolder . '/infos.json', true), true);
                    $models[$model]['label'] = $json['label'];
                    $models[$model]['model'] = $model;
                    $models[$model]['url'] = 'https://' . str_replace(['--'], ['/', ''], $model);
                    $models[$model]['isavailable'] = (isset($yeswikiFarmModels) && in_array($model, $yeswikiFarmModels))
                        || (!isset($yeswikiFarmModels) && in_array($model, $this->wiki->config['yeswiki-farm-models']));
                }
            }

            $runningModel = $farm->runningModelAssets();
            if (!is_null($runningModel) && !$this->assetsProgressShown) {
                $output .= $this->renderAssetsProgress($runningModel);
            }

            $output .= $this->render(
                '@ferme/' . $this->arguments['template'],
                [
                    'formurl' => $this->wiki->href('', $this->wiki->GetPageTag()),
                    'models' => $models,
                    'defaultModelIsAvailable' => $defaultModelIsAvailable,
                    'farmRootUrl' => rtrim((string)($this->wiki->config['yeswiki-farm-root-url'] ?? ''), '/'),
                ]
            );
            $this->wiki->AddJavascriptFile('tools/ferme/javascripts/ferme-import.js');
        } else {
            $output .= '<div class="alert alert-danger">'
            . '  <strong>' . _t('TEMPLATE_ACTION') . ' {{generatemodel}}</strong> : '
            . _t('TEMPLATE_ACTION_FOR_ADMINS_ONLY')
            . '</div>' . "\n";
        }

        return $output;
    }

    /** A missing token throws rather than returning false, so both mean the same here. */
    private function tokenIsValid(): bool
    {
        try {
            return $this->getService(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (Throwable $th) {
            return false;
        }
    }

    public function generateSqlModel($data)
    {
        $output = '';
        $infos = [];
        $extraction = $this->extractBaseUrlAndRootPage($data['url-import']);
        if (empty($extraction)) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'warning',
                'message' => _t('FERME_NOT_POSSIBLE_TO_IMPORT_MODEL') . ' : ' . $data['url-import'],
            ]);
        }
        list($baseUrl, $rootPage, $rewriteModeEnabled) = $extraction;

        $model = str_replace(
            ['http://', 'https://', '/'],
            ['', '', '--'],
            $baseUrl
        );
        if (!FarmConfig::isSafeName($model)) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'warning',
                'message' => _t('FERME_INVALID_MODEL_NAME') . ' "' . $model . '"',
            ]);
        }
        $foldername = FarmConfig::MODELS_DIR . '/' . $model;
        if (!is_dir($foldername)) {
            @mkdir($foldername, 0777, true);
        }
        $infos['label'] = !empty($this->arguments['model_label']) ? $this->arguments['model_label'] : $baseUrl;
        $infos['sourceUrl'] = $baseUrl;
        $infos['dateOfCreation'] = date('Y-m-d H:i:s');
        $jsonData = json_encode($infos);
        file_put_contents($foldername . '/infos.json', $jsonData);

        $filename = $foldername . '/default-content.sql';
        $sql = '';

        $pages = json_decode(html_entity_decode($data['wiki-import-pages']), 1);
        if (is_array($pages) && !empty($pages)) {
            $tabpages = [];
            $sql .= '# YesWiki pages' . "\n";
            foreach ($pages as $page) {
                if (!$rewriteModeEnabled) {
                    $page['body'] = str_replace(str_replace('/', '\\/', $baseUrl) . '\\/wakka.php?', '{{url}}', $page['body']);
                    $page['body'] = str_replace(str_replace('/', '\\/', $baseUrl) . '\\/?', '{{url}}', $page['body']);
                }
                $page['body'] = str_replace($rootPage, '{{rootPage}}', $page['body']);
                $tabpages[] = "('" . ($page['tag'] == $rootPage ? '{{rootPage}}' : $page['tag']) . "',  now(), '" . addslashes($page['body'])
                    . "', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
            }
            $sql .= 'INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,'
                        . " `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
                        . implode(',' . "\n", $tabpages) . ";\n";
            $sql .= '# end YesWiki pages' . "\n\n";
        }

        $forms = json_decode(html_entity_decode($data['wiki-import-forms']), 1);
        if (is_array($forms) && !empty($forms)) {
            $sql .= '# Bazar forms' . "\n";
            $tabforms = [];
            $firstForm = reset($forms);
            $validColumns = array_values(array_filter(array_keys($firstForm), function ($key) {
                return strpos($key, 'bn_') === 0;
            }));
            foreach ($forms as $form) {
                $values = array_map(function ($col) use ($form) {
                    $value = $form[$col] ?? '';

                    return "'" . $this->dbService->escape((string)$value) . "'";
                }, $validColumns);
                $tabforms[] = '(' . implode(', ', $values) . ')';
            }
            $sql .= 'INSERT INTO `{{prefix}}nature` ('
                . implode(', ', array_map(function ($col) {
                    return "`$col`";
                }, $validColumns))
                . ")\nVALUES\n" . implode(',' . "\n", $tabforms) . ";\n";
            $sql .= '# end Bazar forms' . "\n\n";
        }

        $lists = json_decode(html_entity_decode($data['wiki-import-lists']), 1);
        if (is_array($lists) && !empty($lists)) {
            $sql .= '# Bazar lists' . "\n";
            $tablists = [];
            $tabliststriple = [];
            foreach ($lists as $id => $list) {
                $json = json_encode($list);
                $tablists[] = "('" . $id . "',  now(), '" . addslashes($json)
                    . "', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                $tabliststriple[] = "('" . $id . "', 'http://outils-reseaux.org/_vocabulary/type', 'liste')";
            }
            $sql .= 'INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,'
                . " `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
                . implode(',' . "\n", $tablists) . ";\n";
            $sql .= "INSERT INTO `{{prefix}}triples` (`resource`, `property`, `value`) VALUES\n"
                . implode(',' . "\n", $tabliststriple) . ";\n";
            $sql .= '# end Bazar lists' . "\n\n";
        }

        $entries = json_decode(html_entity_decode($data['wiki-import-entries']), 1);
        $tabentries = [];
        $tabentriestriple = [];
        if (is_array($entries)) {
            foreach ($entries as $item) {
                $id = $this->dbService->escape((string)($item['id_fiche'] ?? ''));
                if ($id === '') {
                    continue;
                }
                unset($item['valider']);
                unset($item['MAX_FILE_SIZE']);
                unset($item['antispam']);
                unset($item['mot_de_passe_wikini']);
                unset($item['mot_de_passe_repete_wikini']);
                unset($item['html_data']);
                unset($item['url']);
                unset($item['owner']);

                $json = json_encode($item);
                $tabentries[] = "('" . $id . "',  now(), '" . addslashes($json)
                    . "', '', '{{WikiName}}', '{{WikiName}}', 'Y', 'page', '')";
                $tabentriestriple[] = "('" . $id . "', 'http://outils-reseaux.org/_vocabulary/type', 'fiche_bazar')";
            }
        }
        if (!empty($tabentries)) {
            $sql .= '# Bazar entries' . "\n";
            $sql .= 'INSERT INTO `{{prefix}}pages` (`tag`, `time`, `body`, `body_r`,'
                . " `owner`, `user`, `latest`, `handler`, `comment_on`) VALUES\n"
                . implode(',' . "\n", $tabentries) . ";\n";
            $sql .= "INSERT INTO `{{prefix}}triples` (`resource`, `property`, `value`) VALUES\n"
                . implode(',' . "\n", $tabentriestriple) . ";\n";
            $sql .= '# end Bazar entries' . "\n\n";
        }

        if (!file_put_contents($filename, $sql)) {
            $output .= '<div class="alert alert-danger">' .
                   '  <strong>' . _t('TEMPLATE_ACTION') . ' {{generatemodel}}</strong> : '
                   . _t('le fichier ' . $filename . ' n\'a pas pu être créé..') .
                   '</div>' . "\n";
        } else {
            $output .= '<div class="alert alert-success">'
                   . _t('Le fichier <a href="' . $filename . '">' . $filename . '</a> vient d\'être enregistré avec succès.')
                   . '</div>' . "\n";
            $output .= $this->startAssets($model, $baseUrl);
        }

        return $output;
    }

    /** Fetch the files and custom folders of the model source, on this server or another. */
    private function startAssets(string $model, string $baseUrl): string
    {
        set_time_limit(300);

        $farm = $this->getService(FarmService::class);
        try {
            $result = $farm->startModelAssets($model, $baseUrl, $this->arguments['source_admin']);
        } catch (Throwable $th) {
            $output = $this->render('@templates/alert-message.twig', [
                'type' => 'warning',
                'message' => _t('FERME_MODEL_ASSETS_FAILED') . ' ' . htmlspecialchars($th->getMessage()),
            ]);
            $running = $farm->runningModelAssets();

            return is_null($running) ? $output : $output . $this->renderAssetsProgress($running, true);
        }

        if ($result['running']) {
            return $this->renderAssetsProgress($model);
        }

        return $this->render('@templates/alert-message.twig', [
            'type' => 'info',
            'message' => implode('<br />', $result['messages']),
        ]);
    }

    /** The block the javascript polls, and the way out of a fetch a browser walked away from. */
    private function renderAssetsProgress(string $model, bool $cancelOnly = false): string
    {
        $this->assetsProgressShown = true;

        return $this->render('@ferme/model-assets-progress.twig', [
            'model' => $model,
            'cancelOnly' => $cancelOnly,
            'assetsUrl' => $this->wiki->href('', 'api/ferme/models/assets'),
        ]);
    }

    public function deleteModel($model)
    {
        if (!FarmConfig::isSafeName($model)) {
            return '<div class="alert alert-warning">' . _t('FERME_INVALID_MODEL_NAME')
                . ' "' . htmlspecialchars($model) . '"</div>';
        }
        $modelDir = FarmConfig::MODELS_DIR . '/' . $model;
        if (is_dir($modelDir)) {
            $this->rrmdir($modelDir);
            $output = '<div class="alert alert-success">Le modèle "' . $modelDir . '" vient d\'être supprimé.</div>';
        } else {
            $output = '<div class="alert alert-warning">Le modèle "' . $modelDir . '" n\'a pas été trouvé.</div>';
        }

        return $output;
    }

    /** Recursively remove a file or folder. */
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

    /** The root page is where the source wiki sends "/", not the page whose url was pasted. */
    private function extractBaseUrlAndRootPage(string $inputUrl): array
    {
        $extraction = $this->extractBaseUrlModeAndTag($this->retrieveUrlAfterRedirect($inputUrl));
        if (empty($extraction)) {
            return [];
        }
        list($baseUrl, $rewriteModeEnabled, $tag) = $extraction;

        $rootExtraction = $this->extractBaseUrlModeAndTag($this->retrieveUrlAfterRedirect($baseUrl . '/'));
        $rootPage = empty($rootExtraction) ? $tag : $rootExtraction[2];

        return [$baseUrl, $rootPage, $rewriteModeEnabled];
    }

    /** @return array [$baseUrl, $rewriteModeEnabled, $tag] */
    private function extractBaseUrlModeAndTag($inputUrl): array
    {
        if (preg_match('/wiki=(' . WN_CAMEL_CASE_EVOLVED . ')/u', $inputUrl, $matches)) {
            $tag = $matches[1];
            if (preg_match('/(.*)\/wakka.php\?.*wiki=' . $tag . '/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = false;
                $baseUrl = $matches[1];
            } elseif (preg_match('/(.*)\/\?.*wiki=' . $tag . '/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = false;
                $baseUrl = $matches[1];
            } elseif (preg_match('/(.*)\/[^\/]*wiki=' . $tag . '/u', $inputUrl, $matches)) {
                $rewriteModeEnabled = true;
                $baseUrl = $matches[1];
            }
        } elseif (preg_match('/(.*)\/wakka.php\?(' . WN_CAMEL_CASE_EVOLVED . ')/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = false;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        } elseif (preg_match('/(.*)\/\?(' . WN_CAMEL_CASE_EVOLVED . ')/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = false;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        } elseif (preg_match('/(https?:\/\/(?:localhost|[0-9]{3}:[0-9]{3}:[0-9]{3}:[0-9]{3}|(?:[^\/]*\.[a-z]{3})).*)\/(' . WN_CAMEL_CASE_EVOLVED . ')(?:\/)?$/u', $inputUrl, $matches)) {
            $rewriteModeEnabled = true;
            $tag = $matches[2];
            $baseUrl = $matches[1];
        }
        if (empty($baseUrl) || is_null($rewriteModeEnabled) || empty($tag)) {
            return [];
        }

        return [$baseUrl, $rewriteModeEnabled, $tag];
    }

    /** Follow the redirects of an url and return where it lands. */
    private function retrieveUrlAfterRedirect(string $inputUrl): string
    {
        $headers = get_headers($inputUrl, true);
        $outputUrl = $inputUrl;
        if (!empty($headers['Location'])) {
            if (is_array($headers['Location'])) {
                $outputUrl = $headers['Location'][count($headers['Location']) - 1];
            } elseif (is_string($headers['Location'])) {
                $outputUrl = $headers['Location'];
            }
        }

        return $outputUrl;
    }

    /** Write the model labels posted by the admin into each infos.json. */
    private function saveModelLabels(array $labels): void
    {
        foreach ($labels as $model => $label) {
            $label = trim(strip_tags($label));
            if (empty($label) || !FarmConfig::isSafeName((string)$model)) {
                continue;
            }
            $infoFile = FarmConfig::MODELS_DIR . '/' . $model . '/infos.json';
            if (is_file($infoFile)) {
                $infos = json_decode(file_get_contents($infoFile), true) ?? [];
                $infos['label'] = $label;
                file_put_contents($infoFile, json_encode($infos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    private function saveConfig(array $data): array
    {
        $output = '';
        $resetConfig = !isset($data['config'])
            || !is_array($data['config'])
            || empty($data['config'])
            || (count($data['config']) == 1 && isset($data['config']['default-content.sql']) && in_array($data['config']['default-content.sql'], ['on', 1, true, '1']));

        include_once 'tools/templates/libs/Configuration.php';
        $config = new Configuration('wakka.config.php');
        $config->load();
        $key = 'yeswiki-farm-models';
        $models = [];
        if ($resetConfig) {
            if (isset($config->$key)) {
                unset($config->$key);
            }
            $models[] = 'default-content';
            $output = '<div class="alert alert-success">La configuration a été remise à zéro.</div>';
        } else {
            foreach ($data['config'] as $name => $state) {
                if (in_array($state, ['on', 1, true, '1'])) {
                    if ($name == 'default-content.sql') {
                        $models[] = 'default-content';
                    } else {
                        $models[] = $name;
                    }
                }
            }
            $config->$key = $models;
            $output = '<div class="alert alert-success">La configuration a été sauvegardée avec les modèles : \'' . implode("','", $models) . '\'.</div>';
        }
        $config->write();

        return [$output, $models];
    }
}
