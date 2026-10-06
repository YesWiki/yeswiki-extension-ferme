<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\WikiStatsException;

/** Measures one wiki again, but only what has moved since the last count. */
class StatsRefresher
{
    public const RECOMPUTE_AFTER = 604800;

    /** @var list<string> */
    private array $untouched = [];

    public function __construct(
        private readonly WikiStats $stats,
        private readonly WikiStatsStore $store,
        private readonly SpamScore $spam
    ) {
    }

    /**
     * @param array{force?: bool, withDisk?: bool, known?: array<string, mixed>|null} $options
     *
     * @return array{counted: bool, walked: bool, failed: ?string}
     */
    public function refresh(string $folder, array $options = []): array
    {
        $force = $options['force'] ?? false;
        $withDisk = $options['withDisk'] ?? true;
        $known = array_key_exists('known', $options) ? $options['known'] : $this->store->read($folder);

        $countAgain = $force || $this->databaseMoved($folder, $known);
        $walkAgain = $withDisk && ($force || $this->diskMoved($folder, $known));
        if (!$countAgain && !$walkAgain) {
            $this->untouched[] = $folder;

            return ['counted' => false, 'walked' => false, 'failed' => null];
        }

        try {
            $measured = $countAgain ? $this->stats->fromDatabase($folder) : [];
            if ($walkAgain) {
                $measured = array_merge($measured, $this->stats->fromDisk($folder), ['filesMtime' => $this->stats->diskProbe($folder)]);
            }
            $this->store->save($folder, array_merge($measured, $this->judge($folder, $measured)));
        } catch (WikiStatsException $exception) {
            $this->store->fail($folder, $exception->getReason());

            return ['counted' => false, 'walked' => false, 'failed' => $exception->getReason()];
        }

        return ['counted' => $countAgain, 'walked' => $walkAgain, 'failed' => null];
    }

    /** @param array<string, mixed>|null $known */
    public function isStale(?array $known, int $olderThan): bool
    {
        if ($known === null || empty($known['checkedAt'])) {
            return true;
        }

        return (int)strtotime((string)$known['checkedAt']) < time() - $olderThan;
    }

    /** Count one wiki again now, because something the farm just did changed it. */
    public function remeasure(string $folder, bool $withDisk = true): void
    {
        try {
            $this->refresh($folder, ['force' => true, 'withDisk' => $withDisk]);
            $this->close();
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
    }

    /** End a sweep: the wikis where nothing moved get their check date in one statement. */
    public function close(): void
    {
        if ($this->untouched !== []) {
            $this->store->touchMany($this->untouched);
            $this->untouched = [];
        }
        $this->stats->close();
    }

    /**
     * What the numbers and the wiki's own name say about it being spam.
     *
     * @param array<string, mixed> $measured
     *
     * @return array<string, mixed>
     */
    private function judge(string $folder, array $measured): array
    {
        if (!array_key_exists('name', $measured)) {
            return [];
        }
        $verdict = $this->spam->of((string)$measured['name'], (string)($measured['description'] ?? ''), $measured, $folder);

        return [
            'suspect' => $verdict['score'],
            'suspectWhy' => implode(',', $verdict['reasons']),
        ];
    }

    /** @param array<string, mixed>|null $known */
    private function databaseMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['lastPageId']) || $this->tooOld($known)) {
            return true;
        }
        if (!isset($known['editedPages']) && (int)($known['pages'] ?? 0) > 0) {
            return true;
        }
        $probe = $this->stats->probe($folder);

        return $probe === null || $probe !== $known['lastPageId'];
    }

    /** @param array<string, mixed>|null $known */
    private function diskMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['filesMtime']) || $this->tooOld($known)) {
            return true;
        }
        $probe = $this->stats->diskProbe($folder);

        return $probe === null || $probe !== $known['filesMtime'];
    }

    /** @param array<string, mixed> $known */
    private function tooOld(array $known): bool
    {
        return empty($known['computedAt']) || (int)strtotime((string)$known['computedAt']) < time() - self::RECOMPUTE_AFTER;
    }
}
