<?php

/**
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 *
 * @see     https://yeswiki.net
 */

namespace YesWiki\Ferme;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\YesWikiHandler;
use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Ferme\Service\FarmService;
use YesWiki\Ferme\Service\StatsPresenter;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;

/**
 * Deleting a farm entry deletes the wiki behind it, which the ordinary confirmation
 * page has no way of knowing. This says what is about to go, and carries out the
 * deletion once the confirmation comes back.
 */
class __DeletePageHandler extends YesWikiHandler
{
    public function run()
    {
        $tag = $this->wiki->GetPageTag();
        $entryManager = $this->wiki->services->get(EntryManager::class);
        if (!$entryManager->isEntry($tag)) {
            return '';
        }

        $entry = $entryManager->getOne($tag);
        $farmId = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');
        if (empty($entry) || (string)($entry['id_typeannonce'] ?? '') !== $farmId) {
            return '';
        }

        $userCanDelete = $this->wiki->UserIsAdmin() || $this->wiki->UserIsOwner();
        if (!empty($_GET['confirme']) && $_GET['confirme'] == 'oui' && $userCanDelete) {
            try {
                if ($this->wiki->services->get(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false)) {
                    $this->wiki->services->get(FarmService::class)->deleteWikiFromEntry($tag);
                }
            } catch (\Throwable $th) {
                exit('No CSRF token'); // do nothing
            }

            return '';
        }

        return $userCanDelete ? $this->warn($tag, $entry) : '';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function warn(string $tag, array $entry): string
    {
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        if ($folder === '') {
            return '';
        }

        $farm = $this->wiki->services->get(FarmService::class);
        $presenter = $this->wiki->services->get(StatsPresenter::class);

        $stored = $this->wiki->services->get(WikiStatsStore::class)->read($folder);
        $fresh = $stored === null ? $this->measureNow($folder) : ['stats' => $stored, 'error' => null];
        $stats = $fresh['stats'];
        $disk = $stats === null ? 0 : (int)($stats['filesBytes'] ?? 0) + (int)($stats['customBytes'] ?? 0) + (int)($stats['privateBytes'] ?? 0);

        return $this->render('@ferme/delete-wiki-warning.twig', [
            'measuredNow' => $stored === null && $stats !== null,
            'unreadable' => $fresh['error'],
            'folder' => $folder,
            'title' => (string)($entry['bf_titre'] ?? $folder),
            'url' => rtrim((string)($this->wiki->config['yeswiki-farm-root-url'] ?? ''), '/') . '/' . $folder . '/',
            'referent' => (string)($entry['bf_referent'] ?? ''),
            'mail' => (string)($entry['bf_mail'] ?? ''),
            'stats' => $stats,
            'disk' => $presenter->size($disk),
            'lastActivity' => $stats === null ? '' : $presenter->age($stats['lastActivity'] ?? null),
            'alsoClaimedBy' => $farm->entriesClaiming($folder, $tag),
        ]);
    }

    /**
     * A wiki created minutes ago has not been measured yet, and "jamais mesuré" is
     * no help to someone about to delete it. One wiki costs a dozen milliseconds,
     * so it is counted here and now. Nothing is stored: it is about to go.
     *
     * @return array{stats:array<string,mixed>|null,error:?string}
     */
    private function measureNow(string $folder): array
    {
        try {
            return ['stats' => $this->wiki->services->get(WikiStats::class)->compute($folder), 'error' => null];
        } catch (WikiStatsException $exception) {
            return ['stats' => null, 'error' => $exception->getReason()];
        } catch (\Throwable $throwable) {
            return ['stats' => null, 'error' => $throwable->getMessage()];
        }
    }
}
