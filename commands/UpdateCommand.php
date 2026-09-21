<?php

namespace YesWiki\Ferme\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use YesWiki\Ferme\Service\AbstractFarmCommand;
use YesWiki\Ferme\Service\CustomAside;
use YesWiki\Ferme\Service\WikiUpdater;
use YesWiki\Wiki;

class UpdateCommand extends AbstractFarmCommand
{
    private const CONSOLE = 'includes/commands/console';
    private const DEFAULT_VERSIONS = ['doryphore'];
    private const CERCO_VERSIONS = ['cercopitheque', 'cercopitheque_dev'];
    private const DEV_VERSIONS = ['doryphore-dev', 'doryphore_dev'];

    protected $updater;
    protected $aside;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct($wiki);
        $this->updater = $wiki->services->get(WikiUpdater::class);
        $this->aside = $wiki->services->get(CustomAside::class);
    }

    protected function configure()
    {
        $this
            ->setName('ferme:update')
            ->setDescription(_t('FERME_CLI_UPDATE_DESCRIPTION'))
            ->setHelp(_t('FERME_CLI_UPDATE_HELP'))
            ->addOption('archive-url', 'a', InputOption::VALUE_OPTIONAL, _t('FERME_CLI_OPT_ARCHIVE_URL'), false)
            ->addOption('source-dir', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_SOURCE_DIR'))
            ->addOption('from-parent', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_FROM_PARENT'))
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_WORKERS'), 4)
            ->addOption('force', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_FORCE'))
            ->addOption('nobackup', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_NOBACKUP_UPDATE'))
            ->addOption('stop-on-error', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_STOP_ON_ERROR'))
            ->addOption('ignore-extensions', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_IGNORE_EXTENSIONS'))
            ->addOption('recover-only', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_RECOVER_ONLY'))
            ->addOption('migratecerco', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_MIGRATECERCO'))
            ->addOption('migratedev', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_MIGRATEDEV'))
            ->addWikiSelectionOptions()
            ->addDryRunOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $started = microtime(true);
        $temporarySource = null;

        if ($input->getOption('recover-only')) {
            return $this->recoverOnly($input, $output, $started);
        }

        try {
            [$sourceDir, $temporarySource] = $this->resolveSource($input, $output);
            [$version, $release] = $this->updater->sourceVersion($sourceDir);
        } catch (\Throwable $th) {
            $output->writeln('<error>' . $th->getMessage() . '</error>');

            return Command::FAILURE;
        }

        try {
            $wikis = $this->selectWikis($input);
            if (empty($wikis)) {
                $this->warnNothingFound($input, $output);

                return Command::SUCCESS;
            }

            $worker = (bool)$input->getOption('from-parent');
            if (!$worker) {
                $output->writeln(_t('FERME_CLI_SOURCE_IS') . ' ' . $sourceDir . ' (' . $version . ' ' . $release . ')');
            }

            $this->sweepAsides($input, $output, $wikis);

            [$todo, $wrongVersion, $upToDate] = $this->triage($input, $output, $wikis, $version, $release);

            $workers = max(1, (int)$input->getOption('workers'));
            $result = (1 === $workers || count($todo) <= 1)
                ? $this->runHere($input, $output, $todo, $sourceDir)
                : $this->runInParallel($input, $output, $todo, $sourceDir, $workers);

            // the parent prints its own summary over all the wikis
            if ($worker) {
                return empty($result['failed']) ? Command::SUCCESS : Command::FAILURE;
            }

            return $this->renderSummary(
                $output,
                _t('FERME_CLI_UPDATE_SUMMARY'),
                [
                    _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                    _t('FERME_CLI_SKIPPED_VERSION') => $wrongVersion,
                    _t('FERME_CLI_SKIPPED_UP_TO_DATE') => $upToDate,
                    _t('FERME_CLI_UPDATED') => $result['updated'],
                    _t('FERME_CLI_FAILED') => count($result['failed']),
                    _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
                ],
                $result['failed'],
                $this->isDryRun($input)
            );
        } finally {
            if ($temporarySource !== null) {
                $this->wiki->services->get(\YesWiki\Ferme\Service\FileSystem::class)->remove($temporarySource);
            }
        }
    }

    /**
     * Put back the custom/ folders left aside by interrupted runs, and stop there.
     */
    private function recoverOnly(InputInterface $input, OutputInterface $output, float $started): int
    {
        $wikis = $this->selectWikis($input);
        if (empty($wikis)) {
            $this->warnNothingFound($input, $output);

            return Command::SUCCESS;
        }

        [$recovered, $failed] = $this->sweepAsides($input, $output, $wikis);

        return $this->renderSummary(
            $output,
            _t('FERME_CLI_RECOVER_SUMMARY'),
            [
                _t('FERME_CLI_WIKIS_FOUND') => count($wikis),
                _t('FERME_CLI_CUSTOM_RECOVERED_COUNT') => $recovered,
                _t('FERME_CLI_FAILED') => count($failed),
                _t('FERME_CLI_ELAPSED') => $this->elapsed($started),
            ],
            $failed,
            $this->isDryRun($input)
        );
    }

    /**
     * @return array{0:int,1:array<int,string>}
     */
    private function sweepAsides(InputInterface $input, OutputInterface $output, array $wikis): array
    {
        $recovered = 0;
        $failed = [];

        foreach ($wikis as $wiki) {
            if (!$this->aside->isAside($wiki['PATH'])) {
                continue;
            }

            $label = $this->label($wiki);
            if ($this->isDryRun($input)) {
                $output->writeln($this->dryRunPrefix($input) . $label . ': ' . _t('FERME_CLI_WOULD_RECOVER_CUSTOM'));
                $recovered++;
                continue;
            }

            try {
                $output->writeln('  <info>' . $label . '</info>: ' . $this->aside->recover($wiki['PATH']));
                $recovered++;
            } catch (\Throwable $th) {
                $failed[] = $label;
                $output->writeln('<error>  ' . $label . ': ' . $th->getMessage() . '</error>');
            }
        }

        return [$recovered, $failed];
    }

    /**
     * Where the new files come from: the farm master, or a release unpacked from
     * a zip. Workers are handed the folder the parent already prepared, so a
     * release is downloaded once however many of them there are.
     *
     * @return array{0:string,1:?string} the source folder, and it again when it is ours to delete
     */
    private function resolveSource(InputInterface $input, OutputInterface $output): array
    {
        $given = $input->getOption('source-dir');
        if (!empty($given)) {
            return [rtrim($given, DIRECTORY_SEPARATOR), null];
        }

        $archive = $input->getOption('archive-url');
        if ($archive === false) {
            return [$this->finder->masterRoot(), null];
        }

        $url = is_string($archive) && $archive !== '' ? $archive : $this->farmConfig->archiveUrl();
        if ($url === '') {
            throw new \RuntimeException(_t('FERME_CLI_NO_ARCHIVE_URL'));
        }

        $output->writeln(_t('FERME_CLI_DOWNLOADING') . ' ' . $url);
        $base = $this->downloadArchive($url);

        // the wiki sits inside the unpacked folder, the folder itself is ours to delete
        return [$this->archiveRoot($base . DIRECTORY_SEPARATOR . 'source'), $base];
    }

    private function downloadArchive(string $url): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ferme-source-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $base);
        }

        $zipFile = $base . DIRECTORY_SEPARATOR . 'yeswiki.zip';
        $handle = fopen($zipFile, 'w');
        $client = HttpClient::create();
        $response = $client->request('GET', $url);
        foreach ($client->stream($response) as $chunk) {
            fwrite($handle, $chunk->getContent());
        }
        fclose($handle);

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException(_t('FERME_CLI_BAD_ARCHIVE') . ' ' . $url);
        }
        $zip->extractTo($base . DIRECTORY_SEPARATOR . 'source');
        $zip->close();
        unlink($zipFile);

        return $base;
    }

    /**
     * Release zips hold a single folder named after the version; the wiki is inside it.
     */
    private function archiveRoot(string $target): string
    {
        $entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($target . DIRECTORY_SEPARATOR . $entries[0])) {
            return $target . DIRECTORY_SEPARATOR . $entries[0];
        }

        return $target;
    }

    /**
     * Sort the wikis into the ones to update and the ones to leave alone.
     *
     * @return array{0:array,1:int,2:int}
     */
    private function triage(InputInterface $input, OutputInterface $output, array $wikis, string $version, string $release): array
    {
        $wanted = self::DEFAULT_VERSIONS;
        if ($input->getOption('migratecerco')) {
            $wanted = self::CERCO_VERSIONS;
        } elseif ($input->getOption('migratedev')) {
            $wanted = self::DEV_VERSIONS;
        }

        $force = (bool)$input->getOption('force');
        $todo = [];
        $wrongVersion = 0;
        $upToDate = 0;

        foreach ($wikis as $wiki) {
            if (!in_array(strtolower($wiki['VERSION']), $wanted, true)) {
                $wrongVersion++;
                $output->writeln('  <fg=gray>- ' . $this->label($wiki) . ': ' . _t('FERME_CLI_SKIPPED_VERSION')
                    . ' "' . $wiki['VERSION'] . '"</>');
                continue;
            }
            if (!$force && $wiki['RELEASE'] === $release && strtolower($wiki['VERSION']) === strtolower($version)) {
                $upToDate++;
                $output->writeln('  <fg=gray>- ' . $this->label($wiki) . ': ' . _t('FERME_CLI_SKIPPED_UP_TO_DATE') . '</>');
                continue;
            }
            $todo[] = $wiki;
        }

        return [$todo, $wrongVersion, $upToDate];
    }

    /**
     * @return array{updated:int,failed:array<int,string>}
     */
    private function runHere(InputInterface $input, OutputInterface $output, array $todo, string $sourceDir): array
    {
        $options = [
            'sourceDir' => $sourceDir,
            'backup' => !$input->getOption('nobackup'),
            'dryRun' => $this->isDryRun($input),
            'ignoreExtensions' => (bool)$input->getOption('ignore-extensions'),
        ];

        $updated = 0;
        $failed = [];
        $index = 0;
        $total = count($todo);

        foreach ($todo as $wiki) {
            $index++;
            $label = $this->label($wiki);
            if (!$input->getOption('from-parent')) {
                $output->writeln($this->dryRunPrefix($input) . '<info>' . $label . '</info> ' . $index . '/' . $total);
            }
            try {
                $result = $this->updater->update($wiki['PATH'], $options);
                foreach ($result['messages'] as $message) {
                    $output->writeln('      ' . $message);
                }
                $updated++;
            } catch (\Throwable $th) {
                $failed[] = $label;
                $output->writeln('<error>      ' . $th->getMessage() . '</error>');
                if ($input->getOption('stop-on-error')) {
                    $output->writeln('<error>' . _t('FERME_CLI_STOPPING') . '</error>');
                    break;
                }
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * One process per wiki, a few at a time. Each boots its own wiki and opens its
     * own database connection, which forking a booted wiki could not do safely.
     *
     * @return array{updated:int,failed:array<int,string>}
     */
    private function runInParallel(
        InputInterface $input,
        OutputInterface $output,
        array $todo,
        string $sourceDir,
        int $workers
    ): array {
        $total = count($todo);
        $output->writeln(_t('FERME_CLI_UPGRADING') . ' ' . $total . ' (' . $workers . ' ' . _t('FERME_CLI_WORKERS') . ')');

        $queue = $todo;
        $running = [];
        $updated = 0;
        $failed = [];
        $index = 0;
        $stop = false;

        while (!empty($queue) || !empty($running)) {
            while (!$stop && count($running) < $workers && !empty($queue)) {
                $wiki = array_shift($queue);
                $index++;
                $process = new Process($this->workerCommand($input, $wiki, $sourceDir), $this->finder->masterRoot());
                $process->setTimeout(null);
                $process->start();
                $running[] = ['process' => $process, 'wiki' => $wiki, 'index' => $index];
            }

            usleep(200000);

            foreach ($running as $key => $worker) {
                if ($worker['process']->isRunning()) {
                    continue;
                }
                unset($running[$key]);

                $label = $this->label($worker['wiki']);
                $prefix = $label . ' ' . $worker['index'] . '/' . $total . ': ';
                $body = trim($worker['process']->getOutput() . "\n" . $worker['process']->getErrorOutput());
                foreach (explode("\n", $body) as $line) {
                    if (trim($line) !== '') {
                        $output->writeln($prefix . trim($line));
                    }
                }

                if ($worker['process']->isSuccessful()) {
                    $updated++;
                    continue;
                }

                $failed[] = $label;
                if ($input->getOption('stop-on-error')) {
                    $stop = true;
                    $queue = [];
                    $output->writeln('<error>' . _t('FERME_CLI_DRAINING') . '</error>');
                }
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * @return array<int,string>
     */
    private function workerCommand(InputInterface $input, array $wiki, string $sourceDir): array
    {
        // the parent already decided this wiki needs it, so the worker does not triage again
        $command = [
            PHP_BINARY,
            self::CONSOLE,
            'ferme:update',
            '--path=' . $wiki['PATH'],
            '--depth=0',
            '--source-dir=' . $sourceDir,
            '--workers=1',
            '--force',
            '--from-parent',
            '--no-ansi',
        ];
        if ($input->getOption('nobackup')) {
            $command[] = '--nobackup';
        }
        if ($input->getOption('ignore-extensions')) {
            $command[] = '--ignore-extensions';
        }
        if ($this->isDryRun($input)) {
            $command[] = '--dry-run';
        }

        return $command;
    }
}
