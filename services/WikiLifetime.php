<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Service\PageManager;
use YesWiki\Files\Service\Storage;
use YesWiki\Kernel\Service\UrlFormatter;

/** How long a farm wiki lives: its kind, its deadline and its renewal, kept on the entry that describes it. */
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
    public const KEY_FILE = 'private/keys/ferme-lifetime.key';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly PageManager $pageManager,
        private readonly UrlFormatter $urlFormatter,
        private readonly Storage $storage
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->flag('yeswiki-farm-lifetime');
    }

    public function days(string $kind): int
    {
        return $kind === self::SHORT
            ? max(1, (int)$this->config->text('yeswiki-farm-lifetime-short', (string)self::SHORT_DAYS))
            : max(1, (int)$this->config->text('yeswiki-farm-lifetime-long', (string)self::LONG_DAYS));
    }

    public function graceDays(): int
    {
        return max(0, (int)$this->config->text('yeswiki-farm-lifetime-grace', (string)self::GRACE_DAYS));
    }

    /** @return list<int> days before the deadline a reminder goes out, furthest first */
    public function reminders(): array
    {
        $days = $this->config->setting('yeswiki-farm-lifetime-reminders', self::REMINDERS);
        $days = is_array($days) ? $days : explode(',', is_scalar($days) ? (string)$days : '');
        $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn (int $day): bool => $day > 0)));
        rsort($days);

        return $days === [] ? self::REMINDERS : $days;
    }

    public function renewWindow(): int
    {
        return $this->reminders()[0];
    }

    /** @return list<string> */
    public function choices(bool $isAdmin): array
    {
        return $isAdmin ? [self::SHORT, self::LONG, self::PERMANENT] : [self::SHORT, self::LONG];
    }

    /** @return array<string, string> */
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

    /**
     * Where an entry stands in its lifetime, or null when it has none.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{kind: string, renewedAt: string, expiresAt: string, daysLeft: ?int, archived: bool, archivedAt: string, archive: string, purgeAt: string, expiring: bool, canRenew: bool}|null
     */
    public function describe(array $entry, \DateTimeImmutable $today): ?array
    {
        $kind = (string)($entry[self::KIND] ?? '');
        if (!in_array($kind, [self::SHORT, self::LONG, self::PERMANENT], true)) {
            return null;
        }
        $expires = $this->date($entry[self::EXPIRES_AT] ?? null);
        $archived = $this->date($entry[self::ARCHIVED_AT] ?? null);
        $daysLeft = $expires === null ? null : (int)$today->diff($expires)->format('%r%a');
        $expiring = $archived === null && $daysLeft !== null && $daysLeft <= $this->renewWindow();

        return [
            'kind' => $kind,
            'renewedAt' => (string)($entry[self::RENEWED_AT] ?? ''),
            'expiresAt' => $expires === null ? '' : $expires->format('Y-m-d'),
            'daysLeft' => $daysLeft,
            'archived' => $archived !== null,
            'archivedAt' => $archived === null ? '' : $archived->format('Y-m-d'),
            'archive' => (string)($entry[self::ARCHIVE] ?? ''),
            'purgeAt' => $archived === null ? '' : $archived->modify('+' . $this->graceDays() . ' days')->format('Y-m-d'),
            'expiring' => $expiring,
            'canRenew' => $expiring,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, string>
     */
    public function renewed(array $entry, \DateTimeImmutable $today, ?string $kind = null): array
    {
        $kind ??= (string)($entry[self::KIND] ?? self::LONG);
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

    /**
     * Renew an entry from its owner's side: refused on an archived, permanent or not yet due wiki.
     *
     * @return array<string, mixed>
     */
    public function renew(string $tag, \DateTimeImmutable $today): array
    {
        $entry = $this->entry($tag);
        $state = $this->describe($entry, $today);
        if ($state === null || $state['kind'] === self::PERMANENT || $state['archived']) {
            throw new \RuntimeException(_t('FERME_LIFETIME_CANNOT_RENEW'));
        }
        if (!$state['canRenew']) {
            throw new \RuntimeException(_t('FERME_LIFETIME_TOO_EARLY', ['date' => $this->date($state['expiresAt'])?->format('d/m/Y') ?? '']));
        }

        return $this->write($tag, $this->renewed($entry, $today));
    }

    /**
     * Set an entry's kind, or renew it at once, from the farm's admin side.
     *
     * @return array<string, mixed>
     */
    public function change(string $tag, string $kind, \DateTimeImmutable $today): array
    {
        $entry = $this->entry($tag);
        if (!empty($entry[self::ARCHIVED_AT])) {
            throw new \RuntimeException(_t('FERME_LIFETIME_CANNOT_RENEW'));
        }
        if (!in_array($kind, [self::SHORT, self::LONG, self::PERMANENT], true)) {
            throw new \InvalidArgumentException(_t('FERME_LIFETIME_INVALID') . ' "' . $kind . '"');
        }

        return $this->write($tag, $this->renewed($entry, $today, $kind));
    }

    /**
     * Write lifetime keys on the entry, which are no field of the form and so go straight into its body.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, mixed>
     */
    public function write(string $tag, array $changes): array
    {
        $entry = array_merge($this->entry($tag), $changes);
        foreach (self::KEYS as $key) {
            if ($key !== self::REMINDED && array_key_exists($key, $entry) && ($entry[$key] === '' || $entry[$key] === null)) {
                unset($entry[$key]);
            }
        }
        $entry['updated_at'] = date('Y-m-d H:i:s');
        $this->pageManager->save($tag, $entry, '', true);

        return $entry;
    }

    public function token(string $tag, string $expiresAt): string
    {
        return hash_hmac('sha256', $tag . '|' . $expiresAt, $this->secret());
    }

    /** @param array<string, mixed> $entry */
    public function tokenIsValid(array $entry, string $token): bool
    {
        $expires = (string)($entry[self::EXPIRES_AT] ?? '');

        return $expires !== '' && $token !== '' && hash_equals($this->token((string)($entry['tag'] ?? ''), $expires), $token);
    }

    /** @param array<string, mixed> $entry */
    public function renewUrl(array $entry): string
    {
        $tag = (string)($entry['tag'] ?? '');

        return $this->urlFormatter->href('', 'api/ferme/lifetime/renew', ['id' => $tag, 'token' => $this->token($tag, (string)($entry[self::EXPIRES_AT] ?? ''))], false);
    }

    public function label(string $kind): string
    {
        return _t('FERME_LIFETIME_' . strtoupper($kind));
    }

    /**
     * The entry describing a wiki, as stored.
     *
     * @return array<string, mixed>
     */
    public function entry(string $tag): array
    {
        $page = $this->pageManager->getOne($tag, null, false, true);
        $body = is_array($page['body'] ?? null) ? $page['body'] : [];
        if ($page === null || ($page['type'] ?? '') !== PageType::ENTRY || (string)($body['form_id'] ?? '') !== $this->config->farmId()) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ENTRY') . ' ' . $tag);
        }

        return $body + ['tag' => $tag];
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function secret(): string
    {
        if ($this->storage->fileExists(self::KEY_FILE)) {
            $secret = $this->storage->read(self::KEY_FILE);
            if (strlen($secret) >= 32) {
                return $secret;
            }
        }
        $secret = bin2hex(random_bytes(32));
        $this->storage->write(self::KEY_FILE, $secret);

        return $secret;
    }
}
