<?php

namespace YesWiki\Ferme\Service;

/** The pages somebody looked at and vouched for, remembered as they were approved: a page that changes is judged again. */
class SpamApprovals
{
    public const KEY = 'spamApproved';

    /** @var array<string, array<string, string>> */
    private array $read = [];

    public function __construct(private readonly WikiStatsStore $store)
    {
    }

    /** What is vouched for is the text, not the name. */
    public static function mark(string $body): string
    {
        return substr(sha1(preg_replace('/\s+/', ' ', trim($body)) ?? $body), 0, 16);
    }

    public function isApproved(string $folder, string $tag, string $body): bool
    {
        $approved = $this->all($folder);

        return isset($approved[$tag]) && $approved[$tag] === self::mark($body);
    }

    /** @return array<string, string> page tag => the mark of the text that was approved */
    public function all(string $folder): array
    {
        if (!array_key_exists($folder, $this->read)) {
            $stored = (string)($this->store->read($folder)[self::KEY] ?? '');
            $decoded = $stored === '' ? null : json_decode($stored, true);
            $this->read[$folder] = is_array($decoded) ? array_map('strval', $decoded) : [];
        }

        return $this->read[$folder];
    }

    public function approve(string $folder, string $tag, string $body): void
    {
        $approved = $this->all($folder);
        $approved[$tag] = self::mark($body);
        $this->keep($folder, $approved);
    }

    public function forget(string $folder, string $tag): bool
    {
        $approved = $this->all($folder);
        if (!isset($approved[$tag])) {
            return false;
        }
        unset($approved[$tag]);
        $this->keep($folder, $approved);

        return true;
    }

    /** @param array<string, string> $approved */
    private function keep(string $folder, array $approved): void
    {
        $this->read[$folder] = $approved;
        $this->store->keep($folder, [self::KEY => $approved === [] ? null : json_encode($approved)]);
    }
}
