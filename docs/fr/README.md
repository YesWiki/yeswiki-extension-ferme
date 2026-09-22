# Extension ferme

**Ferme, ou comment créer des wiki depuis votre wiki**

Vous aimez YesWiki ? Avec l'extention ferme, il vous suffira de remplir un formulaire et vous aurez un nouveau wiki !
{{label class="label-danger" }}Attention{{end elem="label"}} Le clic est facile, cette extention peut devenir addictive, elle est donc à tenir éloignée des enfants. En effet, il est facile de créer des wiki, il faut penser qu'il faudra ensuite les faire vivre et les maintenir... Certes, la ferme vous aidera à les maintenir mais rien de pire que des wiki inutiles, qui laissent à penser que la coopération, ça ne fonctionne pas.

**Préambule**

L'extension "Ferme" a été développée principalement par Mrflos, alias, {{button class="new-window" link="http://www.cooperations.infini.fr/spip.php?article10947" nobtn="1" text="Florian Schmitt"}}.
Elle correspond aux besoins, souvent énoncés par les usagers de YesWiki, de pouvoir générer facilement des wiki sans avoir à passer par des fonctionnalités complexes tels que le FTP et la maîtrise des codes Mysql de leur serveur ;-)

**Ce que la ferme permet de :**
 - **retrouver tous les wiki installés sur votre serveur** et de pouvoir les administrer facilement au travers d'une interface dédiée
 - **écrire aux personnes référentes** des wiki de la ferme, une par une ou par paquets, pour les prévenir d'une migration, d'une fermeture, d'une nouveauté
 - **suivre l'activité de chaque wiki** : fiches, pages, comptes, fichiers, espace disque et date du dernier changement, mis à jour tout seuls
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

En haut de la page, un bandeau donne les totaux de la ferme — dont le nombre de wikis en service et en hibernation, sur lesquels on clique pour ne voir que ceux-là — et des étiquettes qui filtrent la liste d'un clic : à mettre à jour, dormants, contenu d'origine, archives lourdes, suspects, contenu spammé, en erreur, jamais mesurés, en hibernation. « Contenu d'origine » retient les wikis qui tiennent encore ce que leur modèle leur a donné : soit personne n'y a jamais rien écrit, soit quelqu'un a essayé — cinq pages au plus — et n'est jamais revenu depuis six mois. Un wiki installé il y a moins d'un mois n'y figure pas, il n'a pas encore eu sa chance. Les pages d'un modèle sont écrites en une fois, donc tout ce qui arrive cinq minutes plus tard a été tapé par quelqu'un, et les réécritures de pages par les mises à jour de la ferme, qui ne portent le nom de personne, ne comptent pas. Un wiki mis en hibernation n'est pas compté parmi les dormants : il se tait parce qu'on l'a voulu. Un menu "Trier par" range les wiki par titre, personne référente, dernière activité, activité totale, fiches, pages, comptes, formulaires ou espace disque, et le bouton à côté retourne l'ordre. Les en-têtes du tableau — nom, contenu, dernière activité, disque — se cliquent pour trier dessus, un deuxième clic retourne l'ordre, et le menu suit. L'étiquette choisie, le tri et la recherche s'écrivent dans l'adresse de la page : le lien que vous copiez rouvre la même liste.

Le tableau tient en cinq colonnes :
 -  Le détail dépliable d'un wiki marqué "contenu spammé" liste ses pages en cause, chacune avec un lien qui l'ouvre dans un nouvel onglet, la raison pour laquelle elle est marquée, et ce que le nettoyage en ferait — ou, quand aucune règle ne sait la nettoyer, qu'elle est à regarder à la main. Chaque page porte un lien « ce n'est pas du spam » : une liste de ressources honnête est faite de liens et rien d'autre, aucune règle ne saura jamais l'en distinguer, alors vous tranchez une fois et la ferme s'en souvient. Ce dont elle se souvient est la page telle que vous l'avez validée : si le robot y revient, elle redevient du spam toute seule. Un lien « remettre en spam » annule la validation, et le détail garde la liste des pages validées même quand le wiki a quitté l'étiquette « contenu spammé » : sans cela, une page validée par erreur serait hors d'atteinte
 -  **Nom du wiki** : son titre, qui mène à sa fiche bazar sur le wiki maître, une flèche à côté pour ouvrir le wiki lui-même dans un nouvel onglet, la personne référente et son mail, sa version et son compte super admin s'il en a un. S'y ajoutent, quand il y a lieu, une étiquette "custom mis de côté" laissée par une mise à jour interrompue, "suspect" quand le nom sent le spam, "en hibernation" quand le wiki n'accepte plus d'écriture, ou l'erreur rencontrée à la dernière mesure. Le détail dépliable dit toujours le statut, même pour un wiki jamais mesuré
 -  **Contenu** : ce que le wiki contient — fiches, pages, formulaires, comptes. Un point d'interrogation tant qu'il n'a jamais été mesuré
 -  **Dernière activité** : un petit graphe des douze derniers mois, et "il y a 5 j" en dessous — la date elle-même au-delà de trois mois
 -  **Disque** : la place prise par ses fichiers, et leur nombre
 -  **Actions** : le chevron déplie le détail du wiki (les chiffres en colonnes, la date d'installation, le nombre de pages touchées depuis, la date de la mesure, et l'activité du wiki sur l'année sous forme de calendrier), le bouton ⋯ ouvre le menu : voir la fiche bazar, l'éditer, mettre à jour vers la version du wiki maître, ajouter ou retirer le compte super admin, et supprimer le wiki — cette dernière entrée ouvre la page de suppression dans un nouvel onglet, en gardant la liste derrière, et cette page rappelle en rouge ce qui va disparaître

Ces chiffres ne sont pas recalculés à chaque affichage : la ferme mesure les wiki en arrière-plan, et ne recompte que ceux qui ont bougé. Sur une grosse ferme, mieux vaut lancer `ferme:stats` depuis une tâche planifiée et passer le réglage "stats on visit" à false.

{{attach file="basdepagebis.png" desc="image basdepage.png (0.1MB)" size="big" class="center"}}
En bas de cette page
 - une case "Sélectionner cette page", un bouton "Sélectionner les N wikis" qui prend tous ceux que la recherche et l'étiquette en cours retiennent — au-delà des cent affichés —, un lien "Tout désélectionner", et, dès qu'un wiki est coché, un bloc "Actions sur la sélection" sous le tableau. Il porte le nombre de wikis cochés et range les actions en quatre colonnes, mise à jour, contenu, comptes et contact, vie du wiki :
   -  **Mettre à jour les wikis sélectionnés** : fichiers, migrations, et les extensions propres à chaque wiki
   -  **Mettre à jour seulement les extensions** : les extensions propres au wiki passent à la version publiée pour la version de YesWiki qu'il fait tourner, puis leurs migrations sont lancées. Le cœur n'est pas touché
   -  **Rétablir les dossiers custom** : une mise à jour interrompue peut laisser un wiki sans son dossier custom, mis de côté sous le nom custom.temp. Les wikis dans ce cas portent l'étiquette "custom mis de côté" sous leur titre, et cette entrée les remet en place
   -  **Recalculer les statistiques** : remesure les wikis cochés tout de suite, sans attendre la prochaine passe
   -  **Chercher le spam (sans rien changer)** et **Nettoyer le spam** : la première dit, wiki par wiki, quelles pages partiraient et lesquelles seraient nettoyées ; la seconde le fait. Un wiki en hibernation est nettoyé comme les autres : il est réveillé le temps du nettoyage et rendormi ensuite, même si le nettoyage échoue. Une page que le robot a créée de toutes pièces est supprimée, une page à vous dont il a gâché quelques lignes garde son histoire et perd ces lignes-là, et son écriture passe au groupe admin. Tout ce qui part est d'abord écrit dans les sauvegardes de la ferme, pour pouvoir revenir
   -  **Ajouter** ou **Retirer le compte admin** sur les wikis sélectionnés
   -  **Envoyer un mail** : un message aux personnes référentes des wikis cochés, dont le modèle peut nommer le wiki, son adresse, sa dernière activité
   -  **Sauvegarder les wikis sélectionnés** : chaque wiki fabrique sa propre archive, même en hibernation — l'archive, elle, revient en service pour que la restauration donne un wiki qui marche. Un bouton de téléchargement apparaît par wiki, et l'archive quitte le wiki une fois téléchargée
   -  **Mettre en hibernation** : les wikis choisis continuent de se lire mais refusent toute écriture — pages, fiches, comptes, commentaires. C'est ce qu'on fait d'un wiki que plus personne ne maintient, plutôt que de le supprimer. **Tant qu'un wiki dort, la ferme ne le modifie plus** : ni mise à jour, ni compte admin. Elle peut encore le supprimer — l'hibernation protège un wiki d'être changé, pas d'être jeté — et la page de suppression rappelle qu'il dormait
   -  **Sortir d'hibernation** : ils redeviennent modifiables
   -  **Supprimer les wikis sélectionnés**, après confirmation. Supprimer une fiche depuis bazar efface aussi le wiki : la page de suppression le dit en rouge et rappelle ce que le wiki contient avant que vous confirmiez
 Chaque traitement affiche le wiki en cours et son rang sur le total, et une erreur sur l'un n'arrête pas les autres. Les suppressions partent par paquets de cinq, cinq paquets à la fois, ce qui permet d'en passer des centaines sans y laisser l'après-midi.
 - un bouton "Rechercher d'autres wikis sur ce serveur" : il inspecte le serveur et vous rend la liste des wiki installés qui n'ont pas de fiche, à cocher pour les importer. Rien n'est importé sans votre clic.

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
 - La première, aller dans "gestion du site" / "Fichier de conf" / "Ferme à wikis". Remplissez "Nom du compte super-administrateur..." et "Mot de passe de ce compte super-administrateur" puis cliquer sur "Valider". Chaque réglage y porte une phrase qui dit à quoi il sert, et la clé correspondante à côté
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
thèmes supplémentaires, copiés dans chaque wiki créé en plus de ceux du cœur. Ils doivent être installés dans le dossier `themes` du wiki de la ferme, sans quoi la création le signale et passe son chemin.
```
'yeswiki-farm-extra-themes' => ['montheme'],
```

### Interface de sélection des thèmes activables
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
tableau des choix de themes (ne s'affiche pas si un seul choix possible). La capture
d'écran est un nom de fichier à déposer dans `tools/ferme/screenshots/`, pas une
adresse : un fichier absent est simplement ignoré, le thème reste proposé sans image.
```
'yeswiki-farm-themes' => [
    [
      'label' => 'Margot (thème par défaut de YesWiki)', //nom du thème à l'écran
      'screenshot' => 'margot.jpg', //fichier dans tools/ferme/screenshots
      'theme' => 'margot', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'margot.css' //style par defaut
    ],
    [
      'label' => 'Margot clair', //nom du thème à l'écran
      'screenshot' => false, //pas de capture d'écran
      'theme' => 'margot', //nom de theme
      'squelette' => '1col.tpl.html', //squelette par defaut
      'style' => 'light.css' //style par defaut
    ],
  ],
```

### Tools activables
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
extensions supplémentaires, copiées dans chaque wiki créé en plus de celles du cœur. Elles doivent être présentes dans le dossier `tools` du wiki de la ferme.
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
### Nom de la page principale
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
nom de la page d'accueil des wikis créés
```
'yeswiki-farm-homepage' => 'PagePrincipale',
```
### Ajouts proposés à la création
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
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

### Compte sur le wiki de la ferme
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
cas spécifique ou l'on veut créer un user sur le wiki source
```
'yeswiki-farm-create-user' => false,
```
  
### Réglages hérités par les wikis créés
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
ajouter des valeurs dans le fichier de configuration des wikis créés
```
'yeswiki-farm-extra-config' => ['BAZ_ADRESSE_MAIL_ADMIN' => 'admin@yeswiki.test'],
```

### Les autres réglages
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
Le reste se règle dans "gestion du site" / "Fichier de conf" / "Ferme à wikis", où
chaque ligne porte son explication : où sont rangés les wiki et sous quelle adresse,
les thèmes et les extensions copiés dans chaque wiki créé, le compte administrateur
qu'il reçoit, le préfixe de leurs tables, la fréquence des mesures d'activité, le
seuil à partir duquel un wiki est signalé comme suspect et les mots qui le déclenchent,
le modèle du mail envoyé aux personnes référentes, et l'adresse d'un webhook Mattermost
prévenu à chaque wiki créé ou supprimé.
  
