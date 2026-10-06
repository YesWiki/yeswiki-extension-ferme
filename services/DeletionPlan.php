<?php

namespace YesWiki\Ferme\Service;

/** Which entries name which folder, read once: a folder two entries claim must survive the deletion of one of them. */
class DeletionPlan
{
    /** @var array<string, list<string>> */
    private array $byFolder = [];

    /** @param array<array-key, mixed> $entries as EntryManager::search returns them */
    public function index(array $entries): void
    {
        $this->byFolder = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $folder = (string)($entry['bf_dossier-wiki'] ?? '');
            $tag = (string)($entry['tag'] ?? '');
            if ($folder !== '' && $tag !== '') {
                $this->byFolder[$folder][] = $tag;
            }
        }
    }

    /** @return list<string> the entries other than this one naming that folder */
    public function others(string $folder, string $tag): array
    {
        return array_values(array_filter($this->byFolder[$folder] ?? [], static fn (string $other): bool => $other !== $tag));
    }

    /** An entry just deleted claims nothing any more. */
    public function forget(string $tag): void
    {
        foreach ($this->byFolder as $folder => $entries) {
            $left = array_values(array_filter($entries, static fn (string $other): bool => $other !== $tag));
            if ($left === []) {
                unset($this->byFolder[$folder]);
            } else {
                $this->byFolder[$folder] = $left;
            }
        }
    }
}
