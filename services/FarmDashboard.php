<?php

namespace YesWiki\Ferme\Service;

/**
 * Turns the farm's fiches and their stored stats into what one page of the admin
 * table shows: the counters of the summary bar, the wikis a chip selects, the
 * order they come in, and the slice to display. Pure arrays, no database.
 */
class FarmDashboard
{
    public const DORMANT_AFTER = '-6 months';
    public const SETTLE_AFTER = '-1 month';
    public const QUIET_AFTER = '-6 months';
    public const FEW_PAGES = 5;
    public const HEAVY_ARCHIVES = 1073741824;

    public const FILTERS = ['toUpdate', 'dormant', 'neverEdited', 'heavyArchives', 'suspect', 'spammed', 'failed', 'unmeasured', 'hibernating', 'running'];
    public const PROBLEMS = ['missingWiki', 'duplicateFolder', 'noFolder'];
    public const SORTS = ['title', 'referent', 'lastActivity', 'activity', 'users', 'forms', 'entries', 'pages', 'diskBytes'];

    private const TEXT_SORTS = ['title', 'referent', 'lastActivity'];

    /**
     * @param array<int,array<string,mixed>>    $fiches the farm entries, as bazar returns them
     * @param array<string,array<string,mixed>> $stats  what the store holds, keyed by folder
     *
     * @return array{fiches:array<int,array<string,mixed>>,total:int,filtered:int,counts:array<string,int>,totals:array<string,int>}
     */
    public function select(array $fiches, array $stats, array $context = [], array $query = []): array
    {
        $fiches = $this->attach(
            $fiches,
            $stats,
            $context['current'] ?? [],
            $context['onDisk'] ?? [],
            (int)($context['spamThreshold'] ?? 3),
            $context['statuses'] ?? []
        );
        $total = count($fiches);
        $counts = $this->counts($fiches);
        $totals = $this->totals($fiches);

        $kept = $this->filter($fiches, (string)($query['search'] ?? ''), (string)($query['filter'] ?? ''));
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
     * Quiet for six months and nobody meant it. A wiki someone put to sleep is
     * quiet on purpose, and belongs in its own count rather than in this one.
     *
     * @param array<string,mixed> $stats
     */
    public function isDormant(array $stats): bool
    {
        return !empty($stats['lastActivity'])
            && strtotime($stats['lastActivity']) < strtotime(self::DORMANT_AFTER);
    }

    /**
     * A wiki still holding what its model gave it. Either nobody ever wrote in it,
     * or somebody tried it out — a page or three, a file dropped in — and never came
     * back. A month of grace before a wiki can be called that, since a wiki created
     * last week has not had its chance yet.
     *
     * Read on the farm of 3 029 wikis: 131 were never written in at all, and 533
     * more had at most five pages touched, the last of them over six months ago.
     *
     * @param array<string,mixed> $stats
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
     * @param array<string,mixed>  $stats
     * @param array<string,string> $current
     */
    public function isToUpdate(array $stats, array $current): bool
    {
        if (empty($stats['version']) || empty($stats['release'])) {
            return false;
        }
        if (strtolower($stats['version']) !== strtolower($current['version'] ?? '')) {
            return true;
        }

        return $stats['release'] !== ($current['release'] ?? '');
    }

    /**
     * @param array<int,array<string,mixed>>    $fiches
     * @param array<string,array<string,mixed>> $stats
     * @param array<string,string>              $current
     * @param array<string,bool>                $onDisk
     * @param array<string,string>              $statuses wiki_status of every wiki, not only the page's
     *
     * @return array<int,array<string,mixed>>
     */
    private function attach(array $fiches, array $stats, array $current, array $onDisk, int $spamThreshold = 3, array $statuses = []): array
    {
        $claims = array_count_values(array_filter(array_map(function (array $fiche) {
            return (string)($fiche['bf_dossier-wiki'] ?? '');
        }, $fiches)));

        foreach ($fiches as $index => $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            $measured = $stats[$folder] ?? null;
            $fiches[$index]['status'] = (string)($statuses[$folder] ?? ($fiche['status'] ?? ''));

            $fiches[$index]['problems'] = [
                'noFolder' => $folder === '',
                'missingWiki' => $folder !== '' && array_key_exists($folder, $onDisk) && !$onDisk[$folder],
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
     * How many of a wiki's pages somebody vouched for, so a wiki whose spam is all
     * approved still has something to show and the approval can be taken back.
     *
     * @param array<string,mixed> $measured
     */
    private function approvedPages(array $measured): int
    {
        $decoded = json_decode((string)($measured[SpamApprovals::KEY] ?? ''), true);

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * How many wikis each chip of the summary bar would select, counted before any
     * filter so the numbers do not move as you click.
     *
     * @param array<int,array<string,mixed>> $fiches
     *
     * @return array<string,int>
     */
    private function counts(array $fiches): array
    {
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($fiches as $fiche) {
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
     * What the farm holds, added up over the wikis that were measured. `measured`
     * says how many that was, so a sum over none can be shown as unknown rather
     * than as zero.
     *
     * @param array<int,array<string,mixed>> $fiches
     *
     * @return array<string,int>
     */
    private function totals(array $fiches): array
    {
        $totals = array_fill_keys(['wikis', 'running', 'hibernating', 'measured', 'users', 'forms', 'entries', 'pages', 'files', 'diskBytes'], 0);
        $totals['wikis'] = count($fiches);

        foreach ($fiches as $fiche) {
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

    /**
     * @return array<int,array<string,mixed>>
     */
    /**
     * A farm entry whose wiki is not on disk, or whose folder another entry claims
     * too, is broken whatever its stats say.
     *
     * @param array<string,mixed> $fiche
     */
    /**
     * @param array<string,mixed> $fiche
     */
    private function isAsleep(array $fiche): bool
    {
        return WikiHibernator::isAsleep((string)($fiche['status'] ?? ''));
    }

    private function hasProblem(array $fiche): bool
    {
        foreach ($fiche['problems'] ?? [] as $problem) {
            if ($problem) {
                return true;
            }
        }

        return false;
    }

    private function filter(array $fiches, string $search, string $filter): array
    {
        $needle = mb_strtolower(trim($search));
        $filter = in_array($filter, self::FILTERS, true) ? $filter : '';

        return array_values(array_filter($fiches, function (array $fiche) use ($needle, $filter) {
            if ($filter === 'failed' && !$this->hasProblem($fiche) && empty($fiche['stats']['failed'])) {
                return false;
            }
            if ($filter === 'unmeasured' && ($fiche['stats'] !== null || $this->hasProblem($fiche))) {
                return false;
            }
            if ($filter === 'hibernating' && !$this->isAsleep($fiche)) {
                return false;
            }
            if ($filter === 'running' && $this->isAsleep($fiche)) {
                return false;
            }
            if ($filter !== '' && !in_array($filter, ['failed', 'unmeasured', 'hibernating', 'running'], true) && empty($fiche['stats'][$filter])) {
                return false;
            }
            if ($needle === '') {
                return true;
            }

            foreach (['bf_titre', 'bf_referent', 'bf_mail', 'bf_dossier-wiki'] as $field) {
                if (str_contains(mb_strtolower((string)($fiche[$field] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Wikis that were never measured sort last whichever way the column goes, so a
     * missing number never passes for a small one.
     *
     * @param array<int,array<string,mixed>> $fiches
     *
     * @return array<int,array<string,mixed>>
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

    /**
     * @param array<string,mixed> $fiche
     *
     * @return int|string|null
     */
    private function sortValue(array $fiche, string $sort)
    {
        if ($sort === 'title') {
            return (string)($fiche['bf_titre'] ?? '');
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
            return array_sum(array_map('intval', (array)$stats[$sort]));
        }

        return (int)$stats[$sort];
    }
}
