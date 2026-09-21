<?php

namespace YesWiki\Ferme\Service;

/**
 * Which wikis found on disk deserve a farm entry. A farm that has been open for a
 * while collects wikis created and never used again, so importing everything means
 * importing mostly those. The thresholds are the operator's, and a wiki that was
 * never measured is left out rather than guessed at.
 */
class ImportFilter
{
    public const REASONS = ['inBazar', 'unmeasured', 'entries', 'pages', 'users', 'idle', 'name'];

    /**
     * @param array<int,array<string,mixed>> $inspected as WikiRepository::inspect returns
     * @param array<string,mixed>            $criteria  minEntries, minPages, minUsers, activeSince, nameExcludes
     *
     * @return array{keep:array<int,array<string,mixed>>,left:array<string,array<int,string>>}
     */
    public function apply(array $inspected, array $criteria): array
    {
        $keep = [];
        $left = array_fill_keys(self::REASONS, []);

        foreach ($inspected as $wiki) {
            $reason = $this->reason($wiki, $criteria);
            if ($reason === null) {
                $keep[] = $wiki;
                continue;
            }
            $left[$reason][] = $wiki['folder'];
        }

        return ['keep' => $keep, 'left' => $left];
    }

    /**
     * Why this wiki is not imported, or null when it is.
     *
     * @param array<string,mixed> $wiki
     * @param array<string,mixed> $criteria
     */
    public function reason(array $wiki, array $criteria): ?string
    {
        if (!empty($wiki['existsInBazar'])) {
            return 'inBazar';
        }

        $excludes = (string)($criteria['nameExcludes'] ?? '');
        if ($excludes !== '' && @preg_match('/' . $excludes . '/i', (string)$wiki['folder']) === 1) {
            return 'name';
        }

        $asked = array_filter([
            'entries' => $criteria['minEntries'] ?? null,
            'pages' => $criteria['minPages'] ?? null,
            'users' => $criteria['minUsers'] ?? null,
            'idle' => $criteria['activeSince'] ?? null,
        ], function ($value) {
            return $value !== null;
        });

        if (empty($asked)) {
            return null;
        }

        $stats = $wiki['stats'] ?? null;
        if ($stats === null) {
            return 'unmeasured';
        }

        foreach (['entries', 'pages', 'users'] as $what) {
            if (isset($asked[$what]) && (int)($stats[$what] ?? 0) < (int)$asked[$what]) {
                return $what;
            }
        }

        if (isset($asked['idle'])) {
            $since = date('Y-m-d H:i:s', time() - (int)$asked['idle']);
            if (empty($stats['lastActivity']) || $stats['lastActivity'] < $since) {
                return 'idle';
            }
        }

        return null;
    }
}
