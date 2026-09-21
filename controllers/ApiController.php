<?php

namespace YesWiki\Ferme\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use YesWiki\Core\ApiResponse;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\YesWikiController;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\FarmService;
use YesWiki\Ferme\Service\StatsPresenter;

class ApiController extends YesWikiController
{
    public const MAX_PER_BATCH = 25;

    /**
     * Display Ferme API documentation.
     *
     * @Route("/api/ferme", options={"acl":{"public"}})
     * @Route("/api/ferme/", options={"acl":{"public"}})
     */
    public function onlineDoc()
    {
        $output = $this->wiki->Header() . $this->getDocumentation() . $this->wiki->Footer();

        return new Response($output);
    }

    /**
     * DataTables server-side data for the wikis admin table.
     *
     * @Route("/api/ferme/wikis", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function getWikisTable(Request $request)
    {
        $draw = intval($request->request->get('draw', 1));
        $start = max(0, intval($request->request->get('start', 0)));
        $length = min(500, max(1, intval($request->request->get('length', 100))));
        $search = trim($request->request->all('search')['value'] ?? '');
        $sort = (string)$request->request->get('sort', 'title');
        $direction = $request->request->get('direction') === 'desc' ? 'desc' : 'asc';
        $filter = (string)$request->request->get('filter', '');

        $farm = $this->getService(FarmService::class);
        $result = $farm->getWikiListPaginated($start, $length, $search, $sort, $direction, $filter);

        $rows = [];
        foreach ($result['fiches'] as $fiche) {
            $rows[] = $this->formatRow($fiche);
        }

        return new ApiResponse([
            'draw' => $draw,
            'recordsTotal' => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data' => $rows,
            'counts' => $result['counts'],
            'totals' => $this->formatTotals($result['totals']),
        ]);
    }

    /**
     * Upgrade a single wiki to the state of the farm master.
     *
     * @Route("/api/ferme/wikis/upgrade", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function upgradeWiki(Request $request)
    {
        $wikiFolder = trim($request->request->get('folder', ''));

        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $farm = $this->getService(FarmService::class);
        if (!is_dir($farm->getWikiPath($wikiFolder))) {
            return new ApiResponse(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder], Response::HTTP_NOT_FOUND);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $result = $farm->updateWiki($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => implode("\n", $result['messages'])]);
    }

    /**
     * Bring one wiki's own extensions up, without touching its core.
     *
     * @Route("/api/ferme/wikis/upgrade-extensions", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function upgradeWikiExtensions(Request $request)
    {
        $wikiFolder = trim($request->request->get('folder', ''));

        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $farm = $this->getService(FarmService::class);
        if (!is_dir($farm->getWikiPath($wikiFolder))) {
            return new ApiResponse(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder], Response::HTTP_NOT_FOUND);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $result = $farm->updateWikiExtensions($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => implode("\n", $result['messages'])]);
    }

    /**
     * Put back the custom/ folder an interrupted update left aside.
     *
     * @Route("/api/ferme/wikis/recover-custom", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function recoverWikiCustom(Request $request)
    {
        $wikiFolder = trim($request->request->get('folder', ''));

        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $farm = $this->getService(FarmService::class);
        if (!is_dir($farm->getWikiPath($wikiFolder))) {
            return new ApiResponse(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $farm->recoverWikiCustom($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => implode("\n", $result['messages'])]);
    }

    /**
     * Measure one wiki again, now, without waiting for the nightly run.
     *
     * @Route("/api/ferme/wikis/refresh-stats", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function refreshWikiStats(Request $request)
    {
        $wikiFolder = $this->askedFolder($request);
        if (!is_string($wikiFolder)) {
            return $wikiFolder;
        }

        set_time_limit(0);

        try {
            $result = $this->getService(FarmService::class)->refreshWikiStats($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => implode("\n", $result['messages'])]);
    }

    /**
     * A year of one wiki's edits, day by day, drawn on the spot.
     *
     * @Route("/api/ferme/wikis/activity", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function wikiActivity(Request $request)
    {
        $wikiFolder = $this->askedFolder($request);
        if (!is_string($wikiFolder)) {
            return $wikiFolder;
        }

        try {
            $edits = $this->getService(FarmService::class)->wikiActivity($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        $presenter = $this->getService(StatsPresenter::class);

        return new ApiResponse([
            'success' => true,
            'title' => $presenter->calendarTitle(),
            'calendar' => $presenter->calendar($edits),
        ]);
    }

    /**
     * The folder a request names, or the answer to send back when it names none
     * we can act on.
     *
     * @return string|ApiResponse
     */
    private function askedFolder(Request $request)
    {
        $wikiFolder = trim($request->request->get('folder', ''));

        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }
        if (!is_dir($this->getService(FarmService::class)->getWikiPath($wikiFolder))) {
            return new ApiResponse(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder], Response::HTTP_NOT_FOUND);
        }

        return $wikiFolder;
    }

    /**
     * Write to the person who runs one wiki of the farm.
     *
     * @Route("/api/ferme/wikis/mail", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function mailWikiReferent(Request $request)
    {
        $idFiche = trim($request->request->get('id_fiche', ''));
        if (empty($idFiche)) {
            return new ApiResponse(['success' => false, 'error' => 'Missing id_fiche'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        try {
            $address = $this->getService(FarmMailer::class)->sendToReferent(
                $idFiche,
                (string)$request->request->get('subject', ''),
                (string)$request->request->get('body', '')
            );
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => _t('FERME_MAIL_SENT_TO') . ' ' . $address]);
    }

    /**
     * The session's current token, for a page that has been open long enough for
     * its own to have gone stale. Same origin only, and admins only, so this hands
     * the token to whoever could already read it off the page.
     *
     * @Route("/api/ferme/csrf-token", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function freshCsrfToken()
    {
        return new ApiResponse([
            'success' => true,
            'token' => $this->getService(CsrfTokenManager::class)->getToken('main')->getValue(),
        ]);
    }

    /**
     * Search the server for wikis not yet in the farm bazar, and import them.
     *
     * @Route("/api/ferme/wikis/search", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function searchWikis(Request $request)
    {
        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        set_time_limit(0);

        return new ApiResponse($this->getService(FarmService::class)->searchWikisOnServer());
    }

    /**
     * Give a farm entry to the wikis the operator picked in that list.
     *
     * @Route("/api/ferme/wikis/import", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function importWikis(Request $request)
    {
        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $folders = $request->request->all('folders');
        if (empty($folders) || !is_array($folders)) {
            return new ApiResponse(['success' => false, 'error' => 'No folder given'], Response::HTTP_BAD_REQUEST);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $imported = $this->getService(FarmService::class)->importWikiFolders(
                $folders,
                $this->wiki->GetUser()['email'] ?? ''
            );
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse([
            'success' => true,
            'imported' => $imported,
            'output' => count($imported) . ' ' . _t('FERME_SEARCH_IMPORTED_STATUS'),
        ]);
    }

    /**
     * Delete wikis (folder + DB tables + bazar entry), one or a batch of them.
     *
     * The session is closed as soon as the caller is known: PHP holds its file
     * locked for the whole request, and the browser sends several of these at once.
     *
     * @Route("/api/ferme/wikis/delete", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function deleteWiki(Request $request)
    {
        $asked = $request->request->all('id_fiches');
        $idFiches = is_array($asked) ? array_values(array_filter(array_map('trim', $asked))) : [];
        if ($idFiches === []) {
            $single = trim($request->request->get('id_fiche', ''));
            $idFiches = $single === '' ? [] : [$single];
        }

        if ($idFiches === []) {
            return new ApiResponse(['success' => false, 'error' => 'Missing id_fiche'], Response::HTTP_BAD_REQUEST);
        }
        if (count($idFiches) > self::MAX_PER_BATCH) {
            return new ApiResponse(['success' => false, 'error' => 'Too many entries at once'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $started = microtime(true);
        $results = $this->getService(FarmService::class)->deleteWikisForApi($idFiches);
        $elapsed = microtime(true) - $started;

        if (count($idFiches) === 1 && !$request->request->has('id_fiches')) {
            return new ApiResponse($results[0]);
        }

        return new ApiResponse([
            'success' => true,
            'results' => $results,
            'seconds' => round($elapsed, 2),
        ]);
    }

    /**
     * Add the farm super-admin account to a single wiki.
     *
     * @Route("/api/ferme/wikis/admin-add", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function addFarmAdmin(Request $request)
    {
        return $this->runFarmAdminAction($request, true);
    }

    /**
     * Remove the farm super-admin account from a single wiki.
     *
     * @Route("/api/ferme/wikis/admin-remove", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function removeFarmAdmin(Request $request)
    {
        return $this->runFarmAdminAction($request, false);
    }

    private function runFarmAdminAction(Request $request, bool $add): ApiResponse
    {
        $folder = trim($request->request->get('folder', ''));

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $farm = $this->getService(FarmService::class);
        $result = $add ? $farm->addFarmAdmin($folder) : $farm->removeFarmAdmin($folder);

        if (!empty($result['errors'])) {
            return new ApiResponse(['success' => false, 'error' => implode(' ', $result['errors'])], Response::HTTP_BAD_REQUEST);
        }

        return new ApiResponse(['success' => true]);
    }

    /**
     * Walk the fetch of a model's files and custom folders one step further.
     *
     * The source wiki takes minutes to make its backup and this end takes minutes to
     * download it, so the browser drives the job the way the core backup screen does,
     * one short request at a time.
     *
     * @Route("/api/ferme/models/assets", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function modelAssets(Request $request)
    {
        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $action = trim($request->request->get('action', 'status'));
        if (!in_array($action, ['status', 'cancel'], true)) {
            return new ApiResponse(['success' => false, 'error' => 'Unsupported action: ' . $action], Response::HTTP_BAD_REQUEST);
        }

        // one download slice, or the unpacking of the whole backup, fits well inside this
        set_time_limit(300);
        if (session_status() === PHP_SESSION_ACTIVE) {
            // the job takes its time; holding the session lock would freeze the whole browser
            session_write_close();
        }

        $farm = $this->getService(FarmService::class);
        try {
            $result = $action === 'cancel' ? $farm->cancelModelAssets() : $farm->advanceModelAssets();
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'running' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse([
            'success' => true,
            'running' => $result['running'],
            'step' => $result['state']['step'] ?? '',
            'bytes' => $result['state']['bytes'] ?? 0,
            'total' => $result['state']['total'] ?? 0,
            'messages' => $result['messages'],
        ]);
    }

    private function tokenIsValid(): bool
    {
        try {
            return $this->getService(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function getDocumentation(): string
    {
        $base = $this->wiki->href('', 'api/ferme/wikis');
        $searchUrl = $this->wiki->href('', 'api/ferme/wikis/search');
        $upgradeUrl = $this->wiki->href('', 'api/ferme/wikis/upgrade');
        $deleteUrl = $this->wiki->href('', 'api/ferme/wikis/delete');
        $adminAddUrl = $this->wiki->href('', 'api/ferme/wikis/admin-add');
        $adminRemUrl = $this->wiki->href('', 'api/ferme/wikis/admin-remove');
        $modelAssetsUrl = $this->wiki->href('', 'api/ferme/models/assets');

        return '<h2>Extension Ferme</h2>'
            . '<p><code>POST ' . $base . '</code> '
            . 'DataTables server-side data for the wikis admin table (admins only).<br>'
            . 'Params: <code>draw</code>, <code>start</code>, <code>length</code>, '
            . '<code>search[value]</code>, <code>order[0][column]</code>, <code>order[0][dir]</code></p>'
            . '<p><code>POST ' . $searchUrl . '</code> '
            . 'Scan the server for wikis not yet in the farm bazar and import them (admins only).<br>'
            . 'Params: <code>csrf-token</code><br>'
            . 'Returns: <code>wikisInBazar</code>, <code>wikisOnServer</code>, <code>results[]</code>, <code>imported[]</code></p>'
            . '<p><code>POST ' . $upgradeUrl . '</code> '
            . 'Upgrade a single wiki to the state of the farm master (admins only).<br>'
            . 'Params: <code>folder</code> (folder name), <code>csrf-token</code></p>'
            . '<p><code>POST ' . $deleteUrl . '</code> '
            . 'Delete a wiki — removes folder, DB tables and bazar entry (admins only).<br>'
            . 'Params: <code>id_fiche</code> (page tag), <code>csrf-token</code></p>'
            . '<p><code>POST ' . $adminAddUrl . '</code> '
            . 'Create or refresh the farm super-admin account on a wiki, and add it to its '
            . '<code>@admins</code> group. Run on a wiki that already has the account, it resets '
            . 'the password from the farm config (admins only).<br>'
            . 'Params: <code>folder</code> (folder name), <code>csrf-token</code></p>'
            . '<p><code>POST ' . $adminRemUrl . '</code> '
            . 'Delete the farm super-admin account from a wiki and drop it from its '
            . '<code>@admins</code> group (admins only).<br>'
            . 'Params: <code>folder</code> (folder name), <code>csrf-token</code></p>'
            . '<p><code>POST ' . $modelAssetsUrl . '</code> '
            . 'Walk the fetch of a wiki model\'s files and custom folders one step further. '
            . 'The generate-model screen starts the fetch and its javascript polls this until '
            . 'it stops running (admins only).<br>'
            . 'Params: <code>action</code> (<code>status</code> or <code>cancel</code>), <code>csrf-token</code><br>'
            . 'Returns: <code>running</code>, <code>step</code>, <code>bytes</code>, <code>total</code>, '
            . '<code>messages[]</code>, <code>error</code></p>';
    }

    private function formatRow(array $fiche): array
    {
        $idFiche = $fiche['id_fiche'] ?? '';

        return [
            'id_fiche' => $idFiche,
            'folder' => $fiche['bf_dossier-wiki'] ?? '',
            'title' => $fiche['bf_titre'] ?? '',
            'description' => $fiche['bf_description'] ?? '',
            'url' => $fiche['url'] ?? '',
            'referent' => $fiche['bf_referent'] ?? '',
            'mail' => $fiche['bf_mail'] ?? '',
            'last_modification' => $fiche['last_modification'] ?? '',
            'last_modification_iso' => $fiche['last_modification_iso'] ?? '',
            'dashboard_link' => $fiche['dashboard_link'] ?? '',
            'admin' => $this->describeAdmin($fiche['admin'] ?? null),
            'version' => $this->describeVersion($fiche['version'] ?? []),
            'view_url' => $this->wiki->href('', $idFiche),
            'edit_url' => $this->wiki->href('edit', $idFiche),
            'delete_url' => $this->wiki->href('deletepage', $idFiche),
            'error' => isset($fiche['error'])
                ? '<div class="alert alert-danger">' . htmlspecialchars($fiche['error']) . '</div>'
                : null,
            'custom_warning' => empty($fiche['custom_aside'])
                ? null
                : '<div><span class="label label-warning"><i class="fas fa-exclamation-triangle"></i> '
                    . htmlspecialchars(_t('FERME_CUSTOM_BROKEN')) . '</span></div>',
            'stats' => $this->formatStats($fiche['stats'] ?? null),
            'problems' => $this->formatProblems($fiche['problems'] ?? []),
        ];
    }

    /**
     * What a row shows of a wiki's numbers. A wiki nobody measured returns null,
     * and the page shows dashes rather than zeros.
     *
     * @param array<string,mixed>|null $stats
     *
     * @return array<string,mixed>|null
     */
    private function formatStats(?array $stats): ?array
    {
        if ($stats === null) {
            return null;
        }

        $presenter = $this->getService(StatsPresenter::class);
        $disk = (int)($stats['diskBytes'] ?? 0);

        return [
            'users' => (int)($stats['users'] ?? 0),
            'forms' => (int)($stats['forms'] ?? 0),
            'entries' => (int)($stats['entries'] ?? 0),
            'pages' => (int)($stats['pages'] ?? 0),
            'files' => (int)($stats['files'] ?? 0),
            'disk' => $presenter->size($disk),
            'disk_bytes' => $disk,
            'disk_detail' => _t('FERME_STATS_DISK_DETAIL', [
                'files' => $presenter->size((int)($stats['filesBytes'] ?? 0)),
                'custom' => $presenter->size((int)($stats['customBytes'] ?? 0)),
                'private' => $presenter->size((int)($stats['privateBytes'] ?? 0)),
            ]),
            'private' => $presenter->size((int)($stats['privateBytes'] ?? 0)),
            'heavy_archives' => !empty($stats['heavyArchives']),
            'dormant' => !empty($stats['dormant']),
            'suspect' => !empty($stats['suspect']),
            'suspect_why' => array_map(function (string $reason) {
                return _t('FERME_SPAM_' . strtoupper($reason));
            }, (array)($stats['suspectWhy'] ?? [])),
            'to_update' => !empty($stats['toUpdate']),
            'failed' => !empty($stats['failed']),
            'error' => $stats['error'] ?? null,
            'last_activity' => $stats['lastActivity'] ?? null,
            'last_activity_age' => $presenter->age($stats['lastActivity'] ?? null),
            'computed_at' => $stats['computedAt'] ?? null,
            'computed_age' => $presenter->age($stats['computedAt'] ?? null),
            'sparkline' => $presenter->sparkline($stats['activity'] ?? []),
        ];
    }

    /**
     * What is wrong with a farm entry itself, whatever its wiki holds.
     *
     * @param array<string,bool> $problems
     *
     * @return array<int,array<string,string>>
     */
    private function formatProblems(array $problems): array
    {
        $said = [
            'noFolder' => 'FERME_PROBLEM_NO_FOLDER',
            'missingWiki' => 'FERME_PROBLEM_MISSING_WIKI',
            'duplicateFolder' => 'FERME_PROBLEM_DUPLICATE_FOLDER',
        ];

        $found = [];
        foreach ($said as $problem => $key) {
            if (!empty($problems[$problem])) {
                $found[] = ['kind' => $problem, 'label' => _t($key)];
            }
        }

        return $found;
    }

    /**
     * @param array<string,int> $totals
     *
     * @return array<string,string|int>
     */
    private function formatTotals(array $totals): array
    {
        $totals['disk'] = $this->getService(StatsPresenter::class)->size((int)($totals['diskBytes'] ?? 0));

        return $totals;
    }

    /**
     * @param array<string,mixed> $version as WikiRepository describes it
     *
     * @return array<string,mixed>|null
     */
    private function describeVersion(array $version): ?array
    {
        if (empty($version)) {
            return null;
        }

        return [
            'name' => (string)($version['version'] ?? ''),
            'release' => (string)($version['release'] ?? ''),
            'status' => (string)($version['status'] ?? ''),
            'update_url' => $version['update_url'] ?? '',
            'source_version' => (string)($version['source_version'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed>|null $admin
     *
     * @return array<string,mixed>|null
     */
    private function describeAdmin(?array $admin): ?array
    {
        if (empty($admin)) {
            return null;
        }

        return [
            'name' => (string)$admin['name'],
            'folder' => (string)($admin['folder'] ?? ''),
            'present' => !empty($admin['present']),
        ];
    }
}
