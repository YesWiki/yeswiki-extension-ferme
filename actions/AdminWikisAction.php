<?php

use YesWiki\Core\Controller\CsrfTokenController;
use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmService;

class AdminWikisAction extends YesWikiAction
{
    public function run()
    {
        $output = '';
        if ($this->wiki->UserIsAdmin()) {
            $farm = $this->getService(FarmService::class);

            if (isset($_GET['maj']) and !empty($_GET['maj'])) {
                try {
                    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $_GET['maj'])) {
                        throw new Exception(_t('FERME_INVALID_FOLDER_NAME') . ' "' . $_GET['maj'] . '"');
                    }
                    if (!$this->getService(CsrfTokenController::class)->checkToken('main', 'GET', 'csrf-token', false)) {
                        throw new Exception(_t('FERME_INVALID_CSRF'));
                    }
                    $farm->updateWiki($_GET['maj']);
                    $output .= $this->render('@templates/alert-message.twig', [
                        'type' => 'success',
                        'message' => _t('FERME_WIKI') . $_GET['maj'] . _t('FERME_UPDATED'),
                    ]);
                } catch (Throwable $th) {
                    $output .= $this->render('@templates/alert-message.twig', [
                        'type' => 'danger',
                        'message' => _t('FERME_WIKI') . $_GET['maj'] . ' : ' . $th->getMessage(),
                    ]);
                }
            }

            return $output . $this->render(
                '@ferme/wikis-table.twig',
                [
                    'api_url' => $this->wiki->href('', 'api/ferme/wikis'),
                    'upgrade_api_url' => $this->wiki->href('', 'api/ferme/wikis/upgrade'),
                    'upgrade_extensions_api_url' => $this->wiki->href('', 'api/ferme/wikis/upgrade-extensions'),
                    'recover_custom_api_url' => $this->wiki->href('', 'api/ferme/wikis/recover-custom'),
                    'refresh_stats_api_url' => $this->wiki->href('', 'api/ferme/wikis/refresh-stats'),
                    'activity_api_url' => $this->wiki->href('', 'api/ferme/wikis/activity'),
                    'csrf_token_api_url' => $this->wiki->href('', 'api/ferme/csrf-token'),
                    'mail_api_url' => $this->wiki->href('', 'api/ferme/wikis/mail'),
                    'import_api_url' => $this->wiki->href('', 'api/ferme/wikis/import'),
                    'delete_api_url' => $this->wiki->href('', 'api/ferme/wikis/delete'),
                    'search_api_url' => $this->wiki->href('', 'api/ferme/wikis/search'),
                    'archive_api_url' => $this->wiki->href('', 'api/ferme/wikis/archive'),
                    'select_api_url' => $this->wiki->href('', 'api/ferme/wikis/select'),
                    'clean_spam_api_url' => $this->wiki->href('', 'api/ferme/wikis/clean-spam'),
                    'spam_pages_api_url' => $this->wiki->href('', 'api/ferme/wikis/spam-pages'),
                    'approve_spam_api_url' => $this->wiki->href('', 'api/ferme/wikis/approve-spam-page'),
                    'hibernate_api_url' => $this->wiki->href('', 'api/ferme/wikis/hibernate'),
                    'wake_api_url' => $this->wiki->href('', 'api/ferme/wikis/wake'),
                    'admin_add_api_url' => $this->wiki->href('', 'api/ferme/wikis/admin-add'),
                    'admin_remove_api_url' => $this->wiki->href('', 'api/ferme/wikis/admin-remove'),
                ]
            );
        }   // User isn't admin

        return '<div class="alert alert-danger">' . _t('FERME_ADMIN_REQUIRED') . '</div>';
    }
}
