<?php
/**
 * Handler called after the 'update' handler. to install the farm database template and create default pages
 * needed ones.
 *
 * @category YesWiki
 * @package  ferme
 * @author   Adrien Cheype <adrien.cheype@gmail.com>
 * @author   Florian Schmitt <mrflos@lilo.org>
 * @author   Jérémy Dufraisse <jeremy.dufraisse-info@orange.fr>
 * @license  https://www.gnu.org/licenses/agpl-3.0.en.html AGPL 3.0
 * @link     https://yeswiki.net
 */

namespace YesWiki\Ferme;

use Exception;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\YesWikiHandler;
use YesWiki\Ferme\Service\UpdateHandlerService;
use YesWiki\Security\Controller\SecurityController;

class UpdateHandler__ extends YesWikiHandler
{
    public function run()
    {
        if ($this->getService(SecurityController::class)->isWikiHibernated()) {
            throw new Exception(_t('WIKI_IN_HIBERNATION'));
        };
        if (!$this->wiki->UserIsAdmin()) {
            return null;
        }

        $version = $this->params->get('yeswiki_version');
        if (!is_string($version)) {
            $version = '';
        }
        $release = $this->params->get('yeswiki_release');
        if (!is_string($release)) {
            $release = '';
        }
        $matches = [];
        if (
            $version  !== 'doryphore'
            || !preg_match("/^(\d+)\.(\d+)\.(\d+)\$/", $release, $matches)
            || intval($matches[1]) > 4
            || (
                intval($matches[1]) === 4
                && (
                    intval($matches[2]) > 4
                    || (
                        intval($matches[2]) === 4
                        && intval($matches[3]) > 4
                    )
                )
            )
        ) {
            return null;
        }

        $pageManager = $this->getService(PageManager::class);
        $updateHandlerService = $this->getService(UpdateHandlerService::class);

        $messages = [];
        $updateHandlerService->createWikiModelsFolder($messages);
        $updateHandlerService->installListeOuiNon($messages);
        $updateHandlerService->installFarmForm($messages);

        if (empty($pageManager->getOne('AdminWikis'))) {
            $updateHandlerService->updatePageRapideHaut($messages);
        }
        $updateHandlerService->updatePage('AdminWikis', $messages);
        $updateHandlerService->updatePage('AjouterWiki', $messages, ['{FarmFormId}' => $this->params->get('bazar_farm_id')]);
        // $updateHandlerService->updatePage('ContactWikis', $messages);
        $updateHandlerService->updatePage('ModelesWiki', $messages);

        if (!empty($messages)) {
            $message = implode(
                '<br/>',
                array_map(
                    function ($lines) {
                        return implode('<br/>', $lines);
                    },
                    array_column($messages, 'lines')
                )
            );
            $output = <<<HTML
            <strong>Extension Ferme</strong><br/>
            $message<br/>
            <hr/>
            HTML;

            // set output
            $this->output = str_replace(
                '<!-- end handler /update -->',
                $output . '<!-- end handler /update -->',
                $this->output
            );
        }
    }
}
