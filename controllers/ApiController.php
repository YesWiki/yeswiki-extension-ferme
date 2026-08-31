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
     * Upgrade a single wiki using yeswicli.
     *
     * @Route("/api/ferme/wikis/upgrade", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function upgradeWiki(Request $request)
    {
        // Never read this from the query string: YesWiki's router consumes $_GET['wiki'] itself.
        $wikiFolder = trim($request->request->get('folder', ''));

        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        $farmRootFolder = $this->wiki->config['yeswiki-farm-root-folder'] ?? '.';

        $wikiPath = $farmRootFolder === '.'
            ? realpath(getcwd() . DIRECTORY_SEPARATOR . $wikiFolder)
            : realpath(getcwd() . DIRECTORY_SEPARATOR . $farmRootFolder . DIRECTORY_SEPARATOR . $wikiFolder);

        if (!$wikiPath || !is_dir($wikiPath)) {
            return new ApiResponse(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder], Response::HTTP_NOT_FOUND);
        }

        // Prevent path traversal
        $expectedRoot = $farmRootFolder === '.'
            ? realpath(getcwd())
            : realpath(getcwd() . DIRECTORY_SEPARATOR . $farmRootFolder);

        if (!$expectedRoot || strpos($wikiPath, $expectedRoot) !== 0) {
            return new ApiResponse(['success' => false, 'error' => 'Path traversal detected'], Response::HTTP_BAD_REQUEST);
        }

        $yeswicliPath = $wikiPath . DIRECTORY_SEPARATOR . 'yeswicli';
        if (!file_exists($yeswicliPath)) {
            $sourceYeswicli = getcwd() . DIRECTORY_SEPARATOR . 'yeswicli';
            if (!file_exists($sourceYeswicli)) {
                return new ApiResponse(['success' => false, 'error' => 'yeswicli not found in source wiki'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            if (!copy($sourceYeswicli, $yeswicliPath)) {
                return new ApiResponse(['success' => false, 'error' => 'Could not copy yeswicli to wiki: ' . $wikiFolder], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        chmod($yeswicliPath, 0755);
        set_time_limit(300);

        $currentDir = getcwd();
        chdir($wikiPath);
        $output = [];
        $returnCode = 0;
        exec('./yeswicli upgrade 2>&1', $output, $returnCode);
        chdir($currentDir);

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            return new ApiResponse(['success' => false, 'output' => $outputStr, 'error' => 'Command exited with code: ' . $returnCode]);
        }

        return new ApiResponse(['success' => true, 'output' => $outputStr]);
    }

    /**
     * Search the server for wikis not yet in the farm bazar, and import them.
     *
     * @Route("/api/ferme/wikis/search", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function searchWikis(Request $request)
    {
        $adminMail = $this->wiki->GetUser()['email'] ?? '';
        $checkHttp = filter_var($request->request->get('check_http', true), FILTER_VALIDATE_BOOLEAN);
        $result = $this->getService(FarmService::class)->searchWikisOnServer($adminMail, $checkHttp);

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
        $csrfToken = trim($request->request->get('csrf-token', ''));

        if (empty($idFiche)) {
            return new ApiResponse(['success' => false, 'error' => 'Missing id_fiche'], Response::HTTP_BAD_REQUEST);
        }

        // Expose the token in $_POST so CsrfTokenController::checkToken() can find it
        $_POST['csrf-token'] = $csrfToken;

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

    /**
     * Shared entry point for the two admin account routes: validates the folder and the
     * CSRF token, then delegates to the farm service.
     */
    private function runFarmAdminAction(Request $request, bool $add): ApiResponse
    {
        $folder = trim($request->request->get('folder', ''));

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return new ApiResponse(['success' => false, 'error' => 'Invalid wiki folder name'], Response::HTTP_BAD_REQUEST);
        }

        // checkToken reads the raw POST body through filter_input(), so the token has to be
        // sent as a real form field — assigning to $_POST here would not reach it.
        try {
            $this->wiki->services->get(CsrfTokenController::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (\Throwable $th) {
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
     * Display Ferme API documentation.
     */
    public function getDocumentation(): string
    {
        $base = $this->wiki->href('', 'api/ferme/wikis');
        $searchUrl = $this->wiki->href('', 'api/ferme/wikis/search');
        $upgradeUrl = $this->wiki->href('', 'api/ferme/wikis/upgrade');
        $deleteUrl = $this->wiki->href('', 'api/ferme/wikis/delete');
        $adminAddUrl = $this->wiki->href('', 'api/ferme/wikis/admin-add');
        $adminRemUrl = $this->wiki->href('', 'api/ferme/wikis/admin-remove');

        return '<h2>Extension Ferme</h2>'
            . '<p><code>POST ' . $base . '</code> '
            . 'DataTables server-side data for the wikis admin table (admins only).<br>'
            . 'Params: <code>draw</code>, <code>start</code>, <code>length</code>, '
            . '<code>search[value]</code>, <code>order[0][column]</code>, <code>order[0][dir]</code></p>'
            . '<p><code>POST ' . $searchUrl . '</code> '
            . 'Scan the server for wikis not yet in the farm bazar and import them (admins only).<br>'
            . 'Returns: <code>wikisInBazar</code>, <code>wikisOnServer</code>, <code>results[]</code>, <code>imported[]</code></p>'
            . '<p><code>POST ' . $upgradeUrl . '</code> '
            . 'Upgrade a single wiki via <code>yeswicli upgrade</code> (admins only).<br>'
            . 'Params: <code>folder</code> (folder name)</p>'
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
            . 'Params: <code>folder</code> (folder name), <code>csrf-token</code></p>';
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
