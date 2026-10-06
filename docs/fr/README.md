# Extension ferme

Gère une ferme à wikis à partir d'un formulaire : chaque fiche du formulaire de la ferme est un wiki, et enregistrer une nouvelle fiche le crée.

## Comment marche une ferme sur Ectoplasme

Une ferme, c'est un seul **programme** YesWiki (le code : `src/`, `vendor/`, `themes/`, `extensions/`) qui sert plusieurs **instances** (les wikis). Le wiki de la ferme est une instance. Chaque wiki qu'il crée en est une autre, dans le dossier de la ferme : un `index.php` qui charge le programme partagé, son propre `yeswicli`, son `yeswiki.config.php`, ses dossiers `cache/`, `custom/`, `files/` et `private/`, et sa base de données. Aucun code n'est copié dans un wiki et aucun lien symbolique n'est créé.

La ferme ne fait rien de tout cela à la main. Elle le demande au cœur :

- **Créer un wiki** lance `core:create-instance` pour faire le dossier, puis le `core:install` du nouveau wiki pour créer sa base, sa configuration et son premier compte. La ferme règle ensuite dans cette configuration le thème, les droits et les réglages qu'elle impose, et active les extensions de `yeswiki-farm-extensions` avec `extension:enable`.
- **Créer un wiki à partir d'un modèle** dépose l'archive du modèle dans le `private/backups/` du nouveau wiki et lance `core:install --from-archive`, qui restaure ses pages, fiches, fichiers et réglages et réécrit ses liens. Le compte de la personne qui crée le wiki est ensuite ajouté avec `user:create`.
- **Supprimer un wiki** lance le `core:destroy` du wiki : d'abord une archive (gardée dans `private/backups/ferme/deleted/` de la ferme, sauf si `yeswiki-farm-archive-on-delete` vaut `false`), puis sa base et son compte. La ferme retire ensuite le dossier.
- **Archiver un wiki** lance son `core:archive`. L'archive reste dans le `private/backups/` de ce wiki et se télécharge depuis l'écran des wikis.
- **Le compte super admin et les comptes des référents** passent par les commandes `user:create`, `user:update`, `user:add-to-group` et `user:delete` du wiki.
- **L'hibernation** écrit `wiki_status: hibernate` dans la configuration du wiki par le ConfigurationService du cœur. Le HibernationService du cœur refuse alors toute écriture.

### Mettre à jour le code de la ferme

Le programme se met à jour une seule fois pour tous les wikis, depuis l'instance qui le possède (celle dont le programme et l'instance sont le même dossier, voir l'ADR-0007) : `/admin/updates` ou `./yeswicli upgrade` sur celle-ci. Chaque wiki doit ensuite lancer ses propres migrations. La ferme remarque la nouvelle version lors de son ménage périodique et lance `migrate` dans chaque wiki en arrière-plan, une fois par version (à couper avec `yeswiki-farm-migrate-on-update: false`). `ferme:migrate` fait la même chose en ligne de commande, et l'écran des wikis propose une action « Migrer » par wiki.

### Bases de données

- Quand `yeswiki-farm-db-admin-user` et `yeswiki-farm-db-admin-password` sont renseignés, chaque wiki a sa propre base et son propre compte, créés par `core:install --db-admin-user` et supprimés par `core:destroy`. C'est ce que recommande le cœur : un wiki ne détient que les accès à sa propre base.
- Sinon chaque wiki partage la base et les accès de la ferme, avec son propre préfixe de tables (`yeswiki-farm-prefix` suivi du nom du dossier). Supprimer un tel wiki ne supprime que ses tables.
- Avec `yeswiki-farm-db-driver: sqlite`, chaque wiki garde un fichier SQLite dans son dossier `private/`, qui part avec le dossier.

## Ce qui a été retiré, et pourquoi

- **Les liens symboliques et les copies de fichiers** (`yeswiki_symlinked_files`, `yeswiki-farm-lent-files`, `yeswiki_files`, `yeswiki_empty_folders`, `yeswiki-farm-extra-themes`, `yeswiki-farm-extra-tools`, `ferme:symlink`) : un wiki charge le programme partagé, il n'y a rien à copier ni à lier. Les thèmes viennent du programme ou du `custom/themes/` du wiki, les extensions du dossier `extensions/` du programme ou du `custom/extensions/` du wiki.
- **`ferme:update` et le bouton « Mettre à jour » de chaque wiki** : un wiki n'a plus de code à lui à mettre à jour. Ils sont remplacés par `ferme:migrate` et l'action « Migrer ».
- **L'extension `client/`** qui empêchait un wiki de la ferme de mettre à jour le code prêté : le cœur refuse une mise à jour du programme depuis toute instance autre que celle qui le possède.
- **Le dossier `custom` mis de côté pendant les mises à jour** : le cœur met lui-même de côté le `custom/` d'un wiki Doryphore qui passe à Ectoplasme.
- **La vérification des versions d'extensions auprès du dépôt** : chaque wiki met à jour son `custom/extensions/` avec `./yeswicli upgrade <extension>`.
- **Les modèles en export SQL** (`custom/wiki-models/`, `yeswiki-farm-models`) : un modèle est désormais une archive complète d'un wiki. Les modèles faits sous Doryphore sont à refaire.
- **`yeswiki-farm-backup-dir`, `yeswiki-farm-archive-url`, `yeswiki-farm-stats-interval`** : les fichiers de la ferme sont dans son `private/backups/ferme/`, et les statistiques suivent le ménage périodique du wiki (toutes les 30 minutes).
- **L'avertissement sur la page de suppression d'une fiche** : supprimer une fiche de la ferme, depuis n'importe quel écran, supprime désormais son wiki. Son archive est gardée dans `private/backups/ferme/deleted/`.

## Actions

### `{{adminwikis}}`

Les wikis de la ferme, sur la page AdminWikis. Les chiffres viennent de la dernière mesure (voir Statistiques).

- Les étiquettes du haut filtrent les wikis, le menu et les en-têtes de colonnes les trient.
- Déplier un wiki montre son activité sur un an, ses pages suspectes (chacune peut être marquée « pas du spam ») et ses archives.
- Le menu de chaque wiki l'ouvre, montre ou modifie sa fiche, le migre, le mesure à nouveau, l'archive, le met en hibernation ou le réveille, ajoute ou retire le super admin, nettoie son spam, écrit à son référent, change sa durée de vie, ou le supprime.
- Sélectionnez des wikis, ou tous ceux que garde le filtre, pour leur faire la même chose.
- « Chercher d'autres wikis sur ce serveur » liste les wikis du dossier de la ferme qui n'ont pas de fiche, et en donne une à ceux que vous choisissez.

### Formulaire de création

La page AjouterWiki affiche le formulaire 1100 (`bazar_farm_id`). Son champ `yeswiki` demande l'adresse du wiki et, sauf si la ferme les fixe, le nom, l'email et le mot de passe de son premier compte. Il propose aussi les thèmes, modèles, droits, options et durées de vie de la ferme quand il y en a plusieurs.

### `{{generatemodel}}`

Les modèles de la ferme, sur la page ModelesWiki. Un modèle est une archive complète d'un wiki : ses pages, fiches, fichiers et réglages. Faites-en un à partir d'un wiki de la ferme, ou de n'importe quel wiki où vous avez un compte administrateur (l'archive est récupérée comme le fait `core:clone`). Renommez un modèle, choisissez s'il est proposé à la création, ou supprimez-le.

### `{{contactwikis}}`

Les référents des wikis de la ferme et leurs adresses mail.

## Configuration

La plupart des réglages se modifient dans l'écran des réglages, rubrique « ferme ». Ceux-ci se règlent dans `yeswiki.config.php` seulement : `yeswiki-farm-themes`, `yeswiki-farm-acls`, `yeswiki-farm-options`, `yeswiki-farm-extra-config`, `yeswiki-farm-group`.

### Dossier et adresse

```php
'yeswiki-farm-root-folder' => 'wikis',
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```

Le dossier est relatif au dossier du wiki de la ferme, ou absolu. Créez-le d'abord, et réglez le serveur web pour que `https://ma.ferme.url/wikis/<dossier>/` arrive sur `<dossier>/index.php`. Avec `.`, les wikis sont créés à côté des dossiers de la ferme, et les noms comme `files` ou `private` sont refusés.

### Base de données

```php
'yeswiki-farm-db-driver' => '',
'yeswiki-farm-db-host' => '',
'yeswiki-farm-db-port' => '',
'yeswiki-farm-db-admin-user' => 'root',
'yeswiki-farm-db-admin-password' => '…',
'yeswiki-farm-prefix' => 'yeswiki_',
```

Pilote, hôte et port vides : ceux de la ferme.

### Premier compte de chaque wiki

```php
'yeswiki-farm-default-WikiAdmin' => '',
'yeswiki-farm-password-WikiAdmin' => '',
'yeswiki-farm-email-WikiAdmin' => 'bf_mail',
'yeswiki-farm-create-user' => false,
```

Nom ou mot de passe vide : la personne qui crée le wiki les choisit. `email-WikiAdmin` nomme le champ de la fiche qui contient l'email. `create-user` crée aussi ce compte dans le wiki de la ferme.

### Compte super admin

```php
'yeswiki-farm-admin-name' => 'SuperAdmin',
'yeswiki-farm-admin-pass' => 'motdepasse',
'yeswiki-farm-admin-email' => 'admin@example.org',
```

Chaque wiki a alors un bouton pour ajouter ou retirer ce compte, dans son groupe `admins`.

### Choix du thème

```php
'yeswiki-farm-themes' => [
  ['label' => 'YesWiki', 'theme' => 'yeswiki', 'squelette' => '1col.twig', 'style' => 'yeswiki.css', 'preset' => '', 'screenshot' => ''],
],
```

Un thème n'est proposé que si son dossier existe dans le `themes/` du programme ou le `custom/themes/` de la ferme. Un `screenshot` est un nom de fichier du dossier `custom/ferme/screenshots/` de la ferme.

### Droits d'accès

```php
'yeswiki-farm-acls' => [
  ['label' => 'Wiki ouvert', 'read' => '*', 'write' => '*', 'comments' => '*'],
  ['label' => 'Wiki protégé', 'read' => '{{user}}', 'write' => '{{user}}', 'comments' => '{{user}}', 'create_user' => true],
],
```

`{{user}}` est le compte que choisit la personne qui crée le wiki quand `create_user` est indiqué.

### Ajouts proposés à la création

```php
'yeswiki-farm-options' => [
  ['label' => 'Ajouter un pad', 'checked' => false, 'page' => 'PagePrincipale', 'content' => "\n[[https://pad.example.org Pad]]\n"],
],
```

Le contenu est ajouté à cette page du nouveau wiki, créée si elle n'existe pas.

### Autres réglages

```php
'yeswiki-farm-homepage' => 'PagePrincipale',
'yeswiki-farm-extensions' => ['contrib'],
'yeswiki-farm-extra-config' => ['contact_from' => 'admin@example.org'],
'yeswiki-farm-archive-on-delete' => true,
'yeswiki-farm-archive-keep' => 604800,
'yeswiki-farm-mattermost-webhook' => '',
'yeswiki-farm-migrate-on-update' => true,
```

- `extensions` : activées dans chaque nouveau wiki.
- `extra-config` : réglages donnés à chaque nouveau wiki, verrouillés dans son écran de réglages.
- `archive-keep` : durée de conservation des archives d'un wiki, en secondes, 0 pour toujours.
- `mattermost-webhook` : prévient un canal à chaque création et suppression.

### Mail de bienvenue

```php
'yeswiki-farm-welcome-mail-subject' => '',
'yeswiki-farm-welcome-mail-body' => '',
```

À la création d'un wiki, son référent reçoit son adresse et l'identifiant administrateur. Vide : le texte par défaut, `\n` pour un retour à la ligne. Variables : `{title}` `{url}` `{referent}` `{folder}` `{username}` `{lifetime}` `{expires}` `{daysLeft}` `{donateUrl}`. Une ligne contenant `{lifetime}`, `{expires}` ou `{daysLeft}` est omise pour un wiki permanent, et celle contenant `{username}` quand la ferme fixe le mot de passe.

### Wikis à durée limitée

```php
'yeswiki-farm-lifetime' => true,
'yeswiki-farm-lifetime-short' => 90,
'yeswiki-farm-lifetime-long' => 365,
'yeswiki-farm-lifetime-grace' => 180,
'yeswiki-farm-lifetime-reminders' => [30, 7],
'yeswiki-farm-donate-url' => 'https://example.org/don',
```

À la création, on choisit entre un test rapide et un test d'un an. Un admin peut aussi créer un wiki permanent.

- Test rapide : supprimé sans archive à son échéance.
- Test d'un an : à son échéance, archivé dans `private/backups/ferme/expired/` puis supprimé. L'archive et la fiche partent après `grace` jours.
- Des mails partent `reminders` jours avant l'échéance, avec un lien pour prolonger, et à l'archivage. Leurs textes sont `yeswiki-farm-lifetime-mail-subject`, `-mail-body`, `-archived-subject` et `-archived-body`.
- Les wikis enregistrés après coup restent permanents.

## Commandes

À lancer depuis le dossier du wiki de la ferme. `--wiki` vise un seul wiki, `--dry-run` montre sans rien faire.

- `ferme:list` : liste les wikis du dossier de la ferme. `--import` donne une fiche à ceux qui n'en ont pas.
- `ferme:migrate` : lance les migrations de chaque wiki.
- `ferme:config` : `--set clé=valeur`, `--unset clé`, `--smtp` (copie les réglages mail de la ferme).
- `ferme:admin` : crée ou retire (`--remove`) un compte dans chaque wiki.
- `ferme:clean-spam` : nettoie le spam. `--stuck` liste ce qu'aucune règle ne sait nettoyer, `--approved` les pages marquées « pas du spam ».
- `ferme:spam-index` : recense les lignes de liens collées d'un wiki à l'autre.
- `ferme:stats` : mesure les wikis.
- `ferme:lifetime` : envoie les rappels, supprime et archive les wikis arrivés à échéance. `--list` montre les échéances.

### Statistiques

La ferme mesure cent wikis (`yeswiki-farm-stats-per-visit`) après chaque ménage périodique du wiki de la ferme. Sur une grosse ferme, réglez `yeswiki-farm-stats-on-visit` à `false` et ajoutez au cron :

```
*/15 * * * * cd /chemin/vers/la/ferme && ./yeswicli ferme:stats --stale=10m
```

### Spam

- `yeswiki-farm-spam-threshold` : score à partir duquel un wiki est suspect (3 par défaut).
- `yeswiki-farm-spam-words` : vocabulaire suspect, en expression régulière.
- `yeswiki-farm-spam-hosts` : domaines à traiter comme du spam, par exemple `[/.]example\.com`.

Le nettoyage supprime les pages qu'un robot a créées et retire les lignes de spam des pages qu'il a modifiées, qui sont ensuite fermées en écriture. Ce qu'il retire est d'abord écrit dans `private/backups/ferme/spam/`.
