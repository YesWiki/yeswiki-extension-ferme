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
   -  **Mettre en hibernation** : les wikis choisis continuent de se lire mais refusent toute écriture — pages, fiches, comptes, commentaires. C'est ce qu'on fait d'un wiki que plus personne ne maintient, plutôt que de le supprimer. **Tant qu'un wiki dort, la ferme ne lui ouvre plus l'écriture** : pas de compte admin, et la page d'un visiteur reste refusée. Ce qui touche à son code passe quand même, et de la même façon partout : la mise à jour, le prêt du code par liens symboliques et le nettoyage du spam réveillent le wiki le temps du travail et le rendorment ensuite, même quand le travail échoue. Les migrations du cœur refusent de tourner sur un wiki endormi, c'est ce réveil qui leur ouvre la porte. Elle peut encore le supprimer — l'hibernation protège un wiki d'être changé, pas d'être jeté — et la page de suppression rappelle qu'il dormait
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

### Fichiers partagés avec les wikis créés
{{label class="label-danger" }}Activable uniquement dans "wakka.config.php"{{end elem="label"}}
Ce qu'un wiki créé emprunte à la ferme par un lien symbolique au lieu de le copier.
Le code du cœur est le même dans tous les wikis et pèse 112 Mo chacun ; prêté, il
n'est sur le disque qu'une fois.
```
'yeswiki_symlinked_files' => [
  'javascripts', 'vendor', 'styles', 'includes', 'lang', 'tools/bazar', // ...
],
```
Par défaut c'est la même liste que `yeswiki-farm-lent-files`, soit les extensions du
cœur une par une, `tools/bazar`, `tools/attach`, `tools/login`, et `themes/margot`.
Jamais `tools` ni `themes` en entier : ces deux dossiers restent en dur dans chaque
wiki, sans quoi il ne pourrait plus avoir d'extension ni de thème à lui. Une extension
installée sur la ferme et absente de la liste reste sur la ferme ; pour la donner aux
wikis créés, c'est `yeswiki-farm-extra-tools`, qui la copie et dont le wiki devient
propriétaire. Mettre `[]` revient à une copie complète par wiki.

Un wiki dont les fichiers pointent vers la ferme suit la version de la ferme : c'est
`ferme:update` sur la ferme qui les met à jour tous d'un coup, et ce wiki ne peut plus
rester sur une version plus ancienne que les autres. Les wikis déjà installés ne sont
pas touchés par ce réglage, qui ne vaut qu'à la création ; `ferme:symlink` remplace
leurs copies par des liens, wiki par wiki, et `ferme:symlink --undo` refait des copies.

### Les autres réglages
{{label class="label-warning" }}Activable dans "Fichier de conf"{{end elem="label"}}
Le reste se règle dans "gestion du site" / "Fichier de conf" / "Ferme à wikis", où
chaque ligne porte son explication : où sont rangés les wiki et sous quelle adresse,
les thèmes et les extensions copiés dans chaque wiki créé, le compte administrateur
qu'il reçoit, le préfixe de leurs tables, la fréquence des mesures d'activité, le
seuil à partir duquel un wiki est signalé comme suspect et les mots qui le déclenchent,
le modèle du mail envoyé aux personnes référentes, et l'adresse d'un webhook Mattermost
prévenu à chaque wiki créé ou supprimé.
  

## Gestion de la ferme à wikis

### Mise à jour de wiki

- mettre à jour chaque wiki (Remarque – chaque wiki est considéré comme à jour lorsqu'il est à la même version que le wiki maître => Maintenez votre wiki principal à jour) ;

### Suppression de wiki

Pour supprimer un wiki, l'interface propose un bouton poubelle pour supprimer la fiche wiki et son contenu associé.
On peut aussi supprimer par l'interface de bazar. Cela déclenche la suppression du wiki en question.

### Un seul traitement à la fois par wiki

Créer, mettre à jour et supprimer un wiki ne peuvent pas se faire en même temps sur
le même wiki. Le deuxième traitement ne patiente pas : il renonce en disant ce que
l'autre est en train de faire, et une file de suppressions ou de mises à jour passe
au wiki suivant.

Une copie qui n'a pas tout apporté est une copie ratée : une création incomplète
échoue et efface le dossier commencé, une mise à jour s'arrête avant d'estampiller
la version.

### Le ménage de la passe statistique

`ferme:stats`, qui tourne de toute façon, en profite pour donner à chaque wiki le
dossier `private` (et `private/backups`) qu'il devrait avoir, et pour jeter les
archives que personne n'est venu chercher — une semaine par défaut,
`yeswiki-farm-archive-keep` en décide, 0 les garde pour toujours. Sur une ferme de
3 149 wikis, ce parcours coûte un dixième de seconde.

## Récupérer les fichiers sql de wikis sources

Il est possible de récupérer automatiquement les fichier sql des wikis qui doivent servir de modèles pour la ferme (voir param 'yeswiki-farm-models' du wakka-config).

- Placer {{generatemodel}} dans une page pour y faire apparaître le module d'import.
- Après avoir affiché la page, dans la zone de saisie intitulée `Générer un modèle à partir d'une adresse URL`, saisir l'url du wiki source.
- Cliquer sur `Importer`.
- Apparaît alors une description du contenu du wiki en question. Cliquer sur le bouton `Générer le fichier MySQL modèle pour ce wiki`.
- Un message indique que le fichier sql portant le nom du wiki source a été généré et copié dans le dossier `tools/ferme/sql` du wiki maître.
- Il faut alors modifier le `wakka.config.php` comme suit.

```php
  // model folders in `custom/wiki-models`
  'yeswiki-farm-models' => [
    'default-content', // special alias for default installation model
    'pnth-terreenaction.org--sourcecollectif',
  ],
```

## Les commandes en ligne

Elles se lancent depuis la racine du wiki de la ferme, comme toutes les commandes YesWiki :

```
./yeswicli ferme:list
./yeswicli ferme:config --smtp --dry-run
./yeswicli ferme:admin --user Support --password 'une longue phrase de passe'
./yeswicli ferme:update
```

Les quatre commandes partagent la façon de choisir les wikis : sans rien, elles
travaillent sur les wikis de la ferme ; `--path` scanne un autre dossier,
`--depth` dit jusqu'où descendre, `--wiki` ne vise qu'un dossier. Le wiki maître
n'est jamais une cible de `ferme:update`. Un wiki que l'utilisateur qui lance la
commande ne peut pas écrire est signalé en échec : il n'y a pas de `sudo` ici.
`--dry-run` montre ce qui serait fait sans rien écrire.

- **`ferme:list`** dit, pour chaque wiki trouvé, s'il a une fiche dans le
  formulaire de la ferme, ce qu'il contient (pages, fiches, comptes, dernière
  modification, d'après les statistiques déjà mesurées), si sa base répond, s'il ne
  lui manque pas de table, et qui l'administre. `--format=json|csv` sort la même
  chose pour un autre programme. `--import` crée les fiches manquantes, avec l'email
  du premier admin du wiki concerné.

Sur une ferme ouverte depuis longtemps, l'immense majorité des dossiers sans fiche
sont des wikis créés puis jamais utilisés, dont beaucoup de spam : les importer tous
fabriquerait des centaines de fiches pour rien. Cinq filtres restreignent l'import à
ce qui mérite une fiche, tous fondés sur les statistiques déjà mesurées, donc
gratuits :

```
./yeswicli ferme:list --import --dry-run \
    --min-entries=15 --min-users=2 --active-since=180d \
    --name-excludes='bet|casino|win|slot|clb|essay|writing|homework'
```

`--min-entries`, `--min-pages`, `--min-users` et `--active-since` écartent ce qui n'a
jamais servi, `--name-excludes` écarte par le nom. Le compte rendu dit combien de
wikis chaque filtre a laissés de côté, et un wiki jamais mesuré est laissé de côté
plutôt que deviné. Commencez toujours par `--dry-run`.

Chaque wiki reçoit aussi une **note de suspicion**, calculée à la mesure et rangée
avec le reste. Cinq signaux, calibrés sur une ferme de 3735 wikis : écriture d'un
autre alphabet et vocabulaire de paris, escorte ou vente de devoirs valent 3 points
chacun ; un lien dans le nom, un nom dans une autre langue que celle de la ferme, et
un wiki jamais allé au-delà du modèle valent 2 chacun. À partir de 3 points, donc dès
qu'un signal de contenu est présent et pas seulement l'inactivité, le wiki est
signalé : 500 wikis sur 3735, dont deux à tort en relisant la liste. La puce
« ressemble à du spam » les rassemble dans la page d'admin, `--skip-suspect` les
écarte d'un import, et `yeswiki-farm-spam-threshold` et `yeswiki-farm-spam-words`
règlent la sévérité et le vocabulaire.

Un wiki qui sort du modèle et que personne n'a touché a une signature reconnaissable,
autour de 128 pages, 9 fiches et 1 compte : c'est ce que `--min-entries=15` ou
`--min-users=2` écartent. `--never-edited` répond à la même question sans seuil à
deviner : il ne garde que les wikis qui tiennent encore ce que leur modèle leur a
donné, c'est-à-dire ceux où personne n'a jamais rien écrit, et ceux où quelqu'un a
touché cinq pages au plus sans revenir depuis six mois. Un wiki installé il y a moins
d'un mois n'y figure jamais. Les pages d'un modèle sont écrites en une fois : tout ce
qui arrive cinq minutes plus tard a été tapé par quelqu'un, et les réécritures que les
mises à jour de la ferme laissent derrière elles, sans nom d'utilisateur, ne comptent
pas — sur la ferme de 3 029 wikis elles touchaient presque tous les wikis le même jour.
La puce « contenu d'origine » montre les mêmes wikis dans la page d'admin. Pour se faire une
idée avant de choisir les seuils, `ferme:list --format=csv` sort une colonne par
chiffre, qui se trie dans un tableur.
- **`ferme:clean-spam`** retire des wikis ce que les robots y ont écrit : une page
  qu'ils ont créée de toutes pièces est supprimée, une page du wiki dont ils ont
  gâché quelques lignes garde son histoire, perd ces lignes-là et passe en écriture
  au groupe admin. Tout ce qui part est écrit dans les sauvegardes de la ferme
  avant de partir. `--dry-run` d'abord, `--list` détaille page par page, `--repair`
  rend sa dernière révision à une page qui n'en a plus. `--stuck` liste les pages
  condamnées qu'aucune règle ne sait nettoyer, avec les hôtes vers lesquels elles
  pointent : c'est la boucle de sortie du dernier reliquat, un hôte ajouté à
  `yeswiki-farm-spam-hosts` et le nettoyage relancé. `--approved` liste ce qu'une
  personne a validé à la main.

Une page peut être honnête et ressembler à du spam : une liste de ressources est
faite de liens et rien d'autre, et aucune règle ne la distinguera jamais d'une
ferme de liens. Une personne la valide donc une fois, depuis le détail du wiki
dans la page d'administration, et la ferme s'en souvient. **Ce dont elle se
souvient est la page telle qu'elle a été validée** : un condensé du texte, rangé
dans `spamApproved` à côté des statistiques du wiki. Que le robot y revienne et
le condensé ne correspond plus — la page redevient du spam sans que personne ait
eu à retirer quoi que ce soit. La mesure ne la compte plus, le nettoyage ne la
touche plus, et `--approved` signale les validations devenues caduques.

`yeswiki-farm-spam-hosts` est un fragment d'expression régulière, `|` entre les
motifs, cherché dans le corps de la page : un hôte qui s'y trouve condamne la page
si courte soit-elle, et le nettoyage retire toutes les lignes qui le portent. Un
nom de domaine se préfixe donc de `[/.]` — `passion\.com` seul attraperait
`mapassion.com`. Un motif peut couvrir toute une campagne d'un coup :
`\.blogspot\.com\.au` retire soixante blogs pornographiques collés dans la même
page. Sur CoopTools, les cinquante-deux motifs en place condamnent cinq pages que
rien d'autre ne voyait, et pas une honnête.
- **`ferme:spam-index`** recense les lignes porteuses de liens que plusieurs wikis
  affichent. Une même ferme donne le même modèle à tous ses wikis : une ligne n'est
  donc retenue que si elle vient d'une page déjà condamnée **et** qu'aucune page
  saine, nulle part, ne la porte. Ce que cela attrape, c'est le bloc de liens collé
  dans cinquante wikis, que rien d'autre ne voit : les mots du lexique n'y sont pas,
  les domaines changent, et un seul lien par ligne passe sous la règle des lignes
  denses. Sur une ferme de 3 149 wikis, l'index retient 14 000 lignes, en écarte 129
  parce qu'une page saine les porte, et fait passer le spam vu de 175 wikis à 411.
  `--min` règle le nombre de wikis qui fait une campagne, `--show` montre l'index.
  `ferme:stats` le reconstruit tout seul une fois par semaine.

Une page est condamnée pour deux liens du lexique, un hôte connu, une campagne, ou
cinquante liens **et** trois lignes sur dix qui en portent un : le compte rendu de
réunion qui cite cinquante adresses au fil de deux mille lignes de prose n'est pas
du spam, et l'annoncer sans pouvoir le nettoyer laisse un chiffre que rien ne fait
descendre. Le nettoyage, lui, vide deux formes d'empilement : dix lignes qui ne sont
qu'un lien et font les trois cinquièmes de la page, ou cinquante lignes portant un
lien qui en font les quatre cinquièmes — la deuxième attrape les fermes de liens
étiquetés, `[[https://cabinet.example/article-12/ conseil juridique]]` mille fois de
suite. Nettoyer n'est pas accuser : une page n'est vidée de ses liens que si autre
chose l'a déjà condamnée.
- **`ferme:config`** écrit et retire des clés dans le `wakka.config.php` de chaque
  wiki : `--set cle=valeur` (répétable, les points font des tableaux imbriqués,
  `int:5`, `json:{...}`, `true`, `false` et `null` gardent leur type) et
  `--unset cle`. `--smtp` recopie les réglages `contact_*` de la ferme dans tous
  les wikis, et dans le `yeswiki-farm-extra-config` de la ferme pour que les
  prochains wikis en héritent.
- **`ferme:admin`** crée l'utilisateur ou remet son mot de passe dans chaque wiki
  et l'ajoute au groupe voulu, avec les valeurs `yeswiki-farm-admin-*` par défaut.
  `--remove` fait l'inverse.
- **`ferme:update`** met les wikis à l'état du wiki maître : sauvegarde des
  fichiers remplacés et de la base, mise à niveau des extensions que le wiki a en
  plus, copie, migrations, puis effacement de la sauvegarde si tout s'est bien
  passé. `--workers` en traite plusieurs à la fois, `--force` refait un wiki déjà
  à jour, `--archive-url` part d'une archive zip plutôt que du wiki maître. Un wiki
  en échec est signalé et la série continue, comme dans la page d'administration ;
  `--stop-on-error` arrête tout au premier échec. `--migrate-only` ne fait que les
  migrations et l'estampille de version : ni fichiers remplacés, ni extensions mises
  à niveau. C'est ce qu'il faut à une ferme dont les wikis pointent vers le maître
  par des liens symboliques, puisque leur code a déjà changé et qu'il ne reste que
  leur base à mettre à l'heure.
- **`ferme:symlink`** remplace, dans les wikis déjà installés, les dossiers listés par
  `yeswiki-farm-lent-files` par des liens vers la ferme : sur une ferme de quelques
  milliers de wikis, c'est des dizaines de gigaoctets rendus au disque. Un dossier
  n'est remplacé que s'il contient exactement ce que contient le maître, mêmes
  fichiers et mêmes tailles ; un wiki retouché à la main est signalé et laissé
  tranquille. `--list` détaille dossier par dossier ce qui a été fait et pourquoi,
  `--undo` recopie le code dans un wiki qui doit reprendre sa route tout seul, et
  `--dry-run` montre sans rien toucher — commencez par là. Le résumé dit combien de
  liens ont été posés et combien de place a été rendue. Les wikis créés ensuite
  reçoivent leurs liens directement, c'est `yeswiki_symlinked_files` qui le dit.

Quand le wiki maître change de version, la ferme lance ces migrations toute seule,
aux deux endroits où cette version change :

- la page `{{update}}` du maître, juste après qu'elle a fini son travail. Le visiteur
  reçoit sa page, puis `ferme:update --migrate-only` démarre dans un processus séparé
  dont la sortie va dans `private/ferme-migrate.log` : quelques milliers de wikis sont
  bien plus longs qu'une page ne peut attendre
- la commande `migrate` du maître, qui enchaîne sur les wikis de la ferme dans la
  foulée, à l'écran : celui qui tape la commande regarde, autant qu'il voie passer les
  wikis plutôt que de l'apprendre par un journal

La version trouvée est notée dans `private/ferme-release` avant que le travail
commence, donc ça n'arrive qu'une fois par version, et une ferme qui n'a encore rien
noté se contente de noter. La version est relue dans `wakka.config.php` plutôt que
dans la configuration en mémoire, puisque c'est la requête même qui vient de l'écrire
qui pose la question. Les migrations que la ferme lance dans ses wikis portent une
variable d'environnement qui empêche l'une d'elles de relancer toute la ferme.

Le réglage `yeswiki-farm-migrate-on-update` éteint tout ça, ce qu'il faut faire sur un
hébergement où `exec()` est interdit ou si vous préférez une ligne de cron.

Les statistiques viennent d'une passe `ferme:stats`, à mettre au cron. Toutes les
quinze minutes suffisent largement, une passe sur 2 700 wikis tenant en quelques
secondes puisque presque tous sont écartés par deux sondes :

```
*/15 * * * * cd /chemin/de/la/ferme && /usr/bin/php includes/commands/console ferme:stats --stale=10m >> private/logs/ferme-stats.log 2>&1
```

`--stale` doit rester plus court que la période du cron, sinon les passages suivants
écartent tout et ne font rien. Deux passages ne peuvent pas se chevaucher, un verrou
s'en charge, donc une passe lente ne s'empile pas sur la suivante.

Pour vérifier que ça tourne vraiment, `ferme:stats --check=30m` ne mesure rien, dit
quelle est la mesure la plus ancienne de la ferme et sort en erreur si un wiki dépasse
la cible. C'est la ligne à donner à une supervision :

```
*/30 * * * * cd /chemin/de/la/ferme && /usr/bin/php includes/commands/console ferme:stats --check=30m || echo "les stats de la ferme ne se mettent plus à jour" | mail -s "ferme" admin@exemple.org
```

Les statistiques se rafraîchissent aussi toutes seules sans cron : après qu'une page
du wiki maître a été servie, et au plus une fois par intervalle, un lot de wikis est
remesuré une fois le visiteur parti, sans qu'il attende. Par défaut, un lot de 100
toutes les 5 minutes, soit 1200 wikis à l'heure : une ferme jusqu'à 1000 wikis a donc
ses statistiques à jour toutes les heures, pourvu que le maître reçoive une visite par
intervalle. La formule est `3600 ÷ intervalle × lot`, à ajuster avec
`yeswiki-farm-stats-interval` et `yeswiki-farm-stats-per-visit` pour une ferme plus
grande. `'yeswiki-farm-stats-on-visit' => false` coupe tout, ce qu'il faut faire quand
le cron est en place. Les trois réglages sont modifiables depuis `{{editconfig}}`.

Personne n'a à mettre les extensions à jour à la main : la commande va chercher
au dépôt la version publiée pour la version de YesWiki visée, et ne télécharge
que les extensions qui en ont besoin. Elles passent toujours avant `migrate`,
pour que leurs fichiers et leurs migrations soient en place quand il tourne :
avant le remplacement du cœur quand le wiki garde sa version, juste après quand
il en change, parce que le dépôt ne répond pour la nouvelle version qu'une fois
le wiki inscrit dessus. Une extension que la version visée ne publie pas est
gardée telle quelle et signalée dans le compte rendu. `--ignore-extensions` ne
touche à aucune.

Le temps des migrations, le `custom/` du wiki est mis de côté sous le nom
`custom.temp` pour que le nouveau noyau travaille sur un wiki standard, puis il
est remis en place, y compris si le processus est tué ou arrêté au clavier. Une
exécution qui n'a rien pu remettre laisserait le wiki sans `custom/` :
`ferme:update` remet d'abord en place les `custom.temp` des wikis qu'il vise, et
`./yeswicli ferme:update --recover-only` ne fait que ça, sans rien mettre à jour.
La page AdminWikis signale les wikis dans ce cas et propose la même remise en place
dans son menu d'actions.
Un `custom/` réapparu entre-temps part dans le dossier de sauvegarde, jamais à la
poubelle.

Avec `yeswiki-farm-mattermost-webhook`, un salon Mattermost reçoit un message à
chaque création et à chaque suppression de wiki : le titre, le dossier, la personne
référente, son adresse, qui a fait l'action, et deux liens, l'un vers la fiche et
l'autre vers sa page de suppression. Le lien de suppression ne supprime rien par
lui-même : il mène sur la ferme, où il faut être identifié·e comme admin et
confirmer, comme pour n'importe quelle page. Un wiki dont le nom sent le spam arrive
en rouge avec les raisons, ce qui permet de modérer au fil de l'eau plutôt qu'une fois
par mois. Un webhook injoignable est écrit dans le journal et n'empêche jamais la
création qu'il annonçait.

La plupart des réglages de la ferme se modifient depuis `{{editconfig}}`. Quatre
restent volontairement en dehors, parce qu'une erreur de frappe y casse les mises à
jour ou ment sur ce qui est installé : `yeswiki_files`, `yeswiki_empty_folders`,
`yeswiki_symlinked_files` et les `yeswiki_version`/`yeswiki_release` que le cœur
écrit lui-même. Les listes de choix offerts aux créateurs de wikis
(`yeswiki-farm-themes`, `-acls`, `-options`, `-models`, `-extra-config`,
`-extra-tools`) en sont aussi, faute d'une interface qui sache éditer des structures
imbriquées sans les abîmer.

Ce que `yeswiki_symlinked_files` contient est décrit plus haut, dans « Fichiers
partagés avec les wikis créés ».

Trois réglages s'ajoutent au `wakka.config.php`, modifiables depuis `{{editconfig}}` :

```php
  // email des fiches créées par `ferme:list --import` quand le wiki n'a pas d'admin avec email
  'yeswiki-farm-admin-email' => '',

  // archive utilisée par `ferme:update --archive-url` quand on ne lui donne pas d'adresse
  'yeswiki-farm-archive-url' => '',

  // sauvegardes de `ferme:update` et `ferme:config`, relatif au wiki de la ferme
  'yeswiki-farm-backup-dir' => 'private/backups/farm',
```

Le dossier de sauvegarde doit être sur le même système de fichiers que les wikis :
`ferme:update` déplace les fichiers au lieu de les copier, et refuse de démarrer
sinon.
