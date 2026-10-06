<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Service\EntryManager;
use YesWiki\Files\Service\RuntimeLock;
use YesWiki\Files\Service\Storage;

/** Walks the farm's wikis with a lifetime: sends reminders, deletes, archives and purges what is due. */
class LifetimeSweeper
{
    public const LOCK = 'cache/ferme-lifetime.lock';
    public const LAST_RUN = 'cache/ferme-lifetime-last.lock';
    public const EVERY = 43200;
    public const EXPIRED_FOLDER = 'expired';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly EntryManager $entryManager,
        private readonly WikiLifetime $lifetime,
        private readonly FarmMailer $mailer,
        private readonly WikiRemover $remover,
        private readonly RuntimeLock $locks,
        private readonly Storage $storage
    ) {
    }

    /**
     * Sweep unless another sweep ran in the last twelve hours or is running now.
     *
     * @return array<string, list<string>>|null
     */
    public function sweepIfDue(int $limit = 0): ?array
    {
        if (!$this->lifetime->isEnabled()) {
            return null;
        }

        return $this->locked(true, fn (): array => $this->sweep(new \DateTimeImmutable('today'), false, $limit));
    }

    /** @return array<string, list<string>>|null null when another sweep is running */
    public function sweepNow(\DateTimeImmutable $today, int $limit = 0): ?array
    {
        return $this->locked(false, fn (): array => $this->sweep($today, false, $limit));
    }

    /** @return array<string, list<string>> */
    public function sweep(\DateTimeImmutable $today, bool $dryRun = false, int $limit = 0): array
    {
        $report = ['reminded' => [], 'deleted' => [], 'archived' => [], 'purged' => [], 'failed' => []];
        $heavy = 0;

        foreach ($this->entries() as $entry) {
            $state = $this->lifetime->describe($entry, $today);
            if ($state === null || $state['kind'] === WikiLifetime::PERMANENT) {
                continue;
            }
            $tag = (string)($entry['tag'] ?? '');
            $folder = (string)($entry['bf_dossier-wiki'] ?? '');
            $name = $folder !== '' ? $folder : $tag;

            try {
                if ($state['archived']) {
                    if ($state['purgeAt'] !== '' && $state['purgeAt'] <= $today->format('Y-m-d')) {
                        if (!$dryRun) {
                            $this->purge($tag, $state['archive']);
                        }
                        $report['purged'][] = $name;
                    }
                    continue;
                }
                if ($state['daysLeft'] === null) {
                    continue;
                }
                if ($state['daysLeft'] <= 0) {
                    if ($limit > 0 && $heavy >= $limit) {
                        continue;
                    }
                    $heavy++;
                    if (!$dryRun) {
                        $state['kind'] === WikiLifetime::SHORT ? $this->remover->expire($tag, $folder) : $this->archive($entry, $folder, $today);
                    }
                    $report[$state['kind'] === WikiLifetime::SHORT ? 'deleted' : 'archived'][] = $name;
                    continue;
                }
                $due = $this->dueReminders($entry, $state['daysLeft']);
                if ($due !== []) {
                    if (!$dryRun) {
                        $this->remind($entry, $state, $due);
                    }
                    $report['reminded'][] = $name;
                }
            } catch (\Throwable $throwable) {
                $report['failed'][] = $name . ' : ' . $throwable->getMessage();
            }
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<int>
     */
    public function dueReminders(array $entry, int $daysLeft): array
    {
        $sent = array_map('intval', array_filter(explode(',', (string)($entry[WikiLifetime::REMINDED] ?? ''))));

        return array_values(array_filter($this->lifetime->reminders(), static fn (int $day): bool => $daysLeft <= $day && !in_array($day, $sent, true)));
    }

    /**
     * @param callable(): array<string, list<string>> $work
     *
     * @return array<string, list<string>>|null
     */
    private function locked(bool $onlyIfDue, callable $work): ?array
    {
        $handle = $this->locks->acquire(self::LOCK);
        if (!$this->locks->tryLock($handle)) {
            return null;
        }

        try {
            $last = $this->locks->lastTaken(self::LAST_RUN);
            if ($onlyIfDue && $last > 0 && time() - $last < self::EVERY) {
                return null;
            }
            $report = $work();
            $this->locks->stamp(self::LAST_RUN);

            return $report;
        } finally {
            $this->locks->release($handle);
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $state
     * @param list<int>            $due
     */
    private function remind(array $entry, array $state, array $due): void
    {
        $this->mailer->send($entry, $this->template('yeswiki-farm-lifetime-mail-subject', 'FERME_LIFETIME_MAIL_SUBJECT'), $this->template('yeswiki-farm-lifetime-mail-body', 'FERME_LIFETIME_MAIL_BODY'), [
            'lifetime' => $this->lifetime->label((string)$state['kind']),
            'expires' => WelcomeMailer::day((string)$state['expiresAt']),
            'daysLeft' => (string)$state['daysLeft'],
            'renewUrl' => $this->lifetime->renewUrl($entry),
            'donateUrl' => $this->config->text('yeswiki-farm-donate-url'),
        ]);
        $sent = array_filter(explode(',', (string)($entry[WikiLifetime::REMINDED] ?? '')));
        $this->lifetime->write((string)$entry['tag'], [
            WikiLifetime::REMINDED => implode(',', array_unique(array_merge($sent, array_map('strval', $due)))),
        ]);
    }

    /** @param array<string, mixed> $entry */
    private function archive(array $entry, string $folder, \DateTimeImmutable $today): void
    {
        $tag = (string)$entry['tag'];
        $kept = ($folder !== '' && $this->config->hasWiki($folder)) ? (string)$this->remover->archiveAndRemove($folder, self::EXPIRED_FOLDER) : '';
        $this->lifetime->write($tag, [WikiLifetime::ARCHIVED_AT => $today->format('Y-m-d'), WikiLifetime::ARCHIVE => $kept]);

        try {
            $this->mailer->send($entry, $this->template('yeswiki-farm-lifetime-archived-subject', 'FERME_LIFETIME_ARCHIVED_SUBJECT'), $this->template('yeswiki-farm-lifetime-archived-body', 'FERME_LIFETIME_ARCHIVED_BODY'), [
                'purgeAt' => WelcomeMailer::day($today->modify('+' . $this->lifetime->graceDays() . ' days')->format('Y-m-d')),
                'donateUrl' => $this->config->text('yeswiki-farm-donate-url'),
            ]);
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
    }

    private function purge(string $tag, string $archive): void
    {
        if ($archive !== '' && str_starts_with($archive, $this->config->dataPath(self::EXPIRED_FOLDER . '/')) && $this->storage->fileExists($archive)) {
            $this->storage->delete($archive);
        }
        $this->remover->forgetEntry($tag);
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return array_values(array_filter($this->entryManager->search(['formsIds' => [$this->config->farmId()]]), static fn (array $entry): bool => !empty($entry[WikiLifetime::KIND])));
    }

    private function template(string $key, string $default): string
    {
        $text = $this->config->text($key);

        return $text !== '' ? str_replace('\\n', "\n", $text) : _t($default);
    }
}
