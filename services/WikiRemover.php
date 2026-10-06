<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Admin\Command\DestroyCommand;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Files\Service\LocalFiles;
use YesWiki\Files\Service\Storage;
use YesWiki\Identity\Service\AclService;

/** Takes a wiki of the farm away with core's `core:destroy`, and keeps the farm's own books: its entry, its numbers, the channel. */
class WikiRemover
{
    public const DELETED_FOLDER = 'deleted';

    /** @var array<string, true> entries this service is deleting itself, which the deletion listener leaves alone */
    private array $deleting = [];

    private ?DeletionPlan $plan = null;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly InstanceConsole $console,
        private readonly InstanceDatabase $database,
        private readonly EntryManager $entryManager,
        private readonly WikiStatsStore $stats,
        private readonly MattermostNotifier $mattermost,
        private readonly FolderLock $lock,
        private readonly WikiHibernator $hibernator,
        private readonly AclService $aclService,
        private readonly Storage $storage,
        private readonly LocalFiles $localFiles
    ) {
    }

    /**
     * Delete several wikis and their entries.
     *
     * @param list<string> $tags
     *
     * @return list<array<string, mixed>>
     */
    public function deleteMany(array $tags): array
    {
        $results = [];
        foreach ($tags as $tag) {
            $results[] = $this->deleteOne(trim($tag));
        }

        return $results;
    }

    public function isDeleting(string $tag): bool
    {
        return isset($this->deleting[$tag]);
    }

    /**
     * The entry is already gone: take its wiki away too, unless another entry still claims it.
     *
     * @param array<string, mixed> $entry
     */
    public function afterEntryDeleted(array $entry): void
    {
        $tag = (string)($entry['tag'] ?? '');
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        $this->plan()->forget($tag);
        if ($folder === '' || !FarmConfig::isSafeName($folder, true)) {
            return;
        }
        $kept = $this->otherEntriesClaiming($folder, $tag);
        if ($kept === []) {
            $this->destroy($folder, $this->config->flag('yeswiki-farm-archive-on-delete', true) ? self::DELETED_FOLDER : null);
        }
        $this->mattermost->deleted($entry, $folder, $kept !== []);
    }

    /** Delete a wiki whose time ran out, entry and all, with no archive. */
    public function expire(string $tag, string $folder): void
    {
        $entry = $this->entryOf($tag);
        $kept = $folder === '' ? [] : $this->otherEntriesClaiming($folder, $tag);
        if ($folder !== '' && $kept === []) {
            $this->destroy($folder, null);
        }
        $this->deleteEntry($tag);
        $this->mattermost->deleted($entry, $folder, $kept !== []);
    }

    /** Archive a wiki into the farm's backups and take it away, leaving its entry: what a lifetime ending does. */
    public function archiveAndRemove(string $folder, string $into): ?string
    {
        return $this->destroy($folder, $into);
    }

    /** Delete an entry whose wiki is already gone. */
    public function forgetEntry(string $tag): void
    {
        $this->deleteEntry($tag);
    }

    public function isAsleep(string $folder): bool
    {
        return WikiHibernator::isAsleep($this->hibernator->statusOf($folder));
    }

    /** @return list<string> the other farm entries naming that folder */
    public function otherEntriesClaiming(string $folder, string $tag): array
    {
        return $this->plan()->others($folder, $tag);
    }

    /**
     * Take a wiki away with its own `core:destroy`: an archive first when asked, then its database, then its folder.
     *
     * @return string|null where the archive was kept in the farm's backups
     */
    public function destroy(string $folder, ?string $archiveInto): ?string
    {
        $dir = $this->config->wikiDir($folder);

        return $this->lock->during($dir, _t('FERME_LOCK_DELETE'), function () use ($folder, $dir, $archiveInto): ?string {
            $this->stats->forget($folder);
            if (!is_dir($dir)) {
                return null;
            }
            if (!$this->config->hasWiki($folder)) {
                $this->console->removeInstance($dir);

                return null;
            }

            $config = $this->config->readWikiConfig($folder);
            if ($this->isAsleep($folder)) {
                $this->hibernator->wake($folder);
            }
            $ownsDatabase = $this->ownsDatabase($config);
            $temporary = $archiveInto === null ? null : $this->temporaryDirectory();
            $arguments = ['core:destroy', '--confirm=' . DestroyCommand::hostOf((string)($config['base_url'] ?? '')), '--keep-bucket'];
            $arguments[] = $temporary === null ? '--keep-archive' : '--archive-to=' . $temporary;
            $env = [];
            if ($ownsDatabase) {
                $arguments[] = '--db-admin-user=' . $this->config->text('yeswiki-farm-db-admin-user');
                $env['DB_ADMIN_PASSWORD'] = $this->config->text('yeswiki-farm-db-admin-password');
            } else {
                $arguments[] = '--keep-database';
            }

            try {
                $result = $this->console->run($dir, $arguments, $env);
                if ($result['status'] !== 0) {
                    throw new \RuntimeException('core:destroy: ' . $result['output']);
                }
                $kept = $temporary === null ? null : $this->keepArchive($temporary, (string)$archiveInto, $folder);
            } finally {
                if ($temporary !== null) {
                    foreach ($this->localFiles->matching($temporary . '/*') as $left) {
                        $this->localFiles->remove($left);
                    }
                    $this->localFiles->removeEmptyDirectory($temporary);
                }
            }

            if (!$ownsDatabase && (string)($config['db_driver'] ?? 'mysql') !== 'sqlite') {
                $this->database->dropOwnTables($config, $dir);
            }
            $this->database->close();
            $this->console->removeInstance($dir);
            $this->config->forgetWikiConfig($folder);

            return $kept;
        });
    }

    /**
     * Delete one wiki and its entry, for the farm's administrators and the entry's owner.
     *
     * @return array<string, mixed>
     */
    private function deleteOne(string $tag): array
    {
        if (!$this->aclService->isAdmin() && !$this->aclService->isOwner($tag)) {
            return ['tag' => $tag, 'success' => false, 'error' => _t('FERME_UNAUTHORIZED')];
        }
        $entry = $this->entryManager->isEntry($tag) ? $this->entryOf($tag) : [];
        if ($entry === []) {
            return ['tag' => $tag, 'success' => false, 'error' => _t('FERME_MAIL_NO_ENTRY') . ' ' . $tag];
        }
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        if ($folder !== '' && !FarmConfig::isSafeName($folder, true)) {
            return ['tag' => $tag, 'success' => false, 'error' => _t('FERME_INVALID_FOLDER_NAME') . ' "' . $folder . '"'];
        }

        $started = microtime(true);
        $kept = $folder === '' ? [] : $this->otherEntriesClaiming($folder, $tag);
        try {
            if ($folder !== '' && $kept === []) {
                $this->destroy($folder, $this->config->flag('yeswiki-farm-archive-on-delete', true) ? self::DELETED_FOLDER : null);
            }
            $this->deleteEntry($tag);
        } catch (FolderBusyException $busy) {
            return ['tag' => $tag, 'success' => false, 'error' => $busy->getMessage()];
        } catch (\Throwable $failed) {
            return ['tag' => $tag, 'success' => false, 'error' => $failed->getMessage()];
        }
        $this->mattermost->deleted($entry, $folder, $kept !== []);

        return ['tag' => $tag, 'success' => true, 'timings' => ['total' => (int)round((microtime(true) - $started) * 1000)]]
            + ($kept === [] ? [] : ['output' => _t('FERME_WIKI_KEPT_FOR') . ' ' . implode(', ', $kept)]);
    }

    private function deleteEntry(string $tag): void
    {
        if (!$this->entryManager->isEntry($tag)) {
            return;
        }
        $this->deleting[$tag] = true;
        try {
            $this->entryManager->delete($tag, true);
        } finally {
            unset($this->deleting[$tag]);
        }
        $this->plan()->forget($tag);
    }

    /** @return array<string, mixed> */
    private function entryOf(string $tag): array
    {
        return $this->entryManager->getOne($tag, false, null, false, true) ?? [];
    }

    /**
     * Whether the wiki has a database and an account of its own the farm may drop, or shares the farm's under a prefix.
     *
     * @param array<string, mixed> $config
     */
    private function ownsDatabase(array $config): bool
    {
        $driver = (string)($config['db_driver'] ?? 'mysql');
        if ($driver === 'sqlite' || $this->config->text('yeswiki-farm-db-admin-user') === '') {
            return false;
        }
        $user = (string)($config['db_user'] ?? $config['mysql_user'] ?? '');

        return $user !== '' && $user !== $this->config->text('db_user') && $user !== $this->config->text('yeswiki-farm-db-admin-user');
    }

    private function temporaryDirectory(): string
    {
        $file = $this->localFiles->temporaryFile('ferme-destroy-');
        $this->localFiles->remove($file);
        if (!$this->localFiles->makeDirectory($file)) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . $file);
        }

        return $file;
    }

    private function keepArchive(string $temporary, string $into, string $folder): ?string
    {
        foreach ($this->localFiles->matching($temporary . '/*.zip') as $archive) {
            $path = $this->config->dataPath($into . '/' . str_replace('/', '-', $folder) . '-' . date('Ymd-His') . '.zip');
            $this->storage->writeFrom($path, $archive);

            return $path;
        }

        return null;
    }

    private function plan(): DeletionPlan
    {
        if ($this->plan === null) {
            $this->plan = new DeletionPlan();
            $this->plan->index($this->entryManager->search(['formsIds' => [$this->config->farmId()]]));
        }

        return $this->plan;
    }
}
