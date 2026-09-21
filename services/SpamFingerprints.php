<?php

namespace YesWiki\Ferme\Service;

/**
 * The one thing a farm can see that a wiki cannot: the same line of links pasted
 * into wiki after wiki. A line carrying a link and found in several unrelated
 * wikis is a robot's, and the cleaning may take it out wherever it sits.
 */
class SpamFingerprints
{
    public const FILE = 'spam-lines.json';
    public const WIKIS_FOR_CAMPAIGN = 5;
    public const PAGES_READ = 60;
    public const LINES_FOR_SPAM_PAGE = 5;
    public const SHARE_FOR_SPAM_PAGE = 0.5;
    public const SAMPLES_KEPT = 200;
    public const SAMPLE_LENGTH = 160;

    private $config;
    private $finder;
    private $database;
    private $known;

    public function __construct(FarmConfig $config, WikiFinder $finder, WikiDatabase $database)
    {
        $this->config = $config;
        $this->finder = $finder;
        $this->database = $database;
    }

    /**
     * The fingerprint of a line, or null for a line the index ignores. Only lines
     * carrying a link are indexed: ordinary prose repeats itself from one wiki to
     * the next without anybody's help.
     */
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

    /**
     * How much of a page is lines the farm has seen elsewhere.
     *
     * @return array{lines:int,known:int,share:float}
     */
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

    /**
     * A page belongs to a campaign when enough of its links come from one: a wiki
     * that copied a footer from a neighbour shares a line or two, never half a page.
     */
    public function isCampaignPage(string $body): bool
    {
        $measure = $this->measurePage($body);

        return $measure['known'] >= self::LINES_FOR_SPAM_PAGE
            && $measure['share'] >= self::SHARE_FOR_SPAM_PAGE;
    }

    /**
     * Read every wiki, learn the lines its condemned pages carry, and keep those
     * that several wikis share.
     *
     * Only condemned pages teach: a farm's wikis are clones of one another, so the
     * lines they all show are the model's, not a robot's. And a line that also sits
     * on a page nobody suspects is dropped whatever its spread — that is how the
     * documentation's own examples stay out of the index.
     *
     * @return array{wikis:int,lines:int,kept:int,dropped:int,path:string}
     */
    public function build(string $hosts = '', int $wikisForCampaign = self::WIKIS_FOR_CAMPAIGN, ?callable $onWiki = null): array
    {
        $suspect = [];
        $innocent = [];
        $samples = [];
        $wikis = 0;

        foreach ($this->finder->find() as $wiki) {
            $folder = (string)$wiki['FOLDER'];
            $wakkaConfig = $this->config->readWikiConfig($folder);
            if (empty($wakkaConfig['table_prefix'])) {
                continue;
            }

            try {
                $db = $this->database->connect($wakkaConfig);
            } catch (\Throwable $throwable) {
                continue;
            }

            $wikis++;
            [$dirty, $clean] = $this->linesOf($db, (string)$wakkaConfig['table_prefix'], $hosts);
            $db->close();

            foreach ($dirty as $print => $line) {
                $suspect[$print] = ($suspect[$print] ?? 0) + 1;
                $samples[$print] = $samples[$print] ?? $line;
            }
            foreach (array_keys($clean) as $print) {
                $innocent[$print] = true;
            }

            if ($onWiki !== null) {
                $onWiki($folder, $wikis);
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

        $path = $this->path();
        file_put_contents($path, (string)json_encode([
            'builtAt' => date('Y-m-d H:i:s'),
            'wikis' => $wikis,
            'threshold' => $wikisForCampaign,
            'lines' => $kept,
            'samples' => array_intersect_key($samples, array_slice($kept, 0, self::SAMPLES_KEPT, true)),
        ]));
        $this->known = $kept;

        return ['wikis' => $wikis, 'lines' => count($suspect), 'kept' => count($kept), 'dropped' => $dropped, 'path' => $path];
    }

    /**
     * @return array<string,int> fingerprint => how many wikis carry it
     */
    public function load(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        $path = $this->path();
        $index = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $this->known = is_array($index['lines'] ?? null) ? $index['lines'] : [];

        return $this->known;
    }

    /**
     * @return array{builtAt:string,wikis:int,threshold:int,kept:int}|null
     */
    public function about(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $index = json_decode((string)file_get_contents($path), true);
        if (!is_array($index)) {
            return null;
        }

        return [
            'builtAt' => (string)($index['builtAt'] ?? ''),
            'wikis' => (int)($index['wikis'] ?? 0),
            'threshold' => (int)($index['threshold'] ?? 0),
            'kept' => is_array($index['lines'] ?? null) ? count($index['lines']) : 0,
        ];
    }

    /**
     * The lines the most wikis carry, with a sample of one, for an operator who
     * wants to see what the index is made of.
     *
     * @return array<int,array{print:string,wikis:int,line:string}>
     */
    public function widest(int $limit = 20): array
    {
        $samples = $this->samples();
        $rows = [];
        foreach (array_slice($this->load(), 0, max(1, $limit), true) as $print => $wikis) {
            $rows[] = ['print' => $print, 'wikis' => $wikis, 'line' => (string)($samples[$print] ?? '')];
        }

        return $rows;
    }

    /**
     * @return array<string,string> fingerprint => one of the lines behind it
     */
    public function samples(): array
    {
        $path = $this->path();
        $index = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

        return is_array($index['samples'] ?? null) ? $index['samples'] : [];
    }

    /**
     * The fingerprints this wiki carries, told apart by the page they sit on.
     *
     * @return array{0:array<string,string>,1:array<string,bool>} the lines of its
     *                                                            condemned pages, then those of the others
     */
    private function linesOf(\mysqli $db, string $prefix, string $hosts): array
    {
        $result = @$db->query(
            'SELECT body FROM `' . $this->database->table($prefix, 'pages') . '`'
            . ' WHERE latest = "Y" ORDER BY time DESC LIMIT ' . self::PAGES_READ
        );

        $dirty = [];
        $clean = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $body = (string)$row['body'];
            $lines = preg_split(SpamCleaner::LINES, $body) ?: [];
            $words = (int)preg_match_all(WikiStats::SPAM_VOCABULARY, $body);
            $links = (int)preg_match_all('#https?://#i', $body);
            $condemned = SpamCleaner::isSpamPage($body, $words, $links, $hosts)
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

    private function path(): string
    {
        return $this->config->ensureBackupDir('spam') . DIRECTORY_SEPARATOR . self::FILE;
    }
}
