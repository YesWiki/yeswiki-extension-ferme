<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\WikiAsleepException;

/** Puts a wiki to sleep or wakes it up, by the `wiki_status` core's HibernationService reads from its configuration. */
class WikiHibernator
{
    public const RUNNING = 'running';
    public const HIBERNATE = 'hibernate';
    public const ASLEEP = ['hibernate', 'archiving', 'updating'];
    public const BUSY = ['archiving', 'updating'];

    public function __construct(
        private readonly FarmConfig $config,
        private readonly WikiConfigEditor $editor,
        private readonly FolderLock $lock
    ) {
    }

    /** The statuses core's HibernationService answers true for. */
    public static function isAsleep(string $status): bool
    {
        return in_array(trim($status), self::ASLEEP, true);
    }

    public static function label(string $status): string
    {
        $status = trim($status);
        if ($status === '' || $status === self::RUNNING) {
            return _t('FERME_STATUS_RUNNING');
        }

        return $status === self::HIBERNATE ? _t('FERME_STATUS_HIBERNATE') : _t('FERME_STATUS_BUSY') . ' (' . $status . ')';
    }

    public function statusOf(string $folder): string
    {
        $status = $this->config->readWikiConfig($folder)['wiki_status'] ?? '';

        return is_string($status) ? trim($status) : '';
    }

    public function refuseIfAsleep(string $folder): void
    {
        if (self::isAsleep($this->statusOf($folder))) {
            throw new WikiAsleepException($folder);
        }
    }

    /** @return array{changed: bool, status: string, before: string} */
    public function hibernate(string $folder): array
    {
        return $this->set($folder, self::HIBERNATE);
    }

    /** @return array{changed: bool, status: string, before: string} */
    public function wake(string $folder): array
    {
        return $this->set($folder, self::RUNNING);
    }

    /**
     * Run some work on a wiki that may be asleep, woken for the time it takes.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function whileAwake(string $folder, callable $work): mixed
    {
        $woken = $this->statusOf($folder) === self::HIBERNATE && $this->wake($folder)['changed'];

        try {
            return $work();
        } finally {
            if ($woken) {
                $this->hibernate($folder);
            }
        }
    }

    /** @return array{changed: bool, status: string, before: string} */
    private function set(string $folder, string $status): array
    {
        if (!$this->config->hasWiki($folder)) {
            throw new \RuntimeException(_t('FERME_NO_WIKI_IN') . ' ' . $folder);
        }
        $dir = $this->config->wikiDir($folder);

        return $this->lock->during($dir, _t('FERME_LOCK_STATUS'), function () use ($dir, $folder, $status): array {
            $before = $this->statusOf($folder);
            $before = $before === '' ? self::RUNNING : $before;
            if ($before === $status) {
                return ['changed' => false, 'status' => $status, 'before' => $before];
            }
            $this->editor->patch($dir, ['wiki_status' => $status]);
            $this->config->forgetWikiConfig($folder);

            return ['changed' => true, 'status' => $status, 'before' => $before];
        });
    }
}
