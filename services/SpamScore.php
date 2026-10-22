<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * How much a wiki looks like one created for spam; no single signal reaches the threshold alone.
 */
class SpamScore
{
    public const THRESHOLD = 3;

    public const REASONS = ['script', 'words', 'domain', 'url', 'language', 'untouched', 'disposable', 'throwaway'];

    private const SCRIPT = '/[ạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹđ\x{0400}-\x{04ff}\x{0600}-\x{06ff}\x{0900}-\x{097f}\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u';
    private const LETTER = '/\p{L}/u';
    private const URL = '#https?://|www\.#i';
    private const OUTSIDE_LINK = '#https?://(?!(?:[a-z0-9-]+\.)*yeswiki\.(?:net|pro)\b)#i';
    private const WORDS = '/\b(bet|bets|betting|casino|slot|slots|jackpot|poker|clb|escort|escorts|call ?girl|viagra|cialis|crypto|bitcoin|forex|essay|essays|homework|assignment|dissertation|seo|backlink|gambling|lottery|toto|togel|judi|aviator|plinko|rummy|unblocked|apk|apks|kqxs)\b/i';
    private const GLUED = '/(keonhacai|nhacai|taixiu|soikeo|bongda|cacuoc|sunwin|xoso|lodeonline|sattamatka|escortgirl|callgirl|camgirl|adultfriend|freespins|modapk|apkmod|betting|casino|jackpot|socolive|rikvip|kubet|hitclub|b52club|okfun|saowin|thabet|\d+bet|bet\d+|\d+win|win\d+|\d+clb|clb\d+|hi88|w88|vn88|n88|sc88|sv88|xx88|sky88|mb66|fun79|sun52|shbet|fun88|688clb|33win|77bet|8xbet|188bet|i9bet|five88|bdgwin)/i';
    private const DOMAINISH = '/(com|net|org|info|xyz|top|vip|club|live|bid|site|space|one|ceo|moe|life|shop|store|online|link|asia|casa|fun|icu)$/i';
    private const THROWAWAY = '/^[a-z]{4,9}\d{3,5}$/';
    private const YEAR = '/(19|20)\d\d$/';
    private const NAME_WORD = '/\p{L}{4,}/u';
    private const SCRIPT_SHARE = 0.05;
    private const SMALL_USERS = 3;
    private const SMALL_PAGES = 200;

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
        $home = $this->ownHome($name, $folder, $stats);
        $score = 0;
        $reasons = [];

        if (preg_match(self::SCRIPT, $text . ' ' . $slug) === 1 || $this->writtenInAnotherScript($home)) {
            $score += 3;
            $reasons[] = 'script';
        }
        if (($text !== '' && (preg_match(self::WORDS, $text) === 1 || $this->matchesFarmWords($text))) || preg_match(self::WORDS, $home) === 1) {
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
        if (($text !== '' && preg_match(self::URL, $text) === 1) || preg_match(self::OUTSIDE_LINK, $home) === 1) {
            $score++;
            $reasons[] = 'url';
        }
        if ($this->readsForeign($text) || $this->readsForeign($home, 3)) {
            $score += 2;
            $reasons[] = 'language';
        }
        if ($this->neverGrew($stats)) {
            $score++;
            $reasons[] = 'untouched';
        }
        if ((int)($stats['disposableUsers'] ?? 0) > 0 && $this->isSmall($stats)) {
            $score += 2;
            $reasons[] = 'disposable';
        }
        if ($folder !== '' && $this->isThrowawayName($folder, $name)) {
            $score += 2;
            $reasons[] = 'throwaway';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /** The home page of a small wiki when it speaks of what the wiki is called, '' otherwise: a stranger's text on an honest wiki is spammed content, not a spam wiki. */
    private function ownHome(string $name, string $folder, array $stats): string
    {
        $home = mb_strtolower(trim((string)($stats['homeText'] ?? '')));
        if ($home === '' || !$this->isSmall($stats)) {
            return '';
        }
        if ($this->hasNoTitleOfItsOwn($folder, $name)) {
            return $home;
        }

        preg_match_all(self::NAME_WORD, mb_strtolower($name), $words);
        $words = array_unique($words[0]);
        $found = count(array_filter($words, fn ($word) => str_contains($home, $word)));

        return $words !== [] && $found * 2 >= count($words) ? $home : '';
    }

    private function writtenInAnotherScript(string $text): bool
    {
        $letters = preg_match_all(self::LETTER, $text);

        return $letters > 40 && preg_match_all(self::SCRIPT, $text) / $letters > self::SCRIPT_SHARE;
    }

    /** A folder shaped like a temp-mail address, `mawabog928`, used as the wiki's name. */
    private function isThrowawayName(string $folder, string $name): bool
    {
        $folderSlug = $this->slugOf($folder);
        $nameSlug = $this->slugOf($name);

        return preg_match(self::THROWAWAY, $folder) === 1
            && preg_match(self::YEAR, $folder) !== 1
            && $nameSlug !== ''
            && (str_starts_with($nameSlug, $folderSlug) || str_starts_with($folderSlug, $nameSlug));
    }

    /** The folder reads as a domain, and the wiki was given no name of its own. */
    private function looksLikeADomain(string $folder, string $name): bool
    {
        if (preg_match('/\d/', $folder) !== 1 || preg_match(self::DOMAINISH, $folder) !== 1) {
            return false;
        }

        return $this->hasNoTitleOfItsOwn($folder, $name);
    }

    private function hasNoTitleOfItsOwn(string $folder, string $name): bool
    {
        $titled = $this->slugOf($name);

        return $titled === '' || $titled === $this->slugOf($folder);
    }

    private function slugOf(string $text): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $text));
    }

    /** Words a farm adds to the list, as a regular expression fragment. */
    private function matchesFarmWords(string $text): bool
    {
        $extra = trim((string)($this->wiki->config['yeswiki-farm-spam-words'] ?? ''));

        return $extra !== '' && @preg_match('/' . $extra . '/i', $text) === 1;
    }

    /** English where the farm speaks something else; an English farm has nothing to compare against. */
    private function readsForeign(string $text, int $atLeast = 1): bool
    {
        $language = strtolower(substr((string)($this->wiki->config['default_language'] ?? 'fr'), 0, 2));
        if ($text === '' || $language === 'en' || !isset(self::STOPWORDS[$language])) {
            return false;
        }

        $english = preg_match_all(self::STOPWORDS['en'], $text);

        return $english >= $atLeast && $english > preg_match_all(self::STOPWORDS[$language], $text);
    }

    /** @param array<string,mixed> $stats */
    private function isSmall(array $stats): bool
    {
        return $stats !== []
            && (int)($stats['users'] ?? 0) <= self::SMALL_USERS
            && (int)($stats['pages'] ?? 0) < self::SMALL_PAGES;
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
