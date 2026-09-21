<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Ferme\Exception\WikiStatsException;

/**
 * Measures one wiki again, but only what has moved: the two probes decide whether
 * the counting, the weighing, both or neither are worth doing. The sweep and the
 * per-visit trickle both go through here, so they cannot drift apart.
 */
class StatsRefresher
{
    public const RECOMPUTE_AFTER = 604800;

    private $stats;
    private $store;
    private $spam;
    private $untouched = [];

    public function __construct(WikiStats $stats, WikiStatsStore $store, SpamScore $spam)
    {
        $this->stats = $stats;
        $this->store = $store;
        $this->spam = $spam;
    }

    /**
     * @param array{force?:bool,withDisk?:bool,known?:array<string,mixed>|null} $options
     *
     * @return array{counted:bool,walked:bool,failed:?string}
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
            $measured = [];
            if ($countAgain) {
                $measured = $this->stats->fromDatabase($folder);
            }
            if ($walkAgain) {
                $measured = array_merge($measured, $this->stats->fromDisk($folder), [
                    'filesMtime' => $this->stats->diskProbe($folder),
                ]);
            }
            $this->store->save($folder, array_merge($measured, $this->judge($measured, $known)));
        } catch (WikiStatsException $exception) {
            $this->store->fail($folder, $exception->getReason());

            return ['counted' => false, 'walked' => false, 'failed' => $exception->getReason()];
        }

        return ['counted' => $countAgain, 'walked' => $walkAgain, 'failed' => null];
    }

    /**
     * What the numbers and the wiki's own name say about it being spam. A pass that
     * only weighed the folders keeps whatever the last full count decided.
     *
     * @param array<string,mixed>      $measured
     * @param array<string,mixed>|null $known
     *
     * @return array<string,mixed>
     */
    private function judge(array $measured, ?array $known): array
    {
        if (!array_key_exists('name', $measured)) {
            return [];
        }

        $verdict = $this->spam->of(
            (string)$measured['name'],
            (string)($measured['description'] ?? ''),
            $measured
        );

        return [
            'suspect' => $verdict['score'],
            'suspectWhy' => implode(',', $verdict['reasons']),
        ];
    }

    /**
     * @param array<string,mixed>|null $known
     */
    public function isStale(?array $known, int $olderThan): bool
    {
        if ($known === null || empty($known['checkedAt'])) {
            return true;
        }

        return strtotime($known['checkedAt']) < time() - $olderThan;
    }

    /**
     * Ends a sweep: the wikis where nothing had moved get their check date in one
     * statement, and the connections opened along the way are released.
     */
    public function close(): void
    {
        if (!empty($this->untouched)) {
            $this->store->touchMany($this->untouched);
            $this->untouched = [];
        }
        $this->stats->close();
    }

    private function databaseMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['lastPageId']) || $this->tooOld($known)) {
            return true;
        }

        $probe = $this->stats->probe($folder);

        return $probe === null || $probe !== $known['lastPageId'];
    }

    private function diskMoved(string $folder, ?array $known): bool
    {
        if ($known === null || !isset($known['filesMtime']) || $this->tooOld($known)) {
            return true;
        }

        $probe = $this->stats->diskProbe($folder);

        return $probe === null || $probe !== $known['filesMtime'];
    }

    /**
     * Deleting an account moves no page id and touches no folder, so a week without
     * a full count is enough to recount whatever the probes say.
     *
     * @param array<string,mixed> $known
     */
    private function tooOld(array $known): bool
    {
        return empty($known['computedAt']) || strtotime($known['computedAt']) < time() - self::RECOMPUTE_AFTER;
    }
}
