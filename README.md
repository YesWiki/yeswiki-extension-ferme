# Extension YesWiki bazar ferme

Permet de créer automatiquement un wiki en créant une fiche bazar

1) Copier l'extension dans votre dossier tools ou installez-la depuis la page `GererMisesAJour`.
2) Mettre un `/update` en fin d'url pour finaler l'installation
3) Vous pouvez alors accéder à la page AdminWikis pour administrer la ferme

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

### Création de comptes admins temporaires pour administrer des wikis hébergés

- ajouter un super administrateur à chaque wiki afin de passer outre ou palier le compte administrateur de ce wiki ;
- de supprimer, pour chaque wiki le compte superadmin.

Pour ce faire, ajouter les deux lignes suivantes à `wakka.config.php`

```php
  'yeswiki-farm-admin-name' => 'NomWikidusuperadmin',
  'yeswiki-farm-admin-pass' => 'votremotdepasse',
```

Ceci fait apparaître un bouton `ajouter le compte` en regard de chaque wiki dans la page d'administration des wikis.
Une fois qu'on s'est créé un compte super admin pour un wiki, le bouton en regard du wiki dans la page d'administration des wikis devient rouge avec le libellé `supprimer le compte`. Appuyer sur ce bouton ne supprime que le compte super administrateur sur le wiki en question.

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

## Paramétrer le dossier de stockage des wikis

Par défaut, lorsqu'un wiki est créé dans la ferme, les fichiers de ce wikis sont placés dans un dossier portant le nom du wiki et placé à la racine du wiki de la ferme. Si vous souhaitez que les dossiers de vos wikis ne soint pas mélés à ceux qui sont nécessaires à la ferme, vous pouvez paramétrer le comportement de votre ferme à cet égard.
Il est nécessaire de jouer sur deux paramètres :

- le nom du dossier de stockage des wikis,
- l'url de base des wikis de la ferme.

**Le nom du dossier —** On utilise à cet effet le paramètre `yeswiki-farm-root-folder`. Il s'agit en fait du chemin relatif du dossier de staockage des wikis.
Si vous voulezque vos wikis soient créés dans le sous-dossier `wikis` du dossier de votre ferme, vous devez le préciser en ajoutant au `wakka.config.php`une ligne contenant :

```php
'yeswiki-farm-root-folder' => 'wikis',
```

Par défaut, ce paramètre vaut `'yeswiki-farm-root-folder' => '.',`

**L'url de base des wikis —** On utilise à cet effet le paramètre `yeswiki-farm-root-url`.
Si, l'adresse de ma ferme est `https://ma.ferme.url/` et que vous voulez que vos wikis soient créés dans le sous-dossier `wikis` de cette ferme, vous devez préciser en ajoutant au `wakka.config.php` une ligne contenant :

```php
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```

Par défaut, ce paramètre n'est pas présent.

**Attention —** Ces deux paramètres doivent être en cohérence l'un avec l'autre.
Si, dans le cas de notre exemple, vous saisissez `'yeswiki-farm-root-folder' => 'wikis',` tout en n'ajoutant pas `'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',`, vous ne pourrez jamais accéder aux wikis créés.

## Autres options activables (à documenter)

```php
  // themes supplémentaires (doivent etre présents dans le dossier themes du wiki source)
  'yeswiki-farm-extra-themes' => ['bootstrap3'],

  // tools supplémentaires (doivent etre présents dans le dossier tools du wiki source)
  'yeswiki-farm-extra-tools' => [],

  // tableau des choix de themes (ne s'affiche pas si qu'un choix possible)
  'yeswiki-farm-themes' => [
    [
      'label' => 'Margot (thème par défaut de YesWiki)', //nom du thème à l'écran
      'screenshot' => 'https://ferme.yeswiki.net/tools/ferme/screenshots/margot.jpg', //screenshot du theme dans tools/ferme/screenshots
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
  // image de fond par défaut des wikis créés
  'yeswiki-farm-bg-img' => '',

  // droits d'acces (ne s'affiche pas si qu'un choix possible)
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

  // nom de la page d'accueil par défaut
  'yeswiki-farm-homepage' => 'PagePrincipale',

  // options d'ajout sur certaines pages
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

  // cas spécifique ou l'on veut créer un user sur le wiki source
  'yeswiki-farm-create-user' => false,

  // ajouter des valeurs dans le fichier de configuration des wikis créés
  'yeswiki-farm-extra-config' => ['BAZ_ADRESSE_MAIL_ADMIN' => 'admin@yeswiki.test'],

  // dossiers a mettre en lien symbolique dans les wikis créés
  'yeswiki_symlinked_files' => [
    'custom', // pour avoir le meme custom de partout, et ne changer qu'a un endroit
  ]
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
`--min-users=2` écartent. Pour se faire une idée avant de choisir les seuils,
`ferme:list --format=csv` sort une colonne par chiffre, qui se trie dans un tableur.
- **`ferme:clean-spam`** retire des wikis ce que les robots y ont écrit : une page
  qu'ils ont créée de toutes pièces est supprimée, une page du wiki dont ils ont
  gâché quelques lignes garde son histoire, perd ces lignes-là et passe en écriture
  au groupe admin. Tout ce qui part est écrit dans les sauvegardes de la ferme
  avant de partir. `--dry-run` d'abord, `--list` détaille page par page, `--repair`
  rend sa dernière révision à une page qui n'en a plus. `--stuck` liste les pages
  condamnées qu'aucune règle ne sait nettoyer, avec les hôtes vers lesquels elles
  pointent : c'est la boucle de sortie du dernier reliquat, un hôte ajouté à
  `yeswiki-farm-spam-hosts` et le nettoyage relancé.
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
  `--stop-on-error` arrête tout au premier échec.

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
