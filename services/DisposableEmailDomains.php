<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Mail domains that hand out throwaway addresses: the public list shipped in
 * data/ (github.com/disposable-email-domains, CC0) and the farm's own additions.
 */
class DisposableEmailDomains
{
    public const FILE = __DIR__ . '/../data/disposable-email-domains.txt';

    private $wiki;
    private $domains;

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
    }

    public function isDisposable(string $email): bool
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($email, $at + 1)));

        return $domain !== '' && isset($this->domains()[$domain]);
    }

    /** How many of these addresses are throwaway ones. */
    public function countIn(array $emails): int
    {
        return count(array_filter($emails, fn ($email) => $this->isDisposable((string)$email)));
    }

    /** @return array<string,true> */
    private function domains(): array
    {
        if ($this->domains === null) {
            $listed = is_readable(self::FILE) ? (file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            $added = preg_split('/[\s,|]+/', (string)($this->wiki->config['yeswiki-farm-spam-email-domains'] ?? '')) ?: [];
            $this->domains = array_fill_keys(array_filter(array_map(fn ($domain) => strtolower(trim($domain)), array_merge($listed, $added))), true);
        }

        return $this->domains;
    }
}
