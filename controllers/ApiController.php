<?php

namespace YesWiki\Ferme\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use YesWiki\Core\ApiResponse;
use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\YesWikiController;
use YesWiki\Ferme\Service\FarmService;

class ApiController extends YesWikiController
{
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
        $order = $request->request->all('order');
        $orderCol = intval($order[0]['column'] ?? 1);
        $orderDir = (($order[0]['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';

        $farm = $this->getService(FarmService::class);
        $result = $farm->getWikiListPaginated($start, $length, $search, $orderCol, $orderDir);

        $rows = [];
        foreach ($result['fiches'] as $fiche) {
            $rows[] = $this->formatRow($fiche);
        }

        return new ApiResponse([
            'draw' => $draw,
            'recordsTotal' => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data' => $rows,
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

        set_time_limit(300);

        try {
            $result = $farm->updateWiki($wikiFolder);
        } catch (\Throwable $th) {
            return new ApiResponse(['success' => false, 'error' => $th->getMessage()]);
        }

        return new ApiResponse(['success' => true, 'output' => implode("\n", $result['messages'])]);
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

        $adminMail = $this->wiki->GetUser()['email'] ?? '';
        $result = $this->getService(FarmService::class)->searchWikisOnServer($adminMail);

        return new ApiResponse($result);
    }

    /**
     * Delete a single wiki (folder + DB tables + bazar entry).
     *
     * @Route("/api/ferme/wikis/delete", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function deleteWiki(Request $request)
    {
        $idFiche = trim($request->request->get('id_fiche', ''));

        if (empty($idFiche)) {
            return new ApiResponse(['success' => false, 'error' => 'Missing id_fiche'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenIsValid()) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $result = $this->getService(FarmService::class)->deleteWikiForApi($idFiche);

        return new ApiResponse($result);
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
            'admin' => $this->formatAdmin($fiche['admin'] ?? null),
            'version' => $this->formatVersion($fiche['version'] ?? []),
            'view_url' => $this->wiki->href('', $idFiche),
            'edit_url' => $this->wiki->href('edit', $idFiche),
            'delete_url' => $this->wiki->href('deletepage', $idFiche),
            'error' => isset($fiche['error'])
                ? '<div class="alert alert-danger">' . htmlspecialchars($fiche['error']) . '</div>'
                : null,
        ];
    }

    private function formatVersion(array $version): string
    {
        if (empty($version)) {
            return '';
        }

        $wikiVersion = $version['version'] ?? '';
        $wikiRelease = $version['release'] ?? '';

        $text = ($wikiVersion ?: '')
            . (!empty($wikiVersion) ? '<br />' : '')
            . ($wikiRelease ?: 'Inconnue');

        switch ($version['status'] ?? '') {
            case 'different':
                $text .= '<br /><i>' . _t('FERME_VERSION_DIFFERENT') . '</i>';
                break;
            case 'outdated':
                $text .= '<br /><a class="btn btn-xs btn-danger" href="' . htmlspecialchars($version['update_url'] ?? '') . '">'
                    . _t('FERME_UPDATE_TO') . ' ' . htmlspecialchars($version['source_version'] ?? '') . '</a>';
                break;
            case 'up-to-date':
                $text .= '<br /><i>' . _t('FERME_VERSION_UP_TO_DATE') . '</i>';
                break;
        }

        return $text;
    }

    private function formatAdmin(?array $admin): string
    {
        if (empty($admin)) {
            return '';
        }

        $name = htmlspecialchars($admin['name']);
        $folder = htmlspecialchars($admin['folder'] ?? '');

        if ($admin['present']) {
            return $name . ' ' . _t('FERME_ADMIN_PRESENT')
                . ' <button class="btn btn-xs btn-danger admin-action-btn"'
                . ' data-admin-action="remove" data-admin-wiki="' . $folder . '">'
                . _t('FERME_ADMIN_REMOVE_ACCOUNT') . '</button>';
        }

        return $name . ' ' . _t('FERME_ADMIN_ABSENT')
            . ' <button class="btn btn-xs btn-success admin-action-btn"'
            . ' data-admin-action="add" data-admin-wiki="' . $folder . '">'
            . _t('FERME_ADMIN_ADD_ACCOUNT') . '</button>';
    }
}
