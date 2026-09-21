<?php

namespace YesWiki\Ferme\Service;

/**
 * What a deletion needs to know about the other entries, worked out once.
 *
 * Asking "does another entry claim this folder?" means reading every farm entry,
 * which costs half a second on a farm of a few thousand. Deleting three hundred
 * wikis one request at a time paid it three hundred times.
 */
class DeletionPlan
{
    private $byFolder = [];

    /**
     * @param array<string,array<string,mixed>> $entries as EntryManager::search returns them
     */
    public function index(array $entries): void
    {
        $this->byFolder = [];
        foreach ($entries as $entry) {
            $folder = (string)($entry['bf_dossier-wiki'] ?? '');
            $idFiche = (string)($entry['id_fiche'] ?? '');
            if ($folder === '' || $idFiche === '') {
                continue;
            }
            $this->byFolder[$folder][] = $idFiche;
        }
    }

    /**
     * @return array<int,string> the entries other than this one naming that folder
     */
    public function others(string $folder, string $idFiche): array
    {
        return array_values(array_filter(
            $this->byFolder[$folder] ?? [],
            function (string $other) use ($idFiche) {
                return $other !== $idFiche;
            }
        ));
    }

    /**
     * An entry that has just been deleted claims nothing any more: without this, the
     * second of two entries on one folder would be kept alive by the first, already gone.
     */
    public function forget(string $idFiche): void
    {
        foreach ($this->byFolder as $folder => $entries) {
            $left = array_values(array_filter($entries, function (string $other) use ($idFiche) {
                return $other !== $idFiche;
            }));
            if ($left === []) {
                unset($this->byFolder[$folder]);
            } else {
                $this->byFolder[$folder] = $left;
            }
        }
    }
}
