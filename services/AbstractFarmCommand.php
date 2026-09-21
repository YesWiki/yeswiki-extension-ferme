<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YesWiki\Wiki;

/**
 * What the ferme:* commands share: how a wiki is selected, how a run is summed up.
 *
 * It lives in services/ rather than commands/ because includes/commands/console
 * instantiates every file it finds in commands/, and a base class cannot be
 * instantiated. Being abstract, Symfony's service autoregistration skips it too.
 */
abstract class AbstractFarmCommand extends Command
{
    protected $wiki;
    protected $farmConfig;
    protected $finder;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct();
        $this->wiki = $wiki;
        $this->farmConfig = $wiki->services->get(FarmConfig::class);
        $this->finder = $wiki->services->get(WikiFinder::class);
    }

    protected function addWikiSelectionOptions(): self
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_PATH'))
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_DEPTH'), 1)
            ->addOption('wiki', null, InputOption::VALUE_REQUIRED, _t('FERME_CLI_OPT_WIKI'));

        return $this;
    }

    protected function addDryRunOption(): self
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, _t('FERME_CLI_OPT_DRY_RUN'));

        return $this;
    }

    /**
     * @return array<int,array> the wikis to act on, as WikiFinder describes them
     */
    protected function selectWikis(InputInterface $input): array
    {
        $folder = (string)$input->getOption('wiki');
        if ($folder !== '') {
            return [$this->finder->findOne($folder)];
        }

        $path = (string)$input->getOption('path');

        return $this->finder->find($path === '' ? null : $path, max(0, (int)$input->getOption('depth')));
    }

    protected function elapsed(float $started): string
    {
        $seconds = (int)round(microtime(true) - $started);
        $minutes = intdiv($seconds, 60);

        return ($minutes > 0 ? $minutes . 'm ' : '') . ($seconds % 60) . 's';
    }

    protected function label(array $wiki): string
    {
        return ($wiki['URL'] ?? 'KO') !== 'KO' ? $wiki['URL'] : $wiki['PATH'];
    }

    protected function isDryRun(InputInterface $input): bool
    {
        return (bool)$input->getOption('dry-run');
    }

    protected function dryRunPrefix(InputInterface $input): string
    {
        return $this->isDryRun($input) ? '[' . _t('FERME_CLI_DRY_RUN') . '] ' : '';
    }

    /**
     * Tell the user nothing was found, the way all the commands do.
     */
    protected function warnNothingFound(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('<comment>' . _t('FERME_CLI_NO_WIKI_FOUND') . ' '
            . ($input->getOption('path') ?: $this->farmConfig->basePath())
            . ' (' . _t('FERME_CLI_OPT_DEPTH') . ': ' . (int)$input->getOption('depth') . ')</comment>');
    }

    /**
     * Closing block of every command: counters, then the wikis that failed.
     *
     * @param array<string,int|string> $counters
     * @param array<int,string>        $failed
     *
     * @return int Command::SUCCESS, or Command::FAILURE when something failed
     */
    protected function renderSummary(
        OutputInterface $output,
        string $title,
        array $counters,
        array $failed = [],
        bool $dryRun = false
    ): int {
        $width = 0;
        foreach (array_keys($counters) as $name) {
            $width = max($width, mb_strlen($name));
        }

        $output->writeln('');
        $output->writeln('<info>=== ' . $title . ($dryRun ? ' (' . _t('FERME_CLI_DRY_RUN_NOTHING_WRITTEN') . ')' : '') . ' ===</info>');
        foreach ($counters as $name => $value) {
            // str_pad counts bytes, and these labels are translated
            $output->writeln('  ' . $name . str_repeat(' ', $width + 2 - mb_strlen($name)) . $value);
        }

        if (empty($failed)) {
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . _t('FERME_CLI_FAILED_WIKIS') . '</error>');
        foreach ($failed as $label) {
            $output->writeln('<error>  - ' . $label . '</error>');
        }

        return Command::FAILURE;
    }
}
