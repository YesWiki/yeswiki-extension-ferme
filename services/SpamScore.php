<?php

namespace YesWiki\Ferme\Service;

/** How much a wiki looks like one of the spam creations a public farm collects: no signal is trusted alone. */
class SpamScore
{
    public const THRESHOLD = 3;

    public const REASONS = ['script', 'words', 'domain', 'url', 'language', 'untouched'];

    private const SCRIPT = '/[ạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹđ\x{0400}-\x{04ff}\x{0600}-\x{06ff}\x{0900}-\x{097f}\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u';
    private const URL = '#https?://|www\.#i';
    private const WORDS = '/\b(bet|bets|betting|casino|slot|slots|jackpot|poker|clb|escort|escorts|call ?girl|viagra|cialis|crypto|bitcoin|forex|essay|essays|homework|assignment|dissertation|seo|backlink|gambling|lottery|toto|togel|judi)\b/i';

    /** The same trade written as one word, as a folder slug does it. */
    private const GLUED = '/(keonhacai|nhacai|taixiu|soikeo|bongda|cacuoc|sunwin|xoso|lodeonline|sattamatka|escortgirl|callgirl|camgirl|adultfriend|freespins|modapk|apkmod|betting|casino|jackpot|\d+bet|bet\d+|\d+win|win\d+|\d+clb|clb\d+|hi88|w88|vn88|shbet|fun88|688clb|33win|77bet|8xbet|188bet|i9bet|five88|bdgwin)/i';

    /** A folder that is a domain name with the dots taken out. */
    private const DOMAINISH = '/(com|net|org|info|xyz|top|vip|club|live|bid|site|space|one|ceo|moe|life|shop|store|online|link|asia|casa|fun|icu)$/i';

    private const STOPWORDS = [
        'fr' => '/\b(de|des|du|la|le|les|un|une|et|pour|sur|dans|avec|nos|notre|vos|votre|aux|par|est|ce|cette)\b/i',
        'en' => '/\b(the|of|and|for|your|you|with|best|how|are|this|our|will|can|top|guide|service|services|online|free|about)\b/i',
    ];

    public function __construct(private readonly FarmConfig $config)
    {
    }

    public function threshold(): int
    {
        return max(1, (int)$this->config->text('yeswiki-farm-spam-threshold', (string)self::THRESHOLD));
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array{score: int, reasons: list<string>}
     */
    public function of(string $name, string $description, array $stats, string $folder = ''): array
    {
        $text = trim(mb_strtolower($name . ' ' . $description));
        $slug = mb_strtolower($folder . ' ' . $name);
        $score = 0;
        $reasons = [];

        if (preg_match(self::SCRIPT, $text . ' ' . $slug) === 1) {
            $score += 3;
            $reasons[] = 'script';
        }
        if ($text !== '' && (preg_match(self::WORDS, $text) === 1 || $this->matchesFarmWords($text))) {
            $score += 3;
            $reasons[] = 'words';
        }
        if (preg_match(self::GLUED, $slug) === 1 && !in_array('words', $reasons, true)) {
            $score += 3;
            $reasons[] = 'words';
        }
        if ($folder !== '' && $this->looksLikeADomain($folder, $name)) {
            $score += 3;
            $reasons[] = 'domain';
        }
        if ($text !== '' && preg_match(self::URL, $text) === 1) {
            $score++;
            $reasons[] = 'url';
        }
        if ($this->readsForeign($text)) {
            $score += 2;
            $reasons[] = 'language';
        }
        if ($this->neverGrew($stats)) {
            $score++;
            $reasons[] = 'untouched';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /** The folder reads as a domain, and the wiki was given no name of its own. */
    private function looksLikeADomain(string $folder, string $name): bool
    {
        if (preg_match('/\d/', $folder) !== 1 || preg_match(self::DOMAINISH, $folder) !== 1) {
            return false;
        }

        $slug = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $folder));
        $titled = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));

        return $titled === '' || $titled === $slug;
    }

    /** Words a farm adds to the list, as a regular expression fragment. */
    private function matchesFarmWords(string $text): bool
    {
        $extra = $this->config->text('yeswiki-farm-spam-words');

        return $extra !== '' && @preg_match('/' . $extra . '/i', $text) === 1;
    }

    /** English where the farm speaks something else. */
    private function readsForeign(string $text): bool
    {
        $language = strtolower(substr($this->config->text('default_language', 'fr'), 0, 2));
        if ($text === '' || $language === 'en' || !isset(self::STOPWORDS[$language])) {
            return false;
        }

        return preg_match_all(self::STOPWORDS['en'], $text) > preg_match_all(self::STOPWORDS[$language], $text);
    }

    /**
     * Still exactly what a new wiki holds, and nobody but its creator.
     *
     * @param array<string, mixed> $stats
     */
    private function neverGrew(array $stats): bool
    {
        if ($stats === []) {
            return false;
        }

        return (int)($stats['entries'] ?? 0) <= 9
            && (int)($stats['users'] ?? 0) <= 1
            && (int)($stats['pages'] ?? 0) < 200;
    }
}
