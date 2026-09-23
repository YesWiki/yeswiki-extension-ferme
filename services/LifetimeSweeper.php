<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Wiki;

/** Walks the farm's wikis with a lifetime: sends reminders, deletes, archives and purges what is due. */
class LifetimeSweeper
{
    public const LAST_RUN = 'cache/ferme-lifetime.last';
    public const EVERY = 43200;
    public const EXPIRED_FOLDER = 'expired';

    private $wiki;
    private $entryManager;
    private $lifetime;
    private $mailer;
    private $remover;
    private $archiver;
    private $config;

    public function __construct(
        Wiki $wiki,
        EntryManager $entryManager,
        WikiLifetime $lifetime,
        FarmMailer $mailer,
        WikiRemover $remover,
        WikiArchiver $archiver,
        FarmConfig $config
    ) {
        $this->wiki = $wiki;
        $this->entryManager = $entryManager;
        $this->lifetime = $lifetime;
        $this->mailer = $mailer;
        $this->remover = $remover;
        $this->archiver = $archiver;
        $this->config = $config;
    }

    /** Sweep unless another sweep ran in the last twelve hours or is running now. */
    public function sweepIfDue(int $limit = 0): ?array
    {
        if (!$this->lifetime->isEnabled()) {
            return null;
        }

        return $this->locked(true, function () use ($limit) {
            return $this->sweep(new \DateTimeImmutable('today'), false, $limit);
        });
    }

    /** Sweep now whenever the last one ran, or return null when another sweep is running. */
    public function sweepNow(\DateTimeImmutable $today, int $limit = 0): ?array
    {
        return $this->locked(false, function () use ($today, $limit) {
            return $this->sweep($today, false, $limit);
        });
    }

    /** Run a sweep under the lock every sweep takes, and stamp the time it ran. */
    private function locked(bool $onlyIfDue, callable $work): ?array
    {
        if (!is_dir(dirname(self::LAST_RUN))) {
            @mkdir(dirname(self::LAST_RUN), 0777, true);
        }
        $handle = @fopen(self::LAST_RUN, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            clearstatcache(true, self::LAST_RUN);
            if ($onlyIfDue && (int)filesize(self::LAST_RUN) > 0 && time() - (int)filemtime(self::LAST_RUN) < self::EVERY) {
                return null;
            }
            $report = $work();
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, date('c'));
            fflush($handle);

            return $report;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function sweep(\DateTimeImmutable $today, bool $dryRun = false, int $limit = 0): array
    {
        $report = ['reminded' => [], 'deleted' => [], 'archived' => [], 'purged' => [], 'failed' => []];
        $heavy = 0;

        foreach ($this->entries() as $entry) {
            $state = $this->lifetime->describe($entry, $today);
            if ($state === null || $state['kind'] === WikiLifetime::PERMANENT) {
                continue;
            }
            $idFiche = (string)$entry['id_fiche'];
            $folder = (string)($entry['bf_dossier-wiki'] ?? '');
            $name = $folder !== '' ? $folder : $idFiche;

            try {
                if ($state['archived']) {
                    if ($state['purgeAt'] !== '' && $state['purgeAt'] <= $today->format('Y-m-d')) {
                        if (!$dryRun) {
                            $this->purge($entry, $state);
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
                    if ($state['kind'] === WikiLifetime::SHORT) {
                        if (!$dryRun) {
                            $this->remover->expire($idFiche, $folder);
                        }
                        $report['deleted'][] = $name;
                    } else {
                        if (!$dryRun) {
                            $this->archive($entry, $folder, $today);
                        }
                        $report['archived'][] = $name;
                    }

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

    public function dueReminders(array $entry, int $daysLeft): array
    {
        $sent = array_map('intval', array_filter(explode(',', (string)($entry[WikiLifetime::REMINDED] ?? ''))));

        return array_values(array_filter($this->lifetime->reminders(), function (int $day) use ($daysLeft, $sent) {
            return $daysLeft <= $day && !in_array($day, $sent, true);
        }));
    }

    public function expiredDir(): string
    {
        return $this->config->ensureBackupDir(self::EXPIRED_FOLDER);
    }

    private function remind(array $entry, array $state, array $due): void
    {
        $this->mailer->send($entry, $this->template('yeswiki-farm-lifetime-mail-subject', 'FERME_LIFETIME_MAIL_SUBJECT'), $this->template('yeswiki-farm-lifetime-mail-body', 'FERME_LIFETIME_MAIL_BODY'), [
            'lifetime' => $this->lifetime->label($state['kind']),
            'expires' => $this->day($state['expiresAt']),
            'daysLeft' => (string)$state['daysLeft'],
            'renewUrl' => $this->lifetime->renewUrl($entry),
            'donateUrl' => trim((string)($this->wiki->config['yeswiki-farm-donate-url'] ?? '')),
        ]);

        $sent = array_filter(explode(',', (string)($entry[WikiLifetime::REMINDED] ?? '')));
        $this->lifetime->write((string)$entry['id_fiche'], [
            WikiLifetime::REMINDED => implode(',', array_unique(array_merge($sent, array_map('strval', $due)))),
        ]);
    }

    private function archive(array $entry, string $folder, \DateTimeImmutable $today): void
    {
        $idFiche = (string)$entry['id_fiche'];
        $kept = '';
        if ($folder !== '' && is_dir($this->config->wikiDir($folder))) {
            $made = $this->archiver->create($folder);
            $source = $this->archiver->pathOf($folder, $made['file']);
            $kept = $folder . '-' . $today->format('Ymd') . '.zip';
            if ($source === null || !rename($source, $this->expiredDir() . DIRECTORY_SEPARATOR . $kept)) {
                throw new \RuntimeException(_t('FERME_ARCHIVE_NOT_FOUND'));
            }
            $this->remover->removeWikiOnly($idFiche, $folder);
        }

        $this->lifetime->write($idFiche, [
            WikiLifetime::ARCHIVED_AT => $today->format('Y-m-d'),
            WikiLifetime::ARCHIVE => $kept,
        ]);

        try {
            $this->mailer->send($entry, $this->template('yeswiki-farm-lifetime-archived-subject', 'FERME_LIFETIME_ARCHIVED_SUBJECT'), $this->template('yeswiki-farm-lifetime-archived-body', 'FERME_LIFETIME_ARCHIVED_BODY'), [
                'purgeAt' => $this->day($today->modify('+' . $this->lifetime->graceDays() . ' days')->format('Y-m-d')),
                'donateUrl' => trim((string)($this->wiki->config['yeswiki-farm-donate-url'] ?? '')),
            ]);
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
    }

    private function purge(array $entry, array $state): void
    {
        if ($state['archive'] !== '') {
            $path = $this->expiredDir() . DIRECTORY_SEPARATOR . basename($state['archive']);
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException(_t('FERME_LIFETIME_PURGE_FAILED') . ' ' . $path);
            }
        }
        $this->remover->forgetEntry((string)$entry['id_fiche']);
    }

    private function entries(): array
    {
        $farmId = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');

        return array_values(array_filter($this->entryManager->search(['formsIds' => [$farmId]]), function ($entry) {
            return is_array($entry) && !empty($entry[WikiLifetime::KIND]);
        }));
    }

    /** The text an admin set for a mail in the configuration, or the default one. */
    private function template(string $key, string $default): string
    {
        $text = trim((string)($this->wiki->config[$key] ?? ''));

        return $text !== '' ? str_replace('\\n', "\n", $text) : _t($default);
    }

    private function day(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed ? $parsed->format('d/m/Y') : $date;
    }
}
