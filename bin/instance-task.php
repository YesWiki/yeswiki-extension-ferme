<?php

/** Runs one of the farm's tasks inside the Instance it is started in, booted like that Instance's own console. */

use YesWiki\Core\YesWikiLoader;
use YesWiki\Ferme\Task\InstanceTasks;
use YesWiki\Kernel\Service\ConfigurationFileProvider;
use YesWiki\Kernel\Service\ConfigurationService;
use YesWiki\Kernel\Service\EnvironmentConfiguration;

$programDir = getenv('YESWIKI_PROGRAM_DIR');
require_once (is_string($programDir) && $programDir !== '' ? $programDir : dirname(__DIR__, 3)) . '/src/bootstrap_paths.php';
require_once YESWIKI_PROGRAM_DIR . '/src/autoload.inc.php';
require_once YESWIKI_PROGRAM_DIR . '/vendor/autoload.php';
require_once YESWIKI_PROGRAM_DIR . '/src/YesWikiLoader.php';
require_once __DIR__ . '/InstanceTasks.php';

YesWikiLoader::loadEnv();

try {
    $file = (new ConfigurationService())->getConfiguration(ConfigurationFileProvider::getConfigFileFromEnv());
    $file->load();
    $parameters = $file->__get('_parameters');
    $yeswikiConfig = EnvironmentConfiguration::apply(is_array($parameters) ? $parameters : []);
    if (empty($yeswikiConfig['base_url'])) {
        throw new RuntimeException('no wiki is configured in ' . YESWIKI_INSTANCE_DIR);
    }

    $_SERVER['REQUEST_URI'] = $yeswikiConfig['base_url'] . ($yeswikiConfig['root_page'] ?? '');
    $_SERVER['HTTP_HOST'] = parse_url((string)$yeswikiConfig['base_url'], PHP_URL_HOST);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['wiki'] = $yeswikiConfig['root_page'] ?? '';

    $arguments = json_decode((string)stream_get_contents(STDIN), true);
    $result = (new InstanceTasks(YesWikiLoader::getWiki()->services))->run((string)($argv[1] ?? ''), is_array($arguments) ? $arguments : []);
} catch (Throwable $failed) {
    $result = ['error' => $failed->getMessage()];
}

echo "\n" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
