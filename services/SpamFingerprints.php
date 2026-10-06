<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Files\Service\Storage;

/** The one thing a farm sees that a wiki cannot: the same line of links pasted into wiki after wiki. */
class SpamFingerprints
{
    public const FILE = 'spam/spam-lines.json';
    public const WIKIS_FOR_CAMPAIGN = 5;
    public const PAGES_READ = 60;
    public const LINES_FOR_SPAM_PAGE = 5;
    public const SHARE_FOR_SPAM_PAGE = 0.5;
    public const SAMPLES_KEPT = 200;
    public const SAMPLE_LENGTH = 160;

    /** @var array<string, int>|null */
    private ?array $known = null;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly WikiFinder $finder,
        private readonly WikiPages $pages,
        private readonly Storage $storage
    ) {
    }

    /** The fingerprint of a line carrying a link, or null for any other line. */
    public static function fingerprint(string $line): ?string
    {
        if (preg_match('#https?://#i', $line) !== 1) {
            return null;
        }
        $normalised = preg_replace('/\s+/', ' ', mb_strtolower(trim($line)));

        return $normalised === null || $normalised === '' ? null : substr(sha1($normalised), 0, 12);
    }

    public function isKnownLine(string $line): bool
    {
        $print = self::fingerprint($line);

        return $print !== null && isset($this->load()[$print]);
    }

    /** @return array{lines: int, known: int, share: float} */
    public function measurePage(string $body): array
    {
        $index = $this->load();
        $lines = 0;
        $known = 0;
        foreach (preg_split(SpamCleaner::LINES, $body) ?: [] as $line) {
            $print = self::fingerprint($line);
            if ($print === null) {
                continue;
            }
            $lines++;
            if (isset($index[$print])) {
                $known++;
            }
        }

        return ['lines' => $lines, 'known' => $known, 'share' => $lines === 0 ? 0.0 : $known / $lines];
    }

    /** A page belongs to a campaign when enough of its links come from one. */
    public function isCampaignPage(string $body): bool
    {
        $measure = $this->measurePage($body);

        return $measure['known'] >= self::LINES_FOR_SPAM_PAGE && $measure['share'] >= self::SHARE_FOR_SPAM_PAGE;
    }

    /**
     * Read every wiki, learn the lines its condemned pages carry, and keep those several wikis share and no innocent page carries.
     *
     * @param callable(string, int): void|null $onWiki
     *
     * @return array{wikis: int, lines: int, kept: int, dropped: int, path: string}
     */
    public function build(string $hosts = '', int $wikisForCampaign = self::WIKIS_FOR_CAMPAIGN, ?callable $onWiki = null): array
    {
        $suspect = [];
        $innocent = [];
        $samples = [];
        $wikis = 0;

        foreach ($this->finder->find() as $wiki) {
            try {
                $pages = $this->pages->current($wiki['FOLDER'], self::PAGES_READ);
            } catch (\Throwable $throwable) {
                continue;
            }
            $wikis++;
            [$dirty, $clean] = $this->linesOf($pages, $hosts);
            foreach ($dirty as $print => $line) {
                $suspect[$print] = ($suspect[$print] ?? 0) + 1;
                $samples[$print] ??= $line;
            }
            foreach (array_keys($clean) as $print) {
                $innocent[$print] = true;
            }
            if ($onWiki !== null) {
                $onWiki($wiki['FOLDER'], $wikis);
            }
        }

        $kept = [];
        $dropped = 0;
        foreach ($suspect as $print => $count) {
            if ($count < $wikisForCampaign) {
                continue;
            }
            if (isset($innocent[$print])) {
                $dropped++;
                continue;
            }
            $kept[$print] = $count;
        }
        arsort($kept);

        $path = $this->config->dataPath(self::FILE);
        $this->storage->write($path, (string)json_encode([
            'builtAt' => date('Y-m-d H:i:s'),
            'wikis' => $wikis,
            'threshold' => $wikisForCampaign,
            'lines' => $kept,
            'samples' => array_intersect_key($samples, array_slice($kept, 0, self::SAMPLES_KEPT, true)),
        ]));
        $this->known = $kept;

        return ['wikis' => $wikis, 'lines' => count($suspect), 'kept' => count($kept), 'dropped' => $dropped, 'path' => $path];
    }

    /** @return array<string, int> fingerprint => how many wikis carry it */
    public function load(): array
    {
        if ($this->known === null) {
            $lines = $this->index()['lines'] ?? null;
            $this->known = is_array($lines) ? array_map('intval', $lines) : [];
        }

        return $this->known;
    }

    /** @return array{builtAt: string, wikis: int, threshold: int, kept: int}|null */
    public function about(): ?array
    {
        $index = $this->index();
        if ($index === []) {
            return null;
        }

        return [
            'builtAt' => (string)($index['builtAt'] ?? ''),
            'wikis' => (int)($index['wikis'] ?? 0),
            'threshold' => (int)($index['threshold'] ?? 0),
            'kept' => is_array($index['lines'] ?? null) ? count($index['lines']) : 0,
        ];
    }

    /** @return list<array{print: string, wikis: int, line: string}> */
    public function widest(int $limit = 20): array
    {
        $samples = $this->samples();
        $rows = [];
        foreach (array_slice($this->load(), 0, max(1, $limit), true) as $print => $wikis) {
            $rows[] = ['print' => (string)$print, 'wikis' => $wikis, 'line' => (string)($samples[$print] ?? '')];
        }

        return $rows;
    }

    /** @return array<string, string> */
    public function samples(): array
    {
        $samples = $this->index()['samples'] ?? null;

        return is_array($samples) ? array_map('strval', $samples) : [];
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        $path = $this->config->dataPath(self::FILE);
        try {
            $decoded = $this->storage->fileExists($path) ? json_decode($this->storage->read($path), true) : null;
        } catch (\Throwable $throwable) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The fingerprints a wiki carries, told apart by whether the page they sit on is condemned.
     *
     * @param list<array{tag: string, content: string, owner: string, revisions: int}> $pages
     *
     * @return array{0: array<string, string>, 1: array<string, bool>}
     */
    private function linesOf(array $pages, string $hosts): array
    {
        $dirty = [];
        $clean = [];
        foreach ($pages as $page) {
            $body = $page['content'];
            $lines = preg_split(SpamCleaner::LINES, $body) ?: [];
            $condemned = SpamCleaner::isSpamPage($body, (int)preg_match_all(WikiStats::SPAM_VOCABULARY, $body), (int)preg_match_all('#https?://#i', $body), $hosts)
                || SpamCleaner::linkFarm($lines) !== [];
            foreach ($lines as $line) {
                $print = self::fingerprint($line);
                if ($print === null) {
                    continue;
                }
                if ($condemned) {
                    $dirty[$print] = mb_substr(trim($line), 0, self::SAMPLE_LENGTH);
                } else {
                    $clean[$print] = true;
                }
            }
        }

        return [$dirty, $clean];
    }
}
