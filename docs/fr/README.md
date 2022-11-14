# Extension ferme

**Ferme, ou comment créer des wiki depuis votre wiki**

Vous aimez YesWiki ? Avec l'extention ferme, il vous suffira de remplir un formulaire et vous aurez un nouveau wiki !
{{label class="label-danger" }}Attention{{end elem="label"}} Le clic est facile, cette extention peut devenir addictive, elle est donc à tenir éloignée des enfants. En effet, il est facile de créer des wiki, il faut penser qu'il faudra ensuite les faire vivre et les maintenir... Certes, la ferme vous aidera à les maintenir mais rien de pire que des wiki inutiles, qui laissent à penser que la coopération, ça ne fonctionne pas.

**Préambule**

L'extension "Ferme" a été développée principalement par Mrflos, alias, {{button class="new-window" link="http://www.cooperations.infini.fr/spip.php?article10947" nobtn="1" text="Florian Schmitt"}}.
Elle correspond aux besoins, souvent énoncés par les usagers de YesWiki, de pouvoir générer facilement des wiki sans avoir à passer par des fonctionnalités complexes tels que le FTP et la maîtrise des codes Mysql de leur serveur ;-)

**Ce que la ferme permet de :**
 - **retrouver tous les wiki installés sur votre serveur** et de pouvoir les administrer facilement au travers d'une interface dédiée
 - **récupérer les mails** (bientôt) de tous les gestionnaires des wiki de la ferme pour pouvoir les informer de...
 - **créer de nouveaux wiki** au travers d'un formulaire à remplir
 - **importer un wiki et le transformer en modèle** depuis un autre serveur

**L'extension "Ferme" peut donc servir à pas mal de choses pour des usages individuels ou plus collectifs :**
 - simplement gérer les wiki installés sur votre serveur pour vous faciliter la tâche
 - vous aider à installer de nouveaux wiki sur votre serveur très facilement
 - proposer à des adhérent.es, des collègues de pouvoir créer leur propre wiki
 - concevoir et proposer des modèles de wiki préparés pour gagner de l'efficience
 - ...
Et vous allez certainement inventer pleins de nouveaux usages !!!

## Les actions 

Nous allons passer en revue les actions proposées par l'extension Ferme.

### Administrer les wikis de votre ferme : {{adminwikis}}
Quand vous installez la ferme, à partir de "Gestion ferme à wikis" dans molette, vous accédez à la page suivante :
{{attach file="Accueil.png" desc="image tousleswiki.png (0.2MB)" size="large" class="center"}}
 - c'est dans cette page que vous retrouverez tous les wiki créés
Si l'on liste les informations disponibles, vous obtenez :
 -  **Titre du wiki** : pas besoin de plus d'explications...
 -  **Personne référente**	: vous devriez comprendre
 -  **Mail référent** : pas trop dur
 -  **Derniers changements** : La date du dernier changements sur ce wiki, pratique pour suivre l'activité. Quand on clique dessus, un pop-up avec les derniers changements du site s'affiche.
 -  **Admin temporaire** : selon votre configuration, un super admin pourra être installé sur ce wiki qui vous permettra de passer de l'un à l'autre, de le gérer sans avoir à gérer de nombreux mots de passe. Vous pourrez, ici, ajouter le super admin à ce wiki ou le supprimer.
 -  **Version du wiki** : vous pourrez mettre à jour votre wiki pour l'aligner sur la version du wiki qui héberge la ferme. En bas de cette colonne, il vous sera proposé de mettre à jour tous les wiki et éventuellement les pages par défaut
-  **Actions** : là, 4 petits boutons vous permettent...
 -  <i class="fa fa-eye"></i> de voir la fiche bazar correspondant à ce wiki
 -  <i class="fa fa-pencil-alt"></i> d'éditer cette fiche pour en changer les valeurs
 -  <i class="fas fa-file-archive"></i> de créer une archive de sauvegarde du wiki
 -  <i class="fa fa-trash"></i> de supprimer ce wiki ainsi que la fiche liée

{{attach file="basdepagebis.png" desc="image basdepage.png (0.1MB)" size="big" class="center"}}
En bas de cette page 
 - le bouton à droite déjà évoqué concernant les mises en page de masse 
 - un bouton "Rechercher d'autres wikis sur ce serveur" permettant de retrouver des wiki déjà installés et de pouvoir les gérer ensuite au travers de la ferme.

### La page de création d'un nouveau wiki
Il s'agit du formulaire 1100 en mode saisie. Vous remplissez la fiche et...
Votre wiki est créé. Rien de complexe. Par défaut, ne vous sera proposé qu'un wiki de base.
{{attach file="nouveauwiki.png" desc="image nouveauwiki.png (0.1MB)" size="original" class="center"}}

### Créer un modèle de wiki : {{generatemodel}}
Cette page très intéressante vous permet, en indiquant l'adresse d'un autre wiki que vous avez fabriqué ou qui vous semble intéressant comme base, d'en fabriquer un modèle qui sera ensuite proposé dans les choix d'installation.
{{attach file="nouveauModele.png" desc="image nouveauModele.png (89.5kB)" size="big" class="center"}}
Son fonctionnement est relativement aisé :
 - Entrer l'adresse du wiki que vous avez concocté comme modèle ou d'un wiki qui vous inspire
 - Cliquer sur "importer"
 - Cliquer sur "Générer le fichier MYSQL modèle pour ce wiki"
 - Repérez la nouvelle ligne apparue dans les "Modèles de contenus disponibles"
 - Cocher la case en début de ligne, enregistrer
 => vous avez maintenant un nouveau type de wiki proposé lors de l'installation au travers de la ferme. Le tour est joué !

## Elements configurables
### Création de comptes super admin pour administrer des wikis hébergés
!> Activable dans "Fichier de conf"
 - ajouter un super administrateur à chaque wiki afin de passer outre ou palier le compte administrateur de ce wiki ;
 - de supprimer, pour chaque wiki le compte superadmin.
Pour ce faire deux solutions
 - La première, aller dans "gestion du site" / "Fichier de conf" / Ferme. Entrez un "Login du super admin" et un "Pass du super admin" puis cliquer sur "Valider"
 - La seconde manière consiste à ajouter les deux lignes suivantes à wakka.config.php
```
'yeswiki-farm-admin-name' => 'NomWikidusuperadmin',
'yeswiki-farm-admin-pass' => 'votremotdepasse',
```
Ceci fait apparaître un bouton "ajouter le compte" en regard de chaque wiki dans la page d'administration des wikis. Une fois qu'on s'est créé un compte super admin pour un wiki, le bouton en regard du wiki dans la page d'administration des wikis devient rouge avec le libellé "supprimer le compte". Appuyer sur ce bouton ne supprime le compte super administrateur que sur le wiki en question.

### Dossier de stockage des wikis
!> Activable dans "Fichier de conf"

Par défaut, lorsqu'un wiki est créé dans la ferme, les fichiers de ce wikis sont placés dans un dossier portant le nom du wiki et placé à la racine du wiki de la ferme. Si vous souhaitez que les dossiers de vos wikis ne soient pas mélés à ceux qui sont nécessaires à la ferme, vous pouvez paramétrer le comportement de votre ferme à cet égard. Il est nécessaire de jouer sur deux paramètres :
 - le nom du dossier de stockage des wikis,
 - l'url de base des wikis de la ferme.
warning, ce dossier devra être créé sur votre serveur
**Le nom du dossier**
On utilise à cet effet le paramètre yeswiki-farm-root-folder. Il s'agit en fait du chemin relatif du dossier de stockage des wikis. Si vous voulez que vos wikis soient créés dans le sous-dossier wikis du dossier de votre ferme, vous devez le préciser dans "Fichier de conf" ou en ajoutant au wakka.config.php une ligne contenant :
```
'yeswiki-farm-root-folder' => 'wikis',
```
Par défaut, ce paramètre vaut 'yeswiki-farm-root-folder' => '.',

**L'url de base des wikis**
On utilise à cet effet le paramètre yeswiki-farm-root-url. Si l'adresse de ma ferme est https://ma.ferme.url/ et que vous voulez que vos wikis soient créés dans le sous-dossier wikis de cette ferme, vous devez préciser dans "Fichier de conf" ou en ajoutant au wakka.config.php une ligne contenant :
```
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```
Par défaut, ce paramètre n'est pas présent.

Attention — Ces deux paramètres doivent être en cohérence l'un avec l'autre. Si, dans le cas de notre exemple, vous saisissez 'yeswiki-farm-root-folder' => 'wikis', tout en n'ajoutant pas 'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/', vous ne pourrez jamais accéder aux wikis créés.

### Thèmes activables
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
thèmes supplémentaires (doivent être présents dans le dossier thèmes du wiki source)
```
'yeswiki-farm-extra-themes' => ['bootstrap3'],
```

### Interface de sélection des thèmes activables
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
tableau des choix de themes (ne s'affiche pas si un seul choix possible)
```
'yeswiki-farm-themes' => [
    [
      'label' => 'Margot (thème par défaut de YesWiki)', //nom du thème à l'écran
      'screenshot' => 'https://ferme.yeswiki.net/tools/ferme/screenshots/margot.jpg', (screenshot du theme dans tools/ferme/screenshots)
      'theme' => 'margot', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'margot.css' //style par defaut
    ],
    [
      'label' => 'Bootstrap (très simple)', //nom du thème à l'écran
      'screenshot' => 'https://ferme.yeswiki.net/tools/ferme/screenshots/bootstrap.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'bootstrap.min.css' //style par defaut
    ],
    [
      'label' => 'Paper (material design de google)', //nom du thème à l'écran
      'screenshot' => 'https://ferme.yeswiki.net/tools/ferme/screenshots/paper.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'paper.bootstrap.min.css' //style par defaut
    ],
    [
      'label' => 'Cyborg (theme sombre, fond noir)', //nom du thème à l'écran
      'screenshot' => 'https://ferme.yeswiki.net/tools/ferme/screenshots/cyborg.jpg', //screenshot du theme dans tools/ferme/screenshots
      'theme' => 'bootstrap3', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'cyborg.bootstrap.min.css' //style par defaut
    ],
  ],
```
  
### Tools activables
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
tools supplémentaires (doivent etre présents dans le dossier tools du wiki source)
```
'yeswiki-farm-extra-tools' => [],
```

### Droits d'accès
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
Proposer de sélectionner les droits d'accès (ne s'affiche pas si qu'un choix possible)
```
'yeswiki-farm-acls' => [
    [
      'label'    => 'Wiki ouvert', //Description des droits d'acces
      'read'     => '*', // lecture
      'write'    => '*', // ecriture
      'comments' => '*' // commentaires
    ],
    [
      'label'    => 'Wiki protégé par un identifiant / mot de passe unique', //Description des droits d'acces
      'read'     => '{{user}}', // lecture
      'write'    => '{{user}}', // ecriture
      'comments' => '{{user}}',  // commentaires
      'create_user' => true
    ]
  ],
```
===Nom de la page principale===
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
  // nom de la page d'accueil par défaut
```
'yeswiki-farm-homepage' => 'PagePrincipale',
```
===Et pour la mention===
options d'ajout sur certaines pages
```
'yeswiki-farm-options' => [
    [
      'label'    => 'Je souhaite intégrer un pad dans mon wiki', //Description de l'ajout
      'checked'  => false, // coche par defaut ?
      'page'    => 'PageMenuHaut', // Page
      'content' => " - [[EtherPad Pad]]\n" // Contenu en syntaxe wiki de l'ajout
    ],
    [
      'label'    => 'Je souhaite recevoir les informations sur mon wiki des autres projets', //Description de l'ajout
      'checked'  => true, // coche par defaut ?
      'page'    => 'PageMenuHaut', // Page
      'content' => " - [[InfosMutualisees Infos mutualisées]]\n" // Contenu en syntaxe wiki de l'ajout
    ]
  ],
```

cas spécifique ou l'on veut créer un user sur le wiki source
```
'yeswiki-farm-create-user' => false,
```
  
ajouter des valeurs dans le fichier de configuration des wikis créés
```
'yeswiki-farm-extra-config' => ['BAZ_ADRESSE_MAIL_ADMIN' => 'admin@yeswiki.test'],
```

image de fond par défaut des wikis créés
```
'yeswiki-farm-bg-img' => '',
```
  
