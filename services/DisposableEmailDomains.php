<?php

namespace YesWiki\Ferme\Service;

/** Mail domains that hand out throwaway addresses: the public list in data/ (github.com/disposable-email-domains, CC0) and the farm's own additions. */
class DisposableEmailDomains
{
    public const FILE = __DIR__ . '/../data/disposable-email-domains.txt';

    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function __construct(private readonly FarmConfig $config)
    {
    }

    public function isDisposable(string $email): bool
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($email, $at + 1)));

        return $domain !== '' && isset($this->domains()[$domain]);
    }

    /**
     * How many of these addresses are throwaway ones.
     *
     * @param list<string> $emails
     */
    public function countIn(array $emails): int
    {
        return count(array_filter($emails, fn (string $email): bool => $this->isDisposable($email)));
    }

    /** @return array<string, true> */
    private function domains(): array
    {
        if ($this->domains === null) {
            $listed = is_readable(self::FILE) ? (file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            $added = preg_split('/[\s,|]+/', $this->config->text('yeswiki-farm-spam-email-domains')) ?: [];
            $this->domains = array_fill_keys(array_values(array_filter(array_map(static fn (string $domain): string => strtolower(trim($domain)), array_merge($listed, $added)))), true);
        }

        return $this->domains;
    }
}
