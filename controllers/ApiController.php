<?php

namespace YesWiki\Ferme\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use YesWiki\Core\ApiResponse;
use YesWiki\Core\YesWikiController;
use YesWiki\Ferme\Service\FarmService;

class ApiController extends YesWikiController
{
    /**
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

    private function formatRow(array $fiche): array
    {
        $idFiche = $fiche['id_fiche'] ?? '';

        return [
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
