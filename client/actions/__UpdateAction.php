<?php

namespace YesWiki\FermeClient;

use YesWiki\Core\YesWikiAction;

/**
 * Refuses, on a wiki whose code is lent by its farm, the updates that would write
 * through the links and rewrite that code for every other wiki sharing it. What the
 * wiki installed for itself is a folder of its own, and stays its own business.
 */
class __UpdateAction extends YesWikiAction
{
    private const CORE_MARKERS = ['javascripts', 'vendor', 'includes'];

    public function run()
    {
        $action = $_GET['action'] ?? '';
        if (!in_array($action, ['upgrade', 'delete'], true)) {
            return '';
        }

        $package = (string)($_GET['package'] ?? '');
        if (!$this->isLent($package)) {
            return '';
        }

        unset($_GET['action'], $_GET['package']);

        return '<div class="alert alert-danger"><i class="fas fa-link"></i> '
            . _t('FERME_CLIENT_UPDATE_REFUSED') . '</div>';
    }

    /**
     * A package the farm lends: the core when the code folders are links, or an
     * extension or theme whose own folder is one.
     */
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
