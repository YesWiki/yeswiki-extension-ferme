<?php

namespace YesWiki\Ferme\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** What the ferme:* commands share: how wikis are picked and how a run is summed up. Kept out of commands/, which the console instantiates file by file. */
abstract class FarmCommand extends Command
{
    protected FarmConfig $farmConfig;
    protected WikiFinder $finder;

    public function __construct(protected ContainerInterface $services)
    {
        parent::__construct();
        $this->farmConfig = $services->get(FarmConfig::class);
        $this->finder = $services->get(WikiFinder::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->services->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException($class);
        }

        return $service;
    }

    protected function addWikiSelectionOptions(): static
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_PATH'))
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_DEPTH'), '1')
            ->addOption('wiki', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_WIKI'));

        return $this;
    }

    protected function addDryRunOption(): static
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_DRY_RUN'));

        return $this;
    }

    /** @return list<array{PATH: string, FOLDER: string, URL: string, VERSION: string, RELEASE: string}> */
    protected function selectWikis(InputInterface $input): array
    {
        $folder = (string)$input->getOption('wiki');
        if ($folder !== '') {
            return [$this->finder->findOne($folder)];
        }
        $path = (string)$input->getOption('path');

        return $this->finder->find($path === '' ? null : $path, max(0, (int)$input->getOption('depth')));
    }

    /** A duration as an operator writes it: 90, 30s, 15m, 8h, 7d. */
    protected function seconds(string $duration): int
    {
        if (preg_match('/^(\d+)([smhd]?)$/', trim($duration), $matches) !== 1) {
            throw new \InvalidArgumentException(_t('FERME_CLI_BAD_DURATION') . ' ' . $duration);
        }
        $units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

        return (int)$matches[1] * $units[$matches[2]];
    }

    protected function elapsed(float $started): string
    {
        $seconds = (int)round(microtime(true) - $started);
        $minutes = intdiv($seconds, 60);

        return ($minutes > 0 ? $minutes . 'm ' : '') . ($seconds % 60) . 's';
    }

    /** @param array{PATH: string, URL: string} $wiki */
    protected function label(array $wiki): string
    {
        return $wiki['URL'] !== 'KO' ? $wiki['URL'] : $wiki['PATH'];
    }

    protected function isDryRun(InputInterface $input): bool
    {
        return (bool)$input->getOption('dry-run');
    }

    protected function dryRunPrefix(InputInterface $input): string
    {
        return $this->isDryRun($input) ? '[' . _t('FERME_CLI_DRY_RUN') . '] ' : '';
    }

    protected function warnNothingFound(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('<comment>' . _t('FERME_CLI_NO_WIKI_FOUND') . ' ' . ((string)$input->getOption('path') !== '' ? (string)$input->getOption('path') : $this->farmConfig->rootFolder())
            . ' (' . _t('FERME_CLI_OPT_DEPTH') . ': ' . (int)$input->getOption('depth') . ')</comment>');
    }

    /**
     * The closing block of every command: counters, then the wikis that failed.
     *
     * @param array<string, int|string> $counters
     * @param list<string>              $failed
     */
    protected function renderSummary(OutputInterface $output, string $title, array $counters, array $failed = [], bool $dryRun = false): int
    {
        $width = 0;
        foreach (array_keys($counters) as $name) {
            $width = max($width, mb_strlen($name));
        }
        $output->writeln('');
        $output->writeln('<info>=== ' . $title . ($dryRun ? ' (' . _t('FERME_CLI_DRY_RUN_NOTHING_WRITTEN') . ')' : '') . ' ===</info>');
        foreach ($counters as $name => $value) {
            $output->writeln('  ' . $name . str_repeat(' ', $width + 2 - mb_strlen($name)) . $value);
        }
        if ($failed === []) {
            return Command::SUCCESS;
        }
        $output->writeln('<error>' . _t('FERME_CLI_FAILED_WIKIS') . '</error>');
        foreach ($failed as $label) {
            $output->writeln('<error>  - ' . $label . '</error>');
        }

        return Command::FAILURE;
    }
}
