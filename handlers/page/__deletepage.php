<?php
// Verification de securite
if (!defined("WIKINI_VERSION")) {
	die("acc&egrave;s direct interdit");
}

initFarmConfig();
$type = $this->GetTripleValue($this->GetPageTag(), 'http://outils-reseaux.org/_vocabulary/type', '', '');
if ($type == 'fiche_bazar' && $_GET['confirme'] == 'oui' && ($this->UserIsOwner() || $this->UserIsAdmin())) {
	$tab_valeurs = baz_valeurs_fiche($this->GetPageTag());
	if (isset($tab_valeurs["bf_dossier-wiki"]) && !empty($tab_valeurs["bf_dossier-wiki"])) {
		$src = realpath(getcwd().'/'.(!empty($GLOBALS['wiki']->config['yeswiki-farm-root-folder']) ? $GLOBALS['wiki']->config['yeswiki-farm-root-folder'] : '.').'/'.$tab_valeurs["bf_dossier-wiki"]);
		if (is_dir($src)) {
			// supprimer le wiki
			rrmdir($src);
			// supprime les tables mysql
			$prefix = $GLOBALS['wiki']->config['yeswiki-farm-prefix'].str_replace('-', '_', $tab_valeurs["bf_dossier-wiki"]);
			$query = 'DROP TABLE `'.$prefix.'__acls`, `'.$prefix.'__links`, `'.$prefix.'__nature`, `'.$prefix.'__pages`, `'.$prefix.'__referrers`, `'.$prefix.'__triples`, `'.$prefix.'__users`;';
			$GLOBALS['wiki']->Query($query);
		}
	}
}
