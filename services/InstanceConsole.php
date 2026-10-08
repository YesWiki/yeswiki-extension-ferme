<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use YesWiki\Kernel\Command\CreateInstanceCommand;
use YesWiki\Kernel\Service\ConsoleService;
use YesWiki\Kernel\Service\EnvironmentConfiguration;

/** Runs the console of another Instance of the farm, the way its own `yeswicli` would. */
class InstanceConsole
{
    public const TIMEOUT = 1800;
    public const MIGRATING = 'YESWIKI_FERME_MIGRATING';
    public const TASK_SCRIPT = 'bin/instance-task.php';
    private const SECRETS = ['DB_ADMIN_USER', 'DB_ADMIN_PASSWORD', 'S3_ADMIN_KEY', 'S3_ADMIN_SECRET', 'YESWIKI_USER_PASSWORD', 'REMOTE_ADMIN_PASSWORD', 'YESWIKI_INSTANCE_DIR'];

    /** Make the Instance folder with core's own `core:create-instance`. */
    public function createInstance(string $wikiDir): void
    {
        $output = new BufferedOutput();
        $status = (new CreateInstanceCommand())->run(new ArrayInput(['path' => $wikiDir]), $output);
        if ($status !== 0) {
            throw new \RuntimeException(trim($output->fetch()));
        }
    }

    /** Take away an Instance folder once core has dropped what it owned, as `yeswiki destroy` does after `core:destroy`. */
    public function removeInstance(string $wikiDir): void
    {
        $wikiDir = rtrim($wikiDir, '/');
        $program = \defined('YESWIKI_PROGRAM_DIR') ? rtrim(YESWIKI_PROGRAM_DIR, '/') : '';
        $instance = \defined('YESWIKI_INSTANCE_DIR') ? rtrim(YESWIKI_INSTANCE_DIR, '/') : '';
        $real = realpath($wikiDir);
        if ($wikiDir === '' || $real === false || in_array($real, array_filter([$program, $instance, realpath($program), realpath($instance)]), true) || $real === '/') {
            throw new \RuntimeException(_t('FERME_CLI_MASTER_EXCLUDED') . ' ' . $wikiDir);
        }
        (new Filesystem())->remove($real);
    }

    /**
     * Run a console command inside the Instance.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $env       variables for this run only, a password among them
     *
     * @return array{status: int, output: string}
     */
    public function run(string $wikiDir, array $arguments, array $env = [], int $timeout = self::TIMEOUT): array
    {
        return $this->process($wikiDir, [ConsoleService::console(), ...$arguments, '--no-interaction', '--no-ansi'], $env, $timeout);
    }

    /**
     * The same, refusing a run that failed with what it printed.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    public function mustRun(string $wikiDir, array $arguments, array $env = [], int $timeout = self::TIMEOUT): string
    {
        $result = $this->run($wikiDir, $arguments, $env, $timeout);
        if ($result['status'] !== 0) {
            throw new \RuntimeException(($arguments[0] ?? '') . ': ' . ($result['output'] !== '' ? $result['output'] : 'exit ' . $result['status']));
        }

        return $result['output'];
    }

    /**
     * Run one of the farm's tasks inside the Instance, with that Instance's own services.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function task(string $wikiDir, string $task, array $arguments = []): array
    {
        $script = \dirname(__DIR__) . '/' . self::TASK_SCRIPT;
        $result = $this->process($wikiDir, [$script, $task], [], self::TIMEOUT, (string)json_encode($arguments));
        $lines = preg_split('/\R/', trim($result['output'])) ?: [];
        $decoded = json_decode((string)end($lines), true);
        if ($result['status'] !== 0 || !is_array($decoded)) {
            throw new \RuntimeException($task . ': ' . ($result['output'] !== '' ? $result['output'] : 'exit ' . $result['status']));
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new \RuntimeException($task . ': ' . $decoded['error']);
        }

        return $decoded;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     *
     * @return array{status: int, output: string}
     */
    private function process(string $wikiDir, array $arguments, array $env, int $timeout, ?string $input = null): array
    {
        $wikiDir = rtrim($wikiDir, '/');
        $process = new Process([self::php(), ...$arguments], $wikiDir, $this->environment($wikiDir, $env), $input);
        $process->setTimeout($timeout > 0 ? $timeout : null);
        $process->run();

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

        return ['status' => (int)$process->getExitCode(), 'output' => (string)preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $output)];
    }

    /**
     * The farm's own environment must not reach another wiki: a DB_DATABASE meant for the farm would become the other wiki's database.
     *
     * @param array<string, string> $env
     *
     * @return array<string, string|false>
     */
    private function environment(string $wikiDir, array $env): array
    {
        $clean = [];
        foreach ([...array_keys(EnvironmentConfiguration::knownEnvNames()), ...EnvironmentConfiguration::NOT_CONFIG, ...self::SECRETS] as $name) {
            $clean[$name] = false;
        }

        return array_merge($clean, [
            'YESWIKI_INSTANCE_DIR' => $wikiDir,
            'YESWIKI_CONFIG_FILE' => FarmConfig::configFileIn($wikiDir),
            'YESWIKI_PROGRAM_DIR' => \defined('YESWIKI_PROGRAM_DIR') ? YESWIKI_PROGRAM_DIR : $wikiDir,
            self::MIGRATING => '1',
        ], $env);
    }

    /** The PHP command line of the running version: PHP_BINARY under the CLI, the same version's php beside it under fpm. */
    public static function php(string $sapi = PHP_SAPI): string
    {
        $configured = getenv('ASYNC_PHP_BINARY');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if ($sapi === 'cli') {
            return PHP_BINARY;
        }
        $sameVersion = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (is_executable($sameVersion)) {
            return $sameVersion;
        }
        $found = (new PhpExecutableFinder())->find(false);

        return is_string($found) && $found !== '' && !str_contains(basename($found), 'fpm') ? $found : 'php';
    }
}
