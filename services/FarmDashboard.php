<?php

namespace YesWiki\Ferme\Service;

/** Turns the farm's entries and their stored numbers into one page of the admin table: counters, the wikis a chip selects, their order, the slice shown. */
class FarmDashboard
{
    public const DORMANT_AFTER = '-6 months';
    public const SETTLE_AFTER = '-1 month';
    public const QUIET_AFTER = '-6 months';
    public const FEW_PAGES = 5;
    public const HEAVY_ARCHIVES = 1073741824;

    public const FILTERS = ['toUpdate', 'dormant', 'neverEdited', 'heavyArchives', 'suspect', 'spammed', 'failed', 'unmeasured', 'hibernating', 'running', 'ongoing', 'expiring', 'archived'];
    public const LIFETIMES = [WikiLifetime::SHORT, WikiLifetime::LONG, WikiLifetime::PERMANENT];
    public const PROBLEMS = ['missingWiki', 'duplicateFolder', 'noFolder'];
    public const SORTS = ['title', 'referent', 'lastActivity', 'activity', 'users', 'forms', 'entries', 'pages', 'diskBytes'];

    private const TEXT_SORTS = ['title', 'referent', 'lastActivity'];

    /**
     * @param list<array<string, mixed>>          $fiches
     * @param array<string, array<string, mixed>> $stats
     * @param array<string, mixed>                $context
     * @param array<string, mixed>                $query
     *
     * @return array{fiches: list<array<string, mixed>>, total: int, filtered: int, counts: array<string, int>, totals: array<string, int>}
     */
    public function select(array $fiches, array $stats, array $context = [], array $query = []): array
    {
        $fiches = $this->attach(
            $fiches,
            $stats,
            $context['current'] ?? [],
            $context['onDisk'] ?? [],
            (int)($context['spamThreshold'] ?? 3),
            $context['statuses'] ?? [],
            isset($context['lifetime']) && is_callable($context['lifetime']) ? $context['lifetime'] : null
        );
        $total = count($fiches);
        $counts = $this->counts($fiches);
        $totals = $this->totals($fiches);

        $kept = $this->filter($fiches, (string)($query['search'] ?? ''), (string)($query['filter'] ?? ''), (string)($query['lifetime'] ?? ''));
        $filtered = count($kept);
        $sorted = $this->sort($kept, (string)($query['sort'] ?? 'title'), (string)($query['direction'] ?? 'asc'));

        return [
            'fiches' => array_slice($sorted, (int)($query['start'] ?? 0), (int)($query['length'] ?? 100)),
            'total' => $total,
            'filtered' => $filtered,
            'counts' => $counts,
            'totals' => $totals,
        ];
    }

    /**
     * Quiet for six months and nobody meant it.
     *
     * @param array<string, mixed> $stats
     */
    public function isDormant(array $stats): bool
    {
        return !empty($stats['lastActivity'])
            && (int)strtotime((string)$stats['lastActivity']) < (int)strtotime(self::DORMANT_AFTER);
    }

    /**
     * A wiki still holding what its model gave it.
     *
     * @param array<string, mixed> $stats
     */
    public function wasNeverEdited(array $stats): bool
    {
        $installed = strtotime((string)($stats['firstActivity'] ?? '')) ?: null;
        if ($installed === null || $installed > strtotime(self::SETTLE_AFTER)) {
            return false;
        }
        if (!isset($stats['editedPages'])) {
            return false;
        }

        $pages = (int)$stats['editedPages'];
        if ($pages === 0) {
            return true;
        }
        if ($pages > self::FEW_PAGES) {
            return false;
        }

        $written = strtotime((string)($stats['lastEdit'] ?? '')) ?: null;

        return $written !== null && $written < strtotime(self::QUIET_AFTER);
    }

    /**
     * A wiki whose own migrations have not caught up with the Program it runs.
     *
     * @param array<string, mixed>  $stats
     * @param array<string, string> $current
     */
    public function isToUpdate(array $stats, array $current): bool
    {
        if (empty($stats['version']) || empty($stats['release'])) {
            return false;
        }
        if (strtolower((string)$stats['version']) !== strtolower($current['version'] ?? '')) {
            return true;
        }

        return (string)$stats['release'] !== ($current['release'] ?? '');
    }

    /**
     * @param list<array<string, mixed>>          $fiches
     * @param array<string, array<string, mixed>> $stats
     * @param array<string, string>               $current
     * @param array<string, bool>                 $onDisk
     * @param array<string, string>               $statuses
     *
     * @return list<array<string, mixed>>
     */
    private function attach(array $fiches, array $stats, array $current, array $onDisk, int $spamThreshold = 3, array $statuses = [], ?callable $lifetime = null): array
    {
        $claims = array_count_values(array_filter(array_map(static fn (array $fiche): string => (string)($fiche['bf_dossier-wiki'] ?? ''), $fiches)));

        foreach ($fiches as $index => $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            $measured = $stats[$folder] ?? null;
            $fiches[$index]['status'] = (string)($statuses[$folder] ?? ($fiche['status'] ?? ''));
            $fiches[$index]['lifetimeState'] = $lifetime === null ? null : $lifetime($fiche);
            $archived = !empty($fiches[$index]['lifetimeState']['archived']);

            $fiches[$index]['problems'] = [
                'noFolder' => $folder === '',
                'missingWiki' => !$archived && $folder !== '' && array_key_exists($folder, $onDisk) && !$onDisk[$folder],
                'duplicateFolder' => $folder !== '' && ($claims[$folder] ?? 0) > 1,
            ];

            if ($measured !== null) {
                $measured['diskBytes'] = (int)($measured['filesBytes'] ?? 0)
                    + (int)($measured['customBytes'] ?? 0)
                    + (int)($measured['privateBytes'] ?? 0);
                $measured['dormant'] = $this->isDormant($measured) && !$this->isAsleep($fiches[$index]);
                $measured['toUpdate'] = $this->isToUpdate($measured, $current);
                $measured['neverEdited'] = $this->wasNeverEdited($measured);
                $measured['heavyArchives'] = (int)($measured['privateBytes'] ?? 0) >= self::HEAVY_ARCHIVES;
                $measured['failed'] = ($measured['status'] ?? '') === WikiStatsStore::STATUS_ERROR;
                $measured['suspect'] = (int)($measured['suspect'] ?? 0) >= $spamThreshold;
                $measured['spammed'] = (int)($measured['spamPages'] ?? 0) > 0;
                $measured['approvedPages'] = $this->approvedPages($measured);
                $measured['suspectWhy'] = array_values(array_filter(explode(',', (string)($measured['suspectWhy'] ?? ''))));
            }

            $fiches[$index]['stats'] = $measured;
        }

        return $fiches;
    }

    /**
     * How many of a wiki's pages somebody vouched for.
     *
     * @param array<string, mixed> $measured
     */
    private function approvedPages(array $measured): int
    {
        $decoded = json_decode((string)($measured[SpamApprovals::KEY] ?? ''), true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * How many wikis each chip would select, counted before any filter.
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return array<string, int>
     */
    private function counts(array $fiches): array
    {
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach (self::LIFETIMES as $kind) {
            $counts[$kind] = 0;
        }
        foreach ($fiches as $fiche) {
            $counts[$this->lifetimeOf($fiche)]++;
            foreach (['ongoing', 'expiring', 'archived'] as $flag) {
                if ($this->lifetimeIs($fiche, $flag)) {
                    $counts[$flag]++;
                }
            }
            if ($this->lifetimeIs($fiche, 'archived')) {
                continue;
            }
            if ($this->isAsleep($fiche)) {
                $counts['hibernating']++;
            } else {
                $counts['running']++;
            }
            if ($this->hasProblem($fiche)) {
                $counts['failed']++;
                continue;
            }

            $stats = $fiche['stats'] ?? null;
            if ($stats === null) {
                $counts['unmeasured']++;
                continue;
            }
            foreach (['toUpdate', 'dormant', 'neverEdited', 'heavyArchives', 'suspect', 'spammed', 'failed'] as $flag) {
                if (!empty($stats[$flag])) {
                    $counts[$flag]++;
                }
            }
        }

        return $counts;
    }

    /**
     * What the farm holds, added up over the wikis that were measured.
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return array<string, int>
     */
    private function totals(array $fiches): array
    {
        $totals = array_fill_keys(['wikis', 'running', 'hibernating', 'measured', 'users', 'forms', 'entries', 'pages', 'files', 'diskBytes'], 0);
        $totals['wikis'] = count($fiches);

        foreach ($fiches as $fiche) {
            if ($this->lifetimeIs($fiche, 'archived')) {
                continue;
            }
            if ($this->isAsleep($fiche)) {
                $totals['hibernating']++;
            } else {
                $totals['running']++;
            }

            $stats = $fiche['stats'] ?? null;
            if ($stats === null) {
                continue;
            }
            $totals['measured']++;
            foreach (['users', 'forms', 'entries', 'pages', 'files', 'diskBytes'] as $key) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
        }

        return $totals;
    }

    /** @param array<string, mixed> $fiche */
    private function isAsleep(array $fiche): bool
    {
        return WikiHibernator::isAsleep((string)($fiche['status'] ?? ''));
    }

    /**
     * A wiki with no lifetime on its entry lives for ever, like a permanent one.
     *
     * @param array<string, mixed> $fiche
     */
    private function lifetimeOf(array $fiche): string
    {
        return (string)($fiche['lifetimeState']['kind'] ?? WikiLifetime::PERMANENT);
    }

    /**
     * Where a wiki stands in its lifetime: ongoing, expiring or archived.
     *
     * @param array<string, mixed> $fiche
     */
    private function lifetimeIs(array $fiche, string $state): bool
    {
        $lifetime = $fiche['lifetimeState'] ?? null;
        if ($lifetime === null || $lifetime['kind'] === WikiLifetime::PERMANENT) {
            return false;
        }
        if ($state === 'ongoing') {
            return !$lifetime['archived'] && !$lifetime['expiring'];
        }

        return !empty($lifetime[$state]);
    }

    /**
     * A farm entry whose wiki is not on disk, or whose folder another entry claims too, is broken whatever its numbers say.
     *
     * @param array<string, mixed> $fiche
     */
    private function hasProblem(array $fiche): bool
    {
        foreach ($fiche['problems'] ?? [] as $problem) {
            if ($problem) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $fiches
     *
     * @return list<array<string, mixed>>
     */
    private function filter(array $fiches, string $search, string $filter, string $lifetime = ''): array
    {
        $lifetime = in_array($lifetime, self::LIFETIMES, true) ? $lifetime : '';
        $needle = mb_strtolower(trim($search));
        $filter = in_array($filter, self::FILTERS, true) ? $filter : '';

        return array_values(array_filter($fiches, function (array $fiche) use ($needle, $filter, $lifetime) {
            if ($lifetime !== '' && $this->lifetimeOf($fiche) !== $lifetime) {
                return false;
            }
            if ($filter === 'failed' && !$this->hasProblem($fiche) && empty($fiche['stats']['failed'])) {
                return false;
            }
            if ($filter === 'unmeasured' && ($fiche['stats'] !== null || $this->hasProblem($fiche) || !empty($fiche['lifetimeState']['archived']))) {
                return false;
            }
            if ($filter === 'hibernating' && !$this->isAsleep($fiche)) {
                return false;
            }
            if ($filter === 'running' && ($this->isAsleep($fiche) || $this->lifetimeIs($fiche, 'archived'))) {
                return false;
            }
            if (in_array($filter, ['ongoing', 'expiring', 'archived'], true)) {
                if (!$this->lifetimeIs($fiche, $filter)) {
                    return false;
                }
            } elseif ($filter !== '' && !in_array($filter, ['failed', 'unmeasured', 'hibernating', 'running'], true) && empty($fiche['stats'][$filter])) {
                return false;
            }
            if ($needle === '') {
                return true;
            }

            foreach (['title', 'bf_titre', 'bf_referent', 'bf_mail', 'bf_dossier-wiki'] as $field) {
                if (str_contains(mb_strtolower((string)($fiche[$field] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Wikis never measured sort last whichever way the column goes.
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $fiches, string $sort, string $direction): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'title';
        $descending = strtolower($direction) === 'desc';

        usort($fiches, function (array $a, array $b) use ($sort, $descending) {
            $left = $this->sortValue($a, $sort);
            $right = $this->sortValue($b, $sort);

            if ($left === null || $right === null) {
                return $left === $right ? 0 : ($left === null ? 1 : -1);
            }

            $comparison = in_array($sort, self::TEXT_SORTS, true)
                ? strcasecmp((string)$left, (string)$right)
                : $left <=> $right;

            return $descending ? -$comparison : $comparison;
        });

        return $fiches;
    }

    /** @param array<string, mixed> $fiche */
    private function sortValue(array $fiche, string $sort): string|int|null
    {
        if ($sort === 'title') {
            return FarmMailer::titleOf($fiche);
        }
        if ($sort === 'referent') {
            return (string)($fiche['bf_referent'] ?? '');
        }

        $stats = $fiche['stats'] ?? null;
        if ($stats === null || !isset($stats[$sort])) {
            return null;
        }
        if ($sort === 'lastActivity') {
            return (string)$stats[$sort];
        }
        if ($sort === 'activity') {
            return (int)array_sum(array_map('intval', (array)$stats[$sort]));
        }

        return (int)$stats[$sort];
    }
}
