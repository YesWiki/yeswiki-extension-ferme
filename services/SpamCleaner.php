<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Files\Service\Storage;

/** Takes the robots' pages out of a wiki they filled: their own pages go, the real pages they edited lose the spam lines. */
class SpamCleaner
{
    public const SKELETON = ['pageprincipale', 'pageheader', 'pagefooter', 'pagetitre', 'pagemenu', 'pagerapide', 'pagecss', 'pagecolor', 'bacasable', 'tableaudebord', 'gerersite', 'accueil'];

    public const LINES = '/\r\n|\r|\n/';
    public const WORDS_FOR_SPAM = 2;
    public const LINKS_FOR_SPAM = 50;
    public const LINKS_FOR_SPAM_LINE = 5;
    public const TEXT_BESIDE_LINK = 20;
    public const LINES_FOR_LINK_FARM = 10;
    public const SHARE_FOR_LINK_FARM = 0.6;
    public const LINES_FOR_LINK_LIST = 50;
    public const SHARE_FOR_LINK_LIST = 0.8;
    public const SHARE_FOR_MANY_LINKS = 0.3;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly WikiPages $pages,
        private readonly InstanceConsole $console,
        private readonly FolderLock $lock,
        private readonly WikiHibernator $hibernator,
        private readonly StatsRefresher $refresher,
        private readonly SpamFingerprints $fingerprints,
        private readonly SpamApprovals $approvals,
        private readonly Storage $storage
    ) {
    }

    /** The hosts a campaign keeps pointing at, as a regular expression fragment the farm sets. */
    public function hosts(): string
    {
        return $this->config->text('yeswiki-farm-spam-hosts');
    }

    /**
     * What the cleaning would do, page by page, touching nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function inspect(string $folder): array
    {
        return $this->look($folder);
    }

    /** One page of a wiki as it stands, or null when it has no such page. */
    public function bodyOf(string $folder, string $tag): ?string
    {
        return $this->pages->content($folder, $tag);
    }

    /**
     * The pages a wiki is condemned for that no rule can clean, with the hosts their links point at.
     *
     * @return list<array{tag: string, links: int, share: float, hosts: array<string, int>}>
     */
    public function stuck(string $folder): array
    {
        $hosts = $this->hosts();
        $stuck = [];
        foreach ($this->readPages($folder) as $page) {
            $body = $page['content'];
            $words = (int)preg_match_all(WikiStats::SPAM_VOCABULARY, $body);
            preg_match_all('#https?://([a-z0-9.\-]+)#i', $body, $found);
            $links = count($found[1]);
            if (!self::isSpamPage($body, $words, $links, $hosts, $this->fingerprints->isCampaignPage($body))
                || $this->approvals->isApproved($folder, $page['tag'], $body)
                || self::strip($body, $hosts, $this->fingerprints) !== trim($body)) {
                continue;
            }
            $counted = array_count_values(array_map('strtolower', $found[1]));
            arsort($counted);
            $stuck[] = [
                'tag' => $page['tag'],
                'links' => $links,
                'share' => self::linkShare(preg_split(self::LINES, $body) ?: []),
                'hosts' => array_slice($counted, 0, 3, true),
            ];
        }

        return $stuck;
    }

    /**
     * Clean a wiki, or say what cleaning it would do.
     *
     * @return array{deleted: int, stripped: int, kept: int, pages: list<array<string, mixed>>, dump: ?string}
     */
    public function clean(string $folder, bool $dryRun = true): array
    {
        if (!$this->config->hasWiki($folder)) {
            throw new WikiStatsException($folder, _t('FERME_CLI_NO_CONFIG_FILE'));
        }

        return $this->lock->during($this->config->wikiDir($folder), _t('FERME_LOCK_CLEAN'), function () use ($folder, $dryRun): array {
            $pages = $this->look($folder);
            $todo = array_values(array_filter($pages, static fn (array $page): bool => $page['action'] !== 'keep'));
            $dump = null;

            if (!$dryRun && $todo !== []) {
                $dump = $this->dump($folder, $todo);
                $orders = array_map(fn (array $page): array => [
                    'tag' => $page['tag'],
                    'action' => $page['action'],
                    'content' => $page['action'] === 'delete' ? '' : self::strip((string)$page['content'], $this->hosts(), $this->fingerprints),
                ], $todo);
                $this->hibernator->whileAwake($folder, fn (): array => $this->console->task($this->config->wikiDir($folder), 'clean', ['pages' => $orders]));
                $this->refresher->remeasure($folder, false);
            }

            return [
                'deleted' => count(array_filter($todo, static fn (array $p): bool => $p['action'] === 'delete')),
                'stripped' => count(array_filter($todo, static fn (array $p): bool => $p['action'] === 'strip')),
                'kept' => count($pages) - count($todo),
                'pages' => array_map(static function (array $page): array {
                    unset($page['content']);

                    return $page;
                }, $todo),
                'dump' => $dump,
            ];
        });
    }

    /** The one rule: what the cleaning would touch, asked by the statistics too. */
    public static function isSpamPage(string $body, int $words, int $links, string $hosts = '', bool $campaign = false): bool
    {
        if ($campaign || $words >= self::WORDS_FOR_SPAM) {
            return true;
        }
        if ($links >= self::LINKS_FOR_SPAM && self::linkShare(preg_split(self::LINES, $body) ?: []) >= self::SHARE_FOR_MANY_LINKS) {
            return true;
        }

        return $hosts !== '' && @preg_match('#(' . $hosts . ')#i', $body) === 1;
    }

    /**
     * How much of what a page says is links.
     *
     * @param list<string> $lines
     */
    public static function linkShare(array $lines): float
    {
        $carrying = 0;
        $written = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $written++;
            if (preg_match('#https?://#i', $line) === 1) {
                $carrying++;
            }
        }

        return $written === 0 ? 0.0 : $carrying / $written;
    }

    /**
     * The lines of a page that is little more than a stack of links, by their position.
     *
     * @param list<string> $lines
     *
     * @return array<int, true>
     */
    public static function linkFarm(array $lines): array
    {
        $bare = [];
        $carrying = [];
        $written = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $written++;
            if (preg_match('#https?://#i', $line) !== 1 || str_contains($line, '{{')) {
                continue;
            }
            $carrying[$index] = true;
            $beside = trim((string)preg_replace('#\[\[|\]\]|https?://\S+#i', '', $line));
            if (strlen($beside) <= self::TEXT_BESIDE_LINK) {
                $bare[$index] = true;
            }
        }

        if (count($bare) >= self::LINES_FOR_LINK_FARM && count($bare) / max(1, $written) >= self::SHARE_FOR_LINK_FARM) {
            return $bare;
        }

        return count($carrying) >= self::LINES_FOR_LINK_LIST && count($carrying) / max(1, $written) >= self::SHARE_FOR_LINK_LIST ? $carrying : [];
    }

    /** A page's text with the spam taken out: nothing left means a page the robot wrote. */
    public static function strip(string $body, string $hosts = '', ?SpamFingerprints $campaigns = null): string
    {
        $lines = preg_split(self::LINES, $body) ?: [];
        $farm = self::linkFarm($lines);
        $kept = [];
        foreach ($lines as $index => $line) {
            if (isset($farm[$index]) || self::isSpamLine($line, $hosts) || ($campaigns !== null && $campaigns->isKnownLine($line))) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    private static function isSpamLine(string $line, string $hosts = ''): bool
    {
        if (preg_match(WikiStats::SPAM_VOCABULARY, $line) === 1) {
            return true;
        }
        if ($hosts !== '' && @preg_match('#(' . $hosts . ')#i', $line) === 1) {
            return true;
        }

        return preg_match_all('#https?://#i', $line) >= self::LINKS_FOR_SPAM_LINE;
    }

    /** @return list<array{tag: string, content: string, owner: string, revisions: int}> */
    private function readPages(string $folder): array
    {
        try {
            return $this->pages->current($folder, null, true);
        } catch (\Throwable $throwable) {
            throw new WikiStatsException($folder, $throwable->getMessage(), $throwable);
        }
    }

    /** @return list<array<string, mixed>> */
    private function look(string $folder): array
    {
        $hosts = $this->hosts();
        $found = [];
        foreach ($this->readPages($folder) as $page) {
            $body = $page['content'];
            $words = (int)preg_match_all(WikiStats::SPAM_VOCABULARY, $body);
            $links = (int)preg_match_all('#https?://#i', $body);
            $campaign = $this->fingerprints->isCampaignPage($body);
            if (!self::isSpamPage($body, $words, $links, $hosts, $campaign) || $this->approvals->isApproved($folder, $page['tag'], $body)) {
                continue;
            }
            $found[] = [
                'tag' => $page['tag'],
                'action' => $this->decide($page['tag'], $body, $page['revisions'], $page['owner']),
                'words' => $words,
                'links' => $links,
                'revisions' => $page['revisions'],
                'owner' => $page['owner'],
                'campaign' => $campaign,
                'host' => $hosts !== '' && @preg_match('#(' . $hosts . ')#i', $body) === 1,
                'cleanable' => self::strip($body, $hosts, $this->fingerprints) !== trim($body),
                'content' => $body,
            ];
        }

        return $found;
    }

    /** A page the robot created goes; a page it edited is kept and cleaned. */
    private function decide(string $tag, string $body, int $revisions, string $owner): string
    {
        foreach (self::SKELETON as $name) {
            if (str_starts_with(strtolower($tag), $name)) {
                return 'strip';
            }
        }
        if ($revisions === 1 && $owner === '') {
            return 'delete';
        }

        return self::strip($body, $this->hosts(), $this->fingerprints) === '' ? 'delete' : 'strip';
    }

    /**
     * Everything about to be removed, written down first in the farm's backups.
     *
     * @param list<array<string, mixed>> $todo
     */
    private function dump(string $folder, array $todo): string
    {
        $kept = [];
        foreach ($todo as $page) {
            $kept[] = ['tag' => $page['tag'], 'action' => $page['action'], 'revisions' => $this->pages->revisions($folder, (string)$page['tag'])];
        }
        $path = $this->config->dataPath('spam/' . str_replace('/', '-', $folder) . '-' . date('Ymd-His') . '.json');
        $this->storage->write($path, (string)json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }
}
