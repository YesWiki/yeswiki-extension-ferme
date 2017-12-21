<?php
// Verification de securite
if (!defined("WIKINI_VERSION")) {
    die("acc&egrave;s direct interdit");
}
$type = $this->GetTripleValue($this->GetPageTag(), 'http://outils-reseaux.org/_vocabulary/type', '', '');
if ($type == 'fiche_bazar' && $_GET['confirme'] == 'oui' && ($this->UserIsOwner() || $this->UserIsAdmin())) {
    $tab_valeurs = baz_valeurs_fiche($this->GetPageTag());
    if (isset($tab_valeurs["bf_dossier-wiki"]) && !empty($tab_valeurs["bf_dossier-wiki"])) {
        $src = $GLOBALS['wiki']->config['yeswiki-farm-root-folder'].'/'.$tab_valeurs["bf_dossier-wiki"];
        if (is_dir($src)) {
            // supprimer le wiki
            rrmdir($src);
            // supprime les tables mysql
            $prefix = 'yeswiki_'.str_replace('-', '_', $tab_valeurs["bf_dossier-wiki"]).'__';
            $GLOBALS['wiki']->Query('DROP TABLE `'.$prefix.'acls`, `'.$prefix.'links`, `'.$prefix.'nature`, `'.$prefix.'pages`, `'.$prefix.'referrers`, `'.$prefix.'triples`, `'.$prefix.'users`;');
        }
    }
}
