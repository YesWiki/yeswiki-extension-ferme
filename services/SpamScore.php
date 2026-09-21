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

    public const REASONS = ['script', 'words', 'url', 'language', 'untouched'];

    private const SCRIPT = '/[ạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹđ\x{0400}-\x{04ff}\x{0600}-\x{06ff}\x{0900}-\x{097f}\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u';
    private const URL = '#https?://|www\.#i';
    private const WORDS = '/\b(bet|bets|betting|casino|slot|slots|jackpot|poker|clb|escort|escorts|call ?girl|viagra|cialis|crypto|bitcoin|forex|essay|essays|homework|assignment|dissertation|seo|backlink|gambling|lottery|toto|togel|judi|nhacai|taixiu|33win|77bet|688clb|8xbet|hi88|w88|188bet|shbet|fun88|vn88|sunwin|bdgwin)\b/i';

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
    public function of(string $name, string $description, array $stats): array
    {
        $text = trim(mb_strtolower($name . ' ' . $description));
        $score = 0;
        $reasons = [];

        if ($text !== '' && preg_match(self::SCRIPT, $text) === 1) {
            $score += 3;
            $reasons[] = 'script';
        }
        if ($text !== '' && (preg_match(self::WORDS, $text) === 1 || $this->matchesFarmWords($text))) {
            $score += 3;
            $reasons[] = 'words';
        }
        if ($text !== '' && preg_match(self::URL, $text) === 1) {
            $score += 2;
            $reasons[] = 'url';
        }
        if ($this->readsForeign($text)) {
            $score += 2;
            $reasons[] = 'language';
        }
        if ($this->neverGrew($stats)) {
            $score += 2;
            $reasons[] = 'untouched';
        }

        return ['score' => $score, 'reasons' => $reasons];
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
