<?php
// Verification de securite
use YesWiki\Bazar\Service\FicheManager;

if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}

$ficheManager = $this->services->get(FicheManager::class);

if ($ficheManager->isFiche($this->GetPageTag()) && !empty($_GET['confirme']) && $_GET['confirme'] == 'oui' && ($this->UserIsOwner() || $this->UserIsAdmin())) {
    $tab_valeurs = $ficheManager->getOne($this->GetPageTag());
    if (isset($tab_valeurs["bf_dossier-wiki"]) && !empty($tab_valeurs["bf_dossier-wiki"])) {
        initFarmConfig();
        $src = realpath(getcwd().'/'.(!empty($GLOBALS['wiki']->config['yeswiki-farm-root-folder']) ? $GLOBALS['wiki']->config['yeswiki-farm-root-folder'] : '.').'/'.$tab_valeurs["bf_dossier-wiki"]);
        if (is_dir($src)) {
            // supprimer le wiki
            rrmdir($src);
            // supprime les tables mysql
            $prefix = empty($tab_valeurs['bf_prefixe']) ?
                $GLOBALS['wiki']->config['yeswiki-farm-prefix'].str_replace('-', '_', $tab_valeurs["bf_dossier-wiki"]) . '__' :
                $tab_valeurs['bf_prefixe'];
            $query = 'DROP TABLE `'.$prefix.'acls`, `'.$prefix.'links`, `'.$prefix.'nature`, `'.$prefix.'pages`, `'.$prefix.'referrers`, `'.$prefix.'triples`, `'.$prefix.'users`;';
            $GLOBALS['wiki']->Query($query);
        }
    }
}
