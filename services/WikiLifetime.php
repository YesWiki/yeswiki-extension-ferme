<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\PageManager;
use YesWiki\Wiki;

/** How long a farm wiki lives: its kind, its deadline, its renewal, kept on its bazar entry. */
class WikiLifetime
{
    public const SHORT = 'short';
    public const LONG = 'long';
    public const PERMANENT = 'permanent';

    public const KIND = 'ferme_lifetime';
    public const RENEWED_AT = 'ferme_renewed_at';
    public const EXPIRES_AT = 'ferme_expires_at';
    public const REMINDED = 'ferme_reminded';
    public const ARCHIVED_AT = 'ferme_archived_at';
    public const ARCHIVE = 'ferme_archive';
    public const KEYS = [self::KIND, self::RENEWED_AT, self::EXPIRES_AT, self::REMINDED, self::ARCHIVED_AT, self::ARCHIVE];

    public const SHORT_DAYS = 90;
    public const LONG_DAYS = 365;
    public const GRACE_DAYS = 180;
    public const REMINDERS = [30, 7];
    public const KEY_FILE = 'private/ferme-lifetime.key';

    private $wiki;
    private $entryManager;
    private $pageManager;

    public function __construct(Wiki $wiki, EntryManager $entryManager, PageManager $pageManager)
    {
        $this->wiki = $wiki;
        $this->entryManager = $entryManager;
        $this->pageManager = $pageManager;
    }

    public function isEnabled(): bool
    {
        return filter_var($this->wiki->config['yeswiki-farm-lifetime'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function days(string $kind): int
    {
        $key = $kind === self::SHORT ? 'yeswiki-farm-lifetime-short' : 'yeswiki-farm-lifetime-long';
        $default = $kind === self::SHORT ? self::SHORT_DAYS : self::LONG_DAYS;

        return max(1, (int)($this->wiki->config[$key] ?? $default));
    }

    public function graceDays(): int
    {
        return max(0, (int)($this->wiki->config['yeswiki-farm-lifetime-grace'] ?? self::GRACE_DAYS));
    }

    public function reminders(): array
    {
        $days = $this->wiki->config['yeswiki-farm-lifetime-reminders'] ?? self::REMINDERS;
        $days = is_array($days) ? $days : explode(',', (string)$days);
        $days = array_values(array_unique(array_filter(array_map('intval', $days), function (int $day) {
            return $day > 0;
        })));
        rsort($days);

        return $days === [] ? self::REMINDERS : $days;
    }

    public function renewWindow(): int
    {
        return $this->reminders()[0];
    }

    public function choices(bool $isAdmin): array
    {
        return $isAdmin ? [self::SHORT, self::LONG, self::PERMANENT] : [self::SHORT, self::LONG];
    }

    public function start(string $kind, \DateTimeImmutable $today): array
    {
        if ($kind === self::PERMANENT) {
            return [self::KIND => self::PERMANENT, self::RENEWED_AT => $today->format('Y-m-d')];
        }

        return [
            self::KIND => $kind,
            self::RENEWED_AT => $today->format('Y-m-d'),
            self::EXPIRES_AT => $today->modify('+' . $this->days($kind) . ' days')->format('Y-m-d'),
            self::REMINDED => '',
        ];
    }

    public function describe(array $entry, \DateTimeImmutable $today): ?array
    {
        $kind = (string)($entry[self::KIND] ?? '');
        if (!in_array($kind, [self::SHORT, self::LONG, self::PERMANENT], true)) {
            return null;
        }

        $expires = $this->date($entry[self::EXPIRES_AT] ?? null);
        $archived = $this->date($entry[self::ARCHIVED_AT] ?? null);
        $daysLeft = $expires === null ? null : (int)$today->diff($expires)->format('%r%a');

        return [
            'kind' => $kind,
            'renewedAt' => (string)($entry[self::RENEWED_AT] ?? ''),
            'expiresAt' => $expires === null ? '' : $expires->format('Y-m-d'),
            'daysLeft' => $daysLeft,
            'archived' => $archived !== null,
            'archivedAt' => $archived === null ? '' : $archived->format('Y-m-d'),
            'archive' => (string)($entry[self::ARCHIVE] ?? ''),
            'purgeAt' => $archived === null ? '' : $archived->modify('+' . $this->graceDays() . ' days')->format('Y-m-d'),
            'expiring' => $archived === null && $daysLeft !== null && $daysLeft <= $this->renewWindow(),
            'canRenew' => $archived === null && $daysLeft !== null && $daysLeft <= $this->renewWindow(),
        ];
    }

    public function renewed(array $entry, \DateTimeImmutable $today, ?string $kind = null): array
    {
        $kind = $kind ?? (string)($entry[self::KIND] ?? self::LONG);
        if ($kind === self::PERMANENT) {
            return [self::KIND => self::PERMANENT, self::RENEWED_AT => $today->format('Y-m-d'), self::EXPIRES_AT => '', self::REMINDED => ''];
        }

        $expires = $this->date($entry[self::EXPIRES_AT] ?? null);
        $from = $expires !== null && $expires > $today && $kind === ($entry[self::KIND] ?? null) ? $expires : $today;

        return [
            self::KIND => $kind,
            self::RENEWED_AT => $today->format('Y-m-d'),
            self::EXPIRES_AT => $from->modify('+' . $this->days($kind) . ' days')->format('Y-m-d'),
            self::REMINDED => '',
        ];
    }

    /** Renew an entry from its owner's side: refused on an archived, permanent or not yet due wiki. */
    public function renew(string $idFiche, \DateTimeImmutable $today): array
    {
        $entry = $this->entry($idFiche);
        $state = $this->describe($entry, $today);
        if ($state === null || $state['kind'] === self::PERMANENT || $state['archived']) {
            throw new \RuntimeException(_t('FERME_LIFETIME_CANNOT_RENEW'));
        }
        if (!$state['canRenew']) {
            throw new \RuntimeException(_t('FERME_LIFETIME_TOO_EARLY', ['date' => $this->date($state['expiresAt'])?->format('d/m/Y') ?? '']));
        }

        return $this->write($idFiche, $this->renewed($entry, $today));
    }

    /** Set an entry's kind, or renew it without waiting, from the farm's admin side. */
    public function change(string $idFiche, string $kind, \DateTimeImmutable $today): array
    {
        $entry = $this->entry($idFiche);
        if (!empty($entry[self::ARCHIVED_AT])) {
            throw new \RuntimeException(_t('FERME_LIFETIME_CANNOT_RENEW'));
        }
        if (!in_array($kind, [self::SHORT, self::LONG, self::PERMANENT], true)) {
            throw new \InvalidArgumentException(_t('FERME_LIFETIME_INVALID') . ' "' . $kind . '"');
        }

        return $this->write($idFiche, $this->renewed($entry, $today, $kind));
    }

    public function write(string $idFiche, array $changes): array
    {
        $entry = array_merge($this->entry($idFiche), $changes);
        foreach ($entry as $key => $value) {
            if (in_array($key, self::KEYS, true) && ($value === '' || $value === null) && $key !== self::REMINDED) {
                unset($entry[$key]);
            }
        }
        unset($entry['html_data'], $entry['url'], $entry['semantic'], $entry['user'], $entry['owner']);
        $this->pageManager->save($idFiche, json_encode($entry), '', true);

        return $entry;
    }

    public function token(string $idFiche, string $expiresAt): string
    {
        return hash_hmac('sha256', $idFiche . '|' . $expiresAt, $this->secret());
    }

    public function tokenIsValid(array $entry, string $token): bool
    {
        $expires = (string)($entry[self::EXPIRES_AT] ?? '');

        return $expires !== '' && $token !== '' && hash_equals($this->token((string)$entry['id_fiche'], $expires), $token);
    }

    public function renewUrl(array $entry): string
    {
        $idFiche = (string)($entry['id_fiche'] ?? '');

        return $this->wiki->href('', 'api/ferme/lifetime/renew', [
            'id' => $idFiche,
            'token' => $this->token($idFiche, (string)($entry[self::EXPIRES_AT] ?? '')),
        ], false);
    }

    public function label(string $kind): string
    {
        return _t('FERME_LIFETIME_' . strtoupper($kind));
    }

    public function entry(string $idFiche): array
    {
        $entry = $this->entryManager->isEntry($idFiche) ? $this->entryManager->getOne($idFiche, false, null, false, true) : null;
        if (empty($entry)) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ENTRY') . ' ' . $idFiche);
        }

        return $entry;
    }

    private function date($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function secret(): string
    {
        $secret = @file_get_contents(self::KEY_FILE);
        if (is_string($secret) && strlen($secret) >= 32) {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        if (!is_dir(dirname(self::KEY_FILE))) {
            @mkdir(dirname(self::KEY_FILE), 0700, true);
        }
        if (@file_put_contents(self::KEY_FILE, $secret, LOCK_EX) === false) {
            throw new \RuntimeException(_t('FERME_CLI_CANNOT_CREATE_DIR') . ' ' . self::KEY_FILE);
        }
        @chmod(self::KEY_FILE, 0600);

        return $secret;
    }
}
