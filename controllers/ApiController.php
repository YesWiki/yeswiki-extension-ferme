<?php

namespace YesWiki\Ferme\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use YesWiki\Core\ApiResponse;
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
        $draw     = intval($request->request->get('draw', 1));
        $start    = max(0, intval($request->request->get('start', 0)));
        $length   = min(500, max(1, intval($request->request->get('length', 100))));
        $search   = trim($request->request->all('search')['value'] ?? '');
        $order    = $request->request->all('order');
        $orderCol = intval($order[0]['column'] ?? 1);
        $orderDir = (($order[0]['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';

        $farm   = $this->getService(FarmService::class);
        $result = $farm->getWikiListPaginated($start, $length, $search, $orderCol, $orderDir);

        $rows = [];
        foreach ($result['fiches'] as $fiche) {
            $rows[] = $this->formatRow($fiche);
        }

        return new ApiResponse([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => $rows,
        ]);
    }

    /**
     * Upgrade a single wiki using yeswicli.
     *
     * @Route("/api/ferme/wikis/upgrade", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function upgradeWiki(Request $request)
    {
        $wikiFolder = trim($request->request->get('wiki', ''));

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
        $output     = [];
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
     * Delete a single wiki (folder + DB tables + bazar entry).
     *
     * @Route("/api/ferme/wikis/delete", methods={"POST"}, options={"acl":{"@admins"}})
     */
    public function deleteWiki(Request $request)
    {
        $idFiche   = trim($request->request->get('id_fiche', ''));
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
     * Display Ferme API documentation.
     *
     * @return string
     */
    public function getDocumentation(): string
    {
        $base        = $this->wiki->href('', 'api/ferme/wikis');
        $upgradeUrl  = $this->wiki->href('', 'api/ferme/wikis/upgrade');
        $deleteUrl   = $this->wiki->href('', 'api/ferme/wikis/delete');

        return '<h2>Extension Ferme</h2>'
            . '<p><code>POST ' . $base . '</code> '
            . 'DataTables server-side data for the wikis admin table (admins only).<br>'
            . 'Params: <code>draw</code>, <code>start</code>, <code>length</code>, '
            . '<code>search[value]</code>, <code>order[0][column]</code>, <code>order[0][dir]</code></p>'
            . '<p><code>POST ' . $upgradeUrl . '</code> '
            . 'Upgrade a single wiki via <code>yeswicli upgrade</code> (admins only).<br>'
            . 'Params: <code>wiki</code> (folder name)</p>'
            . '<p><code>POST ' . $deleteUrl . '</code> '
            . 'Delete a wiki — removes folder, DB tables and bazar entry (admins only).<br>'
            . 'Params: <code>id_fiche</code> (page tag), <code>csrf-token</code></p>';
    }

    private function formatRow(array $fiche): array
    {
        $idFiche = $fiche['id_fiche'] ?? '';

        return [
            'id_fiche'              => $idFiche,
            'folder'                => $fiche['bf_dossier-wiki'] ?? '',
            'title'                 => $fiche['bf_titre'] ?? '',
            'description'           => $fiche['bf_description'] ?? '',
            'url'                   => $fiche['url'] ?? '',
            'referent'              => $fiche['bf_referent'] ?? '',
            'mail'                  => $fiche['bf_mail'] ?? '',
            'last_modification'     => $fiche['last_modification'] ?? '',
            'last_modification_iso' => $fiche['last_modification_iso'] ?? '',
            'dashboard_link'        => $fiche['dashboard_link'] ?? '',
            'admin'                 => $fiche['admin'] ?? '',
            'version'               => $fiche['version'] ?? '',
            'view_url'              => $this->wiki->href('', $idFiche),
            'edit_url'              => $this->wiki->href('edit', $idFiche),
            'delete_url'            => $this->wiki->href('deletepage', $idFiche),
            'error'                 => $fiche['error'] ?? null,
        ];
    }
}
