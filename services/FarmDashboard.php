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
    public const HEAVY_ARCHIVES = 1073741824;

    public const FILTERS = ['toUpdate', 'dormant', 'heavyArchives', 'failed', 'unmeasured'];
    public const PROBLEMS = ['missingWiki', 'duplicateFolder', 'noFolder'];
    public const SORTS = ['title', 'referent', 'lastActivity', 'users', 'forms', 'entries', 'pages', 'diskBytes'];

    private const TEXT_SORTS = ['title', 'referent', 'lastActivity'];

    /**
     * @param array<int,array<string,mixed>>    $fiches  the farm entries, as bazar returns them
     * @param array<string,array<string,mixed>> $stats   what the store holds, keyed by folder
     * @param array<string,string>              $current the version and release the farm runs
     * @param array<string,bool>                $onDisk  folder => whether its wakka.config.php is there
     *
     * @return array{fiches:array<int,array<string,mixed>>,total:int,filtered:int,counts:array<string,int>,totals:array<string,int>}
     */
    public function select(
        array $fiches,
        array $stats,
        array $current,
        array $onDisk = [],
        string $search = '',
        string $filter = '',
        string $sort = 'title',
        string $direction = 'asc',
        int $start = 0,
        int $length = 100
    ): array {
        $fiches = $this->attach($fiches, $stats, $current, $onDisk);
        $total = count($fiches);
        $counts = $this->counts($fiches);
        $totals = $this->totals($fiches);

        $kept = $this->filter($fiches, $search, $filter);
        $filtered = count($kept);

        return [
            'fiches' => array_slice($this->sort($kept, $sort, $direction), $start, $length),
            'total' => $total,
            'filtered' => $filtered,
            'counts' => $counts,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<string,mixed> $stats
     */
    public function isDormant(array $stats): bool
    {
        return !empty($stats['lastActivity'])
            && strtotime($stats['lastActivity']) < strtotime(self::DORMANT_AFTER);
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
     *
     * @return array<int,array<string,mixed>>
     */
    private function attach(array $fiches, array $stats, array $current, array $onDisk): array
    {
        $claims = array_count_values(array_filter(array_map(function (array $fiche) {
            return (string)($fiche['bf_dossier-wiki'] ?? '');
        }, $fiches)));

        foreach ($fiches as $index => $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            $measured = $stats[$folder] ?? null;

            $fiches[$index]['problems'] = [
                'noFolder' => $folder === '',
                'missingWiki' => $folder !== '' && array_key_exists($folder, $onDisk) && !$onDisk[$folder],
                'duplicateFolder' => $folder !== '' && ($claims[$folder] ?? 0) > 1,
            ];

            if ($measured !== null) {
                $measured['diskBytes'] = (int)($measured['filesBytes'] ?? 0)
                    + (int)($measured['customBytes'] ?? 0)
                    + (int)($measured['privateBytes'] ?? 0);
                $measured['dormant'] = $this->isDormant($measured);
                $measured['toUpdate'] = $this->isToUpdate($measured, $current);
                $measured['heavyArchives'] = (int)($measured['privateBytes'] ?? 0) >= self::HEAVY_ARCHIVES;
                $measured['failed'] = ($measured['status'] ?? '') === WikiStatsStore::STATUS_ERROR;
            }

            $fiches[$index]['stats'] = $measured;
        }

        return $fiches;
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
            if ($this->hasProblem($fiche)) {
                $counts['failed']++;
                continue;
            }

            $stats = $fiche['stats'] ?? null;
            if ($stats === null) {
                $counts['unmeasured']++;
                continue;
            }
            foreach (['toUpdate', 'dormant', 'heavyArchives', 'failed'] as $flag) {
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
        $totals = array_fill_keys(['wikis', 'measured', 'users', 'forms', 'entries', 'pages', 'files', 'diskBytes'], 0);
        $totals['wikis'] = count($fiches);

        foreach ($fiches as $fiche) {
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
            if ($filter !== '' && !in_array($filter, ['failed', 'unmeasured'], true) && empty($fiche['stats'][$filter])) {
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

        return $sort === 'lastActivity' ? (string)$stats[$sort] : (int)$stats[$sort];
    }
}
