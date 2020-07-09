# Extension YesWiki bazar ferme
> Attention — Ceci est une extension de YesWiki. Elle ne fait pas partie du cœur officiellement maintenu de YesWiki.

### Ajoute la possibilité de créer des champs de type 'yeswiki'. Ceci permet de créer automatiquement un wiki en créant une fiche bazar

1) Copier l'extension dans votre dossier tools ou installez-la depuis la page `GererMisesAJour`.

2) Utiliser les lignes suivantes pour le formulaire bazar "ferme":
```
texte***bf_titre***Titre***255***255*** *** *** ***1***
texte***bf_description***Description courte***255***255*** *** *** ***1***
labelhtml***<p style="color:#cc3333;">Votre wiki sera accessible sans mot de passe. Si vous devez vous connecter pour certaines actions, vos login/mot de passe seront le mail et le mot de passe indiqués ci-dessous.</p>*** ***
yeswiki***bf_dossier-wiki***Nom du dossier wiki***255***255*** *** *** ***1***
texte***bf_prefixe***Préfixe des tables*** *** *** *** ***text***0*** ***Si non renseigné  le préfixe sera généré automatiquement à partir de l'adresse du site wiki*** * *** * *** *** ***
```
## Gestion des wikis générés
Placer {{adminwikis}} sur une page de votre wiki générateur de ferme permet de
 - mettre à jour chaque wiki (Remarque – chaque wiki est considéré comme à jour lorsqu'il est à la même version que le wiki maître => Maintenez votre wiki maître à jour) ; 
 - ajouter un super adminstrateur à chaque wiki afin de passer outre ou palier le compte administrateur de ce wiki ; 
 - de supprimer, pour chaque wiki le compte superadmin.
Pour ce faire, ajouter les deux lignes suivantes à `wakka.config.php`
```
  'yeswiki-farm-admin-name' => 'NomWikidusuperadmin',
  'yeswiki-farm-admin-pass' => 'votremotdepasse',
```
Ceci fait apparaître un bouton `ajouter le compte` en regard de chaque wiki dans la page d'administration des wikis.
Une fois qu'on s'est créé un compte super admin pour un wiki, le bouton en regard du wiki dans a page d'administration des wikis devient rouge avec le libellé `supprimer le compte`. Appuyer sur ce bouton ne supprime que le compte super administrateur sur le wiki en question.

### Pour supprimer un wiki
Pour supprimer un wiki, il faut aller sur la fiche bazar correspondant à ce wiki et supprimer celle-ci. Cela déclenche la suppression du wiki en question.

## Récupérer les fichiers sql de wikis sources
Il est possible de récupérer automatiquement les fichier sql des wikis qui doivent servir de modèles pour la ferme (voir param 'yeswiki-farm-sql' du wakka-config).
 - Placer {{generatemodel}} dans une page pour y faire apparaître le module d'import.
 - Après avoir affiché la page, dans la zone de saisie intitulée `Générer un modèle à partir d'une adresse URL`, saisir l'url du wiki source.
 - Cliquer sur `Importer`.
 - Apparaît alors une description du contenu du wiki en question. Cliquer sur le bouton `Générer le fichier MySQL modèle pour ce wiki`.
 - Un message indique que le fichier sql portant le nom du wiki source a été généré et copié dans le dossier `tools/ferme/sql` du wiki maître.
 - Il faut alors modifier le `wakka.config.php` comme suit.
```
  // fichiers sql du modele de wiki a installer par defaut
  'yeswiki-farm-sql' => array(
    array(
      'label' => 'Je veux un wiki vide pour commencer', //nom à l'écran
      'file' => 'default.sql' //fichier sql source des wikis de la ferme présent dans tools/ferme/sql
    ),
    array(
      'label' => 'Je veux la configuration de départ pour un projet d\'oasis', //nom à l'écran
      'file' => 'projet-oasis.sql' //fichier sql source des wikis de la ferme présent dans tools/ferme/sql
    ),
    array(
      'label' => 'Je veux la configuration standard de départ pour un projet', //nom à l'écran
      'file' => 'projet.sql' //fichier sql source des wikis de la ferme présent dans tools/ferme/sql
    )
  ),
```

## Paramétrer le dossier de stockage des wikis
Par défaut, lorsqu'un wiki est créé dans la ferme, les fichiers de ce wikis sont placés dans un dossier portant le nom du wiki et placé à la racine du wiki de la ferme. Si vous souhaitez que les dossiers de vos wikis ne soint pas mélés à ceux qui sont nécessaires à la ferme, vous pouvez paramétrer le comportement de votre ferme à cet égard.
Il est nécessaire de jouer sur deux paramètres :
- le nom du dossier de stockage des wikis,
- l'url de base des wikis de la ferme.

**Le nom du dossier —** On utilise à cet effet le paramètre `yeswiki-farm-root-folder`. Il s'agit en fait du chemin relatif du dossier de staockage des wikis.
Si vous voulezque vos wikis soient créés dans le sous-dossier `wikis` du dossier de votre ferme, vous devez le préciser en ajoutant au `wakka.config.php`une ligne contenant :
```
'yeswiki-farm-root-folder' => 'wikis',
```
Par défaut, ce paramètre vaut `'yeswiki-farm-root-folder' => '.',`

**L'url de base des wikis —** On utilise à cet effet le paramètre `yeswiki-farm-root-url`.
Si, l'adresse de ma ferme est `https://ma.ferme.url/` et que vous voulez que vos wikis soient créés dans le sous-dossier `wikis` de cette ferme, vous devez préciser en ajoutant au `wakka.config.php` une ligne contenant :
```
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```
Par défaut, ce paramètre n'est pas présent.

**Attention —** Ces deux paramètres doivent être en cohérence l'un avec l'autre.
Si, dans le cas de notre exemple, vous saisissez `'yeswiki-farm-root-folder' => 'wikis',` tout en n'ajoutant pas `'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',`, vous ne pourrez jamais accéder aux wikis créés.


## Autres options activables (à documenter) 
```
  // themes supplémentaires (doivent etre présents dans le dossier themes du wiki source)
  'yeswiki-farm-extra-themes' => array('bootstrap3'),

  // tools supplémentaires (doivent etre présents dans le dossier tools du wiki source)
  'yeswiki-farm-extra-tools' => array(),

  // tableau des choix de themes (ne s'affiche pas si qu'un choix possible)
  'yeswiki-farm-themes' => array(
    array(
      'label' => 'Colibris (élaboré, incluant beaucoup de pictos)', //nom du thème à l'écran
      'screenshot' => 'colibris.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'colibris', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'colibris.bootstrap.min.css' //style par defaut
    ),
    array(
      'label' => 'Bootstrap (très simple)', //nom du thème à l'écran
      'screenshot' => 'bootstrap.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'bootstrap.min.css' //style par defaut
    ),
    array(
      'label' => 'Paper (material design de google)', //nom du thème à l'écran
      'screenshot' => 'paper.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'paper.bootstrap.min.css' //style par defaut
    ),
    array(
      'label' => 'Cyborg (theme sombre, fond noir)', //nom du thème à l'écran
      'screenshot' => 'cyborg.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'cyborg.bootstrap.min.css' //style par defaut
    )
  ),
  // image de fond par défaut des wikis créés
  'yeswiki-farm-bg-img' => '',

  // droits d'acces (ne s'affiche pas si qu'un choix possible)
  'yeswiki-farm-acls' => array(
    array(
      'label'    => 'Wiki ouvert', //Description des droits d'acces
      'read'     => '*', // lecture
      'write'    => '*', // ecriture
      'comments' => '*' // commentaires
    ),
    array(
      'label'    => 'Wiki protégé par un identifiant / mot de passe unique', //Description des droits d'acces
      'read'     => '{{user}}', // lecture
      'write'    => '{{user}}', // ecriture
      'comments' => '{{user}}',  // commentaires
      'create_user' => true
    )
  ),

  // nom de la page d'accueil par défaut
  'yeswiki-farm-homepage' => 'PagePrincipale',

  // options d'ajout sur certaines pages
  'yeswiki-farm-options' => array(
    array(
      'label'    => 'Je souhaite intégrer un pad dans mon wiki', //Description de l'ajout
      'checked'  => false, // coche par defaut ?
      'page'    => 'PageMenuHaut', // Page
      'content' => " - [[EtherPad Pad]]\n" // Contenu en syntaxe wiki de l'ajout
    ),
    array(
      'label'    => 'Je souhaite recevoir les informations sur mon wiki des autres projets', //Description de l'ajout
      'checked'  => true, // coche par defaut ?
      'page'    => 'PageMenuHaut', // Page
      'content' => " - [[InfosMutualisees Infos mutualisées]]\n" // Contenu en syntaxe wiki de l'ajout
    )
  ),

  // cas spécifique ou l'on veut créer un user sur le wiki source
  'yeswiki-farm-create-user' => false,
  
  // ajouter des valeurs au fichier de configuration des wikis créés
  'yeswiki-farm-extra-config' => array( 'BAZ_ADRESSE_MAIL_ADMIN' => 'admin@yeswiki.test'),
```
