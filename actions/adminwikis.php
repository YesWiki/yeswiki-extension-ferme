<?php
if (!defined("WIKINI_VERSION")) {
	die("acc&egrave;s direct interdit");
}

if ($this->UserIsAdmin()) {
	echo '<h1>'._t('FERME_ALL_WIKIS_ADMIN').'</h1>';
	initFarmConfig();
	$mainWikiVersion = $this->config['yeswiki_release'];
	$wakkaConfig = [];

	if (isset($_GET['superadmin']) and !empty($_GET['superadmin'])) {
		if ($this->config['yeswiki-farm-root-folder'] == '.') {
			$path = getcwd().DIRECTORY_SEPARATOR.$_GET['superadmin'].'/wakka.config.php';
		} else {
			$path = getcwd().DIRECTORY_SEPARATOR
				.$this->config['yeswiki-farm-root-folder'].DIRECTORY_SEPARATOR
				.$_GET['superadmin'].'/wakka.config.php';
		}
		include_once realpath($path);
		if (!empty($wakkaConfig['table_prefix'])) {
			$sql = 'SELECT value FROM `'.$wakkaConfig['table_prefix'].'triples` WHERE resource = "ThisWikiGroup:admins";';
			$list = $this->LoadSingle($sql);
			$list = explode("\n", $list['value']);
			if (!in_array($this->config['yeswiki-farm-admin-name'], $list)) {
				$list[] = $this->config['yeswiki-farm-admin-name'];
			}
			$list = array_map('trim', $list);
			$list = implode("\n", $list);
			$sql = 'UPDATE `'.$wakkaConfig['table_prefix'].'triples` SET value="'.addslashes($list).'" WHERE resource = "ThisWikiGroup:admins";';
			$this->Query($sql);

			$sql = 'INSERT INTO `'.$wakkaConfig['table_prefix'].'users` (`name`, `password`, `email`, `motto`, `revisioncount`, `changescount`, `doubleclickedit`, `signuptime`, `show_comments`) VALUES (\''.$this->config['yeswiki-farm-admin-name'].'\', MD5(\''.$this->config['yeswiki-farm-admin-pass'].'\'), \'\', \'\', \'20\', \'50\', \'Y\', NOW(), \'N\')';
			$this->Query($sql);
		}
	} elseif (isset($_GET['nosuperadmin']) and !empty($_GET['nosuperadmin'])) {
		if ($this->config['yeswiki-farm-root-folder'] == '.') {
			$path = getcwd().DIRECTORY_SEPARATOR.$_GET['nosuperadmin'].'/wakka.config.php';
		} else {
			$path = getcwd().DIRECTORY_SEPARATOR
				.$this->config['yeswiki-farm-root-folder'].DIRECTORY_SEPARATOR
				.$_GET['nosuperadmin'].'/wakka.config.php';
		}
		include_once realpath($path);
		if (!empty($wakkaConfig['table_prefix'])) {
			$sql = 'SELECT value FROM `'.$wakkaConfig['table_prefix'].'triples` WHERE resource = "ThisWikiGroup:admins";';
			$list = $this->LoadSingle($sql);
			$list = explode("\n", $list['value']);
			if (in_array($this->config['yeswiki-farm-admin-name'], $list)) {
				$list = array_diff($list, array($this->config['yeswiki-farm-admin-name']));
			}
			$list = array_map('trim', $list);
			$list = implode("\n", $list);
			$sql = 'UPDATE `'.$wakkaConfig['table_prefix'].'triples` SET value="'.addslashes($list).'" WHERE resource = "ThisWikiGroup:admins";';
			$this->Query($sql);

			$sql = 'DELETE FROM '.$wakkaConfig['table_prefix'].'users WHERE name="'.$this->config['yeswiki-farm-admin-name'].'";';
			$this->Query($sql);
		}
	} elseif (isset($_GET['maj']) and !empty($_GET['maj'])) {
		$srcfolder = getcwd().DIRECTORY_SEPARATOR;
		if ($this->config['yeswiki-farm-root-folder'] == '.') {
			$destfolder = realpath(getcwd().DIRECTORY_SEPARATOR.$_GET['maj']).DIRECTORY_SEPARATOR;
		} else {
			$destfolder = realpath(getcwd().DIRECTORY_SEPARATOR
				.$this->config['yeswiki-farm-root-folder'].DIRECTORY_SEPARATOR
				.$_GET['maj']).DIRECTORY_SEPARATOR;
		}

		include_once $destfolder.'wakka.config.php';
		echo '<div class="alert alert-info">'._t('FERME_UPDATING').$_GET['maj'].'.</div>';
		// nettoyage des anciens tools non utilises
		if (is_dir($destfolder.'tools/despam')) {
			rrmdir($destfolder.'tools/despam');
		}
		if (is_dir($destfolder.'tools/hashcash')) {
			rrmdir($destfolder.'tools/hashcash');
		}
		if (is_dir($destfolder.'tools/ipblock')) {
			rrmdir($destfolder.'tools/ipblock');
		}
		if (is_dir($destfolder.'tools/nospam')) {
			rrmdir($destfolder.'tools/nospam');
		}
		copyRecursive($srcfolder.'index.php', $destfolder.'index.php');
		copyRecursive($srcfolder.'interwiki.conf', $destfolder.'interwiki.conf');
		copyRecursive($srcfolder.'robots.txt', $destfolder.'robots.txt');
		copyRecursive($srcfolder.'tools.php', $destfolder.'tools.php');
		copyRecursive($srcfolder.'wakka.basic.css', $destfolder.'wakka.basic.css');
		copyRecursive($srcfolder.'wakka.css', $destfolder.'wakka.css');
		copyRecursive($srcfolder.'wakka.php', $destfolder.'wakka.php');

		// les dossiers de base des yeswiki
		copyRecursive($srcfolder.'actions', $destfolder.'actions');
		copyRecursive($srcfolder.'formatters', $destfolder.'formatters');
		copyRecursive($srcfolder.'handlers', $destfolder.'handlers');
		copyRecursive($srcfolder.'includes', $destfolder.'includes');
		copyRecursive($srcfolder.'vendor', $destfolder.'vendor');
		copyRecursive($srcfolder.'custom', $destfolder.'custom');
		copyRecursive($srcfolder.'lang', $destfolder.'lang');
		copyRecursive($srcfolder.'setup', $destfolder.'setup');

		// themes
		copyRecursive($srcfolder.'themes', $destfolder.'themes');

		// templates
		copyRecursive(
			$srcfolder.'themes'.DIRECTORY_SEPARATOR.'tools',
			$destfolder.'themes'.DIRECTORY_SEPARATOR.'tools'
		);
		// extensions de base
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'aceditor',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'aceditor'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'attach',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'attach'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'contact',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'contact'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'security',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'security'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'lang',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'lang'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'login',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'login'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'progressBar',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'progressBar'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'tableau',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'tableau'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'toc',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'toc'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'autoupdate',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'autoupdate'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'rss',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'rss'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'tags',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'tags'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'toolsmng',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'toolsmng'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'bazar',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'bazar'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'syndication',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'syndication'
		);
		copyRecursive(
			$srcfolder.'tools'.DIRECTORY_SEPARATOR.'templates',
			$destfolder.'tools'.DIRECTORY_SEPARATOR.'templates'
		);

		// change the config file to update yeswiki version
		include_once 'tools/templates/libs/Configuration.php';
		$config = new Configuration($destfolder.'wakka.config.php');
		$config->load();
		$config->yeswiki_version = $this->config['yeswiki_version'];
		$config->yeswiki_release = $this->config['yeswiki_release'];
		$config->write();
		echo '<div class="alert alert-success">'._t('FERME_WIKI').$_GET['maj']._t('FERME_UPDATED').'</div>';
	} // End elseif (isset($_GET['maj']) and !empty($_GET['maj']))
	$sql = 'SELECT body FROM `'.$this->config['table_prefix'].'pages` WHERE latest="Y" and body like \'%"bf_dossier-wiki":"%\'';
	$pages = $this->LoadAll($sql);
	$fiches = array_map(function($page) { return json_decode($page['body'], true); }, $pages);
	$GLOBALS['ordre'] = 'asc';
	$GLOBALS['champ'] = 'bf_titre';
	usort($fiches, 'champCompare');
	$list = '';
	foreach ($fiches as $fiche) {
		$wakkaConfig = array();
		if ($this->config['yeswiki-farm-root-folder'] == '.') {
			$wikiConfigFile = realpath(getcwd().'/'.$fiche['bf_dossier-wiki'].'/wakka.config.php');
		} else {
			$wikiConfigFile = realpath(getcwd().'/'.$this->config['yeswiki-farm-root-folder'].'/'.$fiche['bf_dossier-wiki'].'/wakka.config.php');
		}
		if (file_exists($wikiConfigFile)) {
			include_once $wikiConfigFile;
			if (!empty($wakkaConfig['table_prefix'])) {
			$url = $wakkaConfig['base_url'].$wakkaConfig['root_page'];
			$sql = 'SELECT tag,time FROM `'.$wakkaConfig['table_prefix'].'pages` WHERE latest="Y" ORDER BY time DESC LIMIT 8';
			$wikiresults = $this->LoadAll($sql);

			$list .= '<li class="item-wiki"><a href="'.$url.'">'.$fiche['bf_titre'].' - <small>'.$url.'</small></a>';
			$list .= '<br><strong>Version de yeswiki : '.(empty($wakkaConfig['yeswiki_release']) ? '<i>Inconnue</i>' : $wakkaConfig['yeswiki_release']).'</strong>';
			if (empty($wakkaConfig['yeswiki_release']) || ($wakkaConfig['yeswiki_release'] < $this->config['yeswiki_release'])) {
				$list .= ' <a class="btn btn-xs btn-danger" href="'.$this->href('', $this->GetPageTag(), 'maj='.$fiche['bf_dossier-wiki']).'">Mettre à jour vers '.$mainWikiVersion.'</a>';
			} else {
				$list .= ' <i>à jour avec le wiki source</i>';
			}
			// test de la presence d'un admin pour la ferme
			if (!empty($this->config['yeswiki-farm-admin-name']) and !empty($this->config['yeswiki-farm-admin-pass'])) {
				$sql = 'SELECT name FROM '.$wakkaConfig['table_prefix'].'users WHERE name="'.$this->config['yeswiki-farm-admin-name'].'"';
				$userresults = $this->LoadAll($sql);
				if (count($userresults) > 0) {
					$text = ' présent <a class="btn btn-xs btn-danger" href="'.$this->href('', $this->GetPageTag(), 'nosuperadmin='.$fiche['bf_dossier-wiki']).'">supprimer le compte</a>';
				} else {
					$text = ' absent <a class="btn btn-xs btn-success" href="'.$this->href('', $this->GetPageTag(), 'superadmin='.$fiche['bf_dossier-wiki']).'">ajouter le compte</a>';
				}
				$list .= '<br><strong>Admin de la ferme : </strong>'.$this->config['yeswiki-farm-admin-name'].$text;
			}
			$list .= '<br><strong>Dernières pages modifiées :</strong><small>';
			foreach ($wikiresults as $page) {
				$list .= '<br><a href="'.$wakkaConfig['base_url'].$page['tag'].'">'.$page['tag'].' <small>('.$page['time'].')</small></a>';
			}
			$list .= '</small><hr></li>';
		}
		} else {
			echo '<div class="alert alert-danger">'._t('FERME_FILE').$fiche['bf_dossier-wiki'].'/wakka.config.php'._t('FERME_NOT_FOUND').'</div>';
		}
	} // End foreach ($fiches as $fiche)
	if ($list) {
		echo '<ol class="list-wiki">'.$list.'</ol>';
	}
} else { // User isn't admin
	echo '<div class="alert alert-danger">'._t('FERME_ADMIN_REQUIRED').'</div>';
}
