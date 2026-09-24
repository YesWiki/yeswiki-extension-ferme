<?php

namespace YesWiki\FermeClient;

use YesWiki\Core\YesWikiAction;

/** Refuses the updates that would rewrite code lent by the farm, and a farm installed inside it. */
class __UpdateAction extends YesWikiAction
{
    private const CORE_MARKERS = ['javascripts', 'vendor', 'includes'];
    private const FARM_PACKAGE = 'ferme';

    public function run()
    {
        $action = $_GET['action'] ?? '';
        if (!in_array($action, ['upgrade', 'delete'], true)) {
            return '';
        }

        $package = (string)($_GET['package'] ?? '');
        if ($action === 'upgrade' && $this->isNewFarm($package)) {
            return $this->refuse('FERME_CLIENT_FARM_REFUSED');
        }
        if (!$this->isLent($package)) {
            return '';
        }

        return $this->refuse('FERME_CLIENT_UPDATE_REFUSED');
    }

    private function refuse(string $message): string
    {
        unset($_GET['action'], $_GET['package']);

        return '<div class="alert alert-danger"><i class="fas fa-link"></i> '
            . _t($message) . '</div>';
    }

    /** A farm inside a farm's wiki: only the ones already there may still be updated. */
    private function isNewFarm(string $package): bool
    {
        return $package === self::FARM_PACKAGE && !is_dir('tools/' . self::FARM_PACKAGE);
    }

    /** The core when its code folders are links, or an extension or theme whose folder is one. */
    private function isLent(string $package): bool
    {
        if ($package === '' || $package === $this->wiki->config['yeswiki_version']) {
            foreach (self::CORE_MARKERS as $folder) {
                if (is_link($folder)) {
                    return true;
                }
            }

            return false;
        }

        return is_link('tools/' . $package) || is_link('themes/' . $package);
    }
}
