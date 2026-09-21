<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * How much a wiki looks like one of the spam creations a public farm collects.
 *
 * The weights were calibrated on a farm of 3 735 wikis: at three points and above,
 * 520 were flagged and reading them showed two that were not spam. No single signal
 * is trusted on its own, and being unused is never enough, since plenty of honest
 * wikis are created and left alone.
 */
class SpamScore
{
    public const THRESHOLD = 3;

    public const REASONS = ['script', 'words', 'domain', 'url', 'language', 'untouched'];

    private const SCRIPT = '/[ạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹđ\x{0400}-\x{04ff}\x{0600}-\x{06ff}\x{0900}-\x{097f}\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u';
    private const URL = '#https?://|www\.#i';
    private const WORDS = '/\b(bet|bets|betting|casino|slot|slots|jackpot|poker|clb|escort|escorts|call ?girl|viagra|cialis|crypto|bitcoin|forex|essay|essays|homework|assignment|dissertation|seo|backlink|gambling|lottery|toto|togel|judi)\b/i';

    /**
     * The same trade, written as one word: a folder is a slug, and `\b` never sees
     * the gambling in `keonhacai5csacom`. Only tokens long enough not to turn up
     * inside an honest name belong here.
     */
    private const GLUED = '/(keonhacai|nhacai|taixiu|soikeo|bongda|cacuoc|sunwin|xoso|lodeonline|sattamatka|escortgirl|callgirl|camgirl|adultfriend|freespins|modapk|apkmod|betting|casino|jackpot|\d+bet|bet\d+|\d+win|win\d+|\d+clb|clb\d+|hi88|w88|vn88|shbet|fun88|688clb|33win|77bet|8xbet|188bet|i9bet|five88|bdgwin)/i';

    /**
     * A folder that is a domain name with the dots taken out, named the same as the
     * wiki: `keonhacai5csacom`, `go88trcom`, `rikvip2info`. Someone naming a real
     * project types a title of their own, which is what keeps this apart from an
     * honest slug.
     */
    private const DOMAINISH = '/(com|net|org|info|xyz|top|vip|club|live|bid|site|space|one|ceo|moe|life|shop|store|online|link|asia|casa|fun|icu)$/i';

    private const STOPWORDS = [
        'fr' => '/\b(de|des|du|la|le|les|un|une|et|pour|sur|dans|avec|nos|notre|vos|votre|aux|par|est|ce|cette)\b/i',
        'en' => '/\b(the|of|and|for|your|you|with|best|how|are|this|our|will|can|top|guide|service|services|online|free|about)\b/i',
    ];

    private $wiki;

    public function __construct(Wiki $wiki)
    {
        $this->wiki = $wiki;
    }

    public function threshold(): int
    {
        return max(1, (int)($this->wiki->config['yeswiki-farm-spam-threshold'] ?? self::THRESHOLD));
    }

    /**
     * @param array<string,mixed> $stats what WikiStats measured
     *
     * @return array{score:int,reasons:array<int,string>}
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
        if ($slug !== '' && preg_match(self::GLUED, $slug) === 1 && !in_array('words', $reasons, true)) {
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

    /**
     * The folder reads as a domain, and the wiki was given no name of its own.
     */
    private function looksLikeADomain(string $folder, string $name): bool
    {
        if (preg_match('/\d/', $folder) !== 1 || preg_match(self::DOMAINISH, $folder) !== 1) {
            return false;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $folder));
        $titled = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));

        return $titled === '' || $titled === $slug;
    }

    /**
     * Words a farm adds to the list, as a regular expression fragment.
     */
    private function matchesFarmWords(string $text): bool
    {
        $extra = trim((string)($this->wiki->config['yeswiki-farm-spam-words'] ?? ''));

        return $extra !== '' && @preg_match('/' . $extra . '/i', $text) === 1;
    }

    /**
     * English where the farm speaks something else. A farm that is itself English
     * has nothing to compare against, so the signal stands down.
     */
    private function readsForeign(string $text): bool
    {
        $language = strtolower(substr((string)($this->wiki->config['default_language'] ?? 'fr'), 0, 2));
        if ($text === '' || $language === 'en' || !isset(self::STOPWORDS[$language])) {
            return false;
        }

        return preg_match_all(self::STOPWORDS['en'], $text) > preg_match_all(self::STOPWORDS[$language], $text);
    }

    /**
     * Still exactly what the model gives a new wiki, and nobody but its creator.
     *
     * @param array<string,mixed> $stats
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
