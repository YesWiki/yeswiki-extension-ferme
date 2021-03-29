<?php
// Verification de securite
if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Ferme\Service\FarmService;

$entryManager = $this->services->get(EntryManager::class);
$farm = $this->services->get(FarmService::class);

if ($entryManager->isEntry($this->GetPageTag()) && !empty($_GET['confirme']) && $_GET['confirme'] == 'oui' && ($this->UserIsOwner() || $this->UserIsAdmin())) {
    $tab_valeurs = $entryManager->getOne($this->GetPageTag());
    if (isset($tab_valeurs["bf_dossier-wiki"]) && !empty($tab_valeurs["bf_dossier-wiki"])) {
        $farm->initFarmConfig();
        $src = realpath(getcwd().'/'.(!empty($GLOBALS['wiki']->config['yeswiki-farm-root-folder']) ? $GLOBALS['wiki']->config['yeswiki-farm-root-folder'] : '.').'/'.$tab_valeurs["bf_dossier-wiki"]);
        if (is_dir($src)) {
            // supprimer le wiki
            $farm->rrmdir($src);
            // supprime les tables mysql
            $prefix = empty($tab_valeurs['bf_prefixe']) ?
                $GLOBALS['wiki']->config['yeswiki-farm-prefix'].str_replace('-', '_', $tab_valeurs["bf_dossier-wiki"]) . '__' :
                $tab_valeurs['bf_prefixe'];
            $query = 'DROP TABLE `'.$prefix.'acls`, `'.$prefix.'links`, `'.$prefix.'nature`, `'.$prefix.'pages`, `'.$prefix.'referrers`, `'.$prefix.'triples`, `'.$prefix.'users`;';
            $GLOBALS['wiki']->Query($query);
        }
    }
}
