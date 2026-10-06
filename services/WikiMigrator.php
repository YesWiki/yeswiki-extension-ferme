<?php

namespace YesWiki\Ferme\Service;

/** Runs each wiki's own `migrate` after the shared Program changed, the one part of an update that is per wiki. */
class WikiMigrator
{
    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceConsole $console,
        private readonly FolderLock $lock,
        private readonly WikiHibernator $hibernator,
        private readonly StatsRefresher $refresher
    ) {
    }

    /**
     * Run `migrate` in a wiki, woken for the time it takes when it sleeps.
     *
     * @return array{status: string, messages: list<string>}
     */
    public function migrate(string $folder, bool $dryRun = false): array
    {
        if (!$this->config->hasWiki($folder)) {
            throw new \RuntimeException(_t('FERME_NO_WIKI_IN') . ' ' . $folder);
        }
        $status = $this->hibernator->statusOf($folder);
        if (in_array($status, WikiHibernator::BUSY, true)) {
            throw new \RuntimeException(_t('FERME_WIKI_BUSY') . ' ' . $folder . ' (' . $status . ')');
        }
        if ($dryRun) {
            return ['status' => 'planned', 'messages' => [_t('FERME_CLI_WOULD_MIGRATE')]];
        }

        $dir = $this->config->wikiDir($folder);
        $output = $this->lock->during($dir, _t('FERME_LOCK_UPDATE'), fn (): string => $this->hibernator->whileAwake(
            $folder,
            fn (): string => $this->console->mustRun($dir, ['migrate'])
        ));
        $this->config->forgetWikiConfig($folder);
        $this->refresher->remeasure($folder, false);

        return ['status' => 'migrated', 'messages' => array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '{"at":')))];
    }
}
