# Extension ferme

Crée et administre des wikis YesWiki à partir d'un formulaire bazar.

## Actions

### `{{adminwikis}}`

Liste les wikis de la ferme. Accessible par "Gestion ferme à wikis" dans la roue crantée.

- Filtrer avec les étiquettes en haut de page, trier avec le menu ou les en-têtes.
- Déplier un wiki pour voir son détail. Sur un wiki spammé, "ce n'est pas du spam" valide une page.
- Le menu ⋯ d'un wiki permet de le voir, l'éditer, le mettre à jour ou le supprimer.

Actions sur la sélection :

- mettre à jour, ou seulement les extensions
- rétablir un wiki après une mise à jour interrompue
- recalculer les statistiques
- chercher ou nettoyer le spam
- ajouter ou retirer le compte super admin
- envoyer un mail aux personnes référentes
- sauvegarder
- mettre en hibernation (lecture seule) ou en sortir
- supprimer

"Rechercher d'autres wikis sur ce serveur" importe les wikis installés sans fiche.

### Formulaire de création

Le formulaire 1100 en mode saisie crée un wiki.

### `{{generatemodel}}`

Transforme un wiki existant en modèle d'installation.

1. Saisir l'URL du wiki source, cliquer sur "Importer".
2. Cliquer sur "Générer le fichier MySQL modèle pour ce wiki".
3. Ajouter le modèle à `yeswiki-farm-models` :

```php
'yeswiki-farm-models' => [
  'default-content',
  'nom-du-modele',
],
```

## Configuration

La plupart des réglages se modifient dans "Gestion du site" > "Fichier de conf" > "Ferme à wikis". Les suivants se règlent dans `wakka.config.php` uniquement : `yeswiki-farm-themes`, `-acls`, `-options`, `-models`, `-extra-config`, `yeswiki_symlinked_files`.

### Compte super admin

```php
'yeswiki-farm-admin-name' => 'SuperAdmin',
'yeswiki-farm-admin-pass' => 'motdepasse',
```

Chaque wiki a alors un bouton pour ajouter ou supprimer ce compte.

### Dossier et URL des wikis

```php
'yeswiki-farm-root-folder' => 'wikis',
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```

Créer le dossier avant. Les deux réglages doivent pointer au même endroit.

### Thèmes et extensions copiés

```php
'yeswiki-farm-extra-themes' => ['montheme'],
'yeswiki-farm-extra-tools' => [],
```

Les installer d'abord sur la ferme.

### Choix de thème

```php
'yeswiki-farm-themes' => [
  [
    'label' => 'Margot',
    'screenshot' => 'margot.jpg',
    'theme' => 'margot',
    'squelette' => '1col.tpl.html',
    'style' => 'margot.css',
  ],
],
```

`screenshot` : un fichier de `tools/ferme/screenshots/`, ou `false`.

### Droits d'accès

```php
'yeswiki-farm-acls' => [
  ['label' => 'Wiki ouvert', 'read' => '*', 'write' => '*', 'comments' => '*'],
  ['label' => 'Wiki protégé', 'read' => '{{user}}', 'write' => '{{user}}', 'comments' => '{{user}}', 'create_user' => true],
],
```

### Ajouts proposés à la création

```php
'yeswiki-farm-options' => [
  [
    'label' => 'Intégrer un pad',
    'checked' => false,
    'page' => 'PageMenuHaut',
    'content' => " - [[EtherPad Pad]]\n",
  ],
],
```

### Autres réglages

```php
'yeswiki-farm-homepage' => 'PagePrincipale',
'yeswiki-farm-extra-config' => ['BAZ_ADRESSE_MAIL_ADMIN' => 'admin@exemple.org'],
'yeswiki-farm-archive-keep' => 604800,
'yeswiki-farm-mattermost-webhook' => '',
'yeswiki-farm-migrate-on-update' => true,
```

- `extra-config` : réglages donnés aux wikis créés.
- `archive-keep` : durée de conservation des sauvegardes en secondes, 0 pour toujours.
- `mattermost-webhook` : prévient un salon à chaque création ou suppression.
- `migrate-on-update` : migre les wikis quand le wiki maître est mis à jour.

### Wikis à durée limitée

```php
'yeswiki-farm-lifetime' => true,
'yeswiki-farm-lifetime-short' => 90,
'yeswiki-farm-lifetime-long' => 365,
'yeswiki-farm-lifetime-grace' => 180,
'yeswiki-farm-lifetime-reminders' => [30, 7],
'yeswiki-farm-lifetime-mail-subject' => '',
'yeswiki-farm-lifetime-mail-body' => '',
'yeswiki-farm-lifetime-archived-subject' => '',
'yeswiki-farm-lifetime-archived-body' => '',
'yeswiki-farm-donate-url' => 'https://exemple.org/dons',
```

À la création, on choisit entre test rapide et test annuel. Un admin peut aussi créer un wiki permanent.

- Test rapide : effacé sans sauvegarde à l'échéance.
- Test annuel : à l'échéance, le wiki est sauvegardé dans `private/backups/farm/expired/` puis fermé. La sauvegarde et la fiche sont effacées après `grace` jours.
- Des mails partent `reminders` jours avant l'échéance, avec un lien pour prolonger. Un autre part à l'archivage. Leurs textes sont réglables (`mail-*`, `archived-*`), `\n` pour aller à la ligne.
- Dans le dernier mois, le bouton "Prolonger" apparaît aussi sur la fiche du wiki.
- Variables du mail : `{title}` `{url}` `{referent}` `{lifetime}` `{expires}` `{daysLeft}` `{renewUrl}` `{pages}` `{entries}` `{users}` `{files}` `{lastActivity}` `{donateUrl}`.
- Dans `{{adminwikis}}`, la ligne "Durée de vie" filtre par type (test rapide, test annuel, permanent), la ligne "Échéance" en dessous par état (échéance lointaine, bientôt échus, archivés). Le menu ⋯ d'un wiki permet de le prolonger ou de changer sa durée.

Les wikis existants restent permanents.

### Fichiers partagés par lien symbolique

```php
'yeswiki_symlinked_files' => ['javascripts', 'vendor', 'styles', 'includes', 'lang', 'tools/bazar'],
```

Les wikis créés partagent ce code avec la ferme au lieu de le copier. `[]` pour tout copier. `ferme:symlink` fait de même sur les wikis existants.

## Commandes

Depuis la racine de la ferme. `--wiki` vise un seul wiki, `--dry-run` montre sans rien faire.

- `ferme:list` : liste les wikis. `--import` crée les fiches manquantes, `--skip-suspect` écarte les suspects.
- `ferme:update` : met les wikis à la version du maître. `--workers=4` en traite plusieurs à la fois.
- `ferme:symlink` : partage le code de la ferme avec les wikis existants. `--undo` pour revenir.
- `ferme:config` : `--set cle=valeur`, `--unset cle`, `--smtp`.
- `ferme:admin` : crée ou retire (`--remove`) un compte admin dans chaque wiki.
- `ferme:clean-spam` : nettoie le spam.
- `ferme:spam-index` : repère le spam copié sur plusieurs wikis.
- `ferme:stats` : met à jour les statistiques.
- `ferme:lifetime` : envoie les rappels, efface et archive les wikis échus. `--list` affiche les échéances.

Commencer par `--dry-run`.

### Statistiques

Sur une grosse ferme, passer `yeswiki-farm-stats-on-visit` à `false` et ajouter au cron :

```
*/15 * * * * cd /chemin/ferme && php includes/commands/console ferme:stats --stale=10m
```

### Spam

- `yeswiki-farm-spam-threshold` : score à partir duquel un wiki est suspect (défaut 3).
- `yeswiki-farm-spam-words` : vocabulaire suspect.
- `yeswiki-farm-spam-hosts` : domaines à traiter comme spam, par exemple `[/.]exemple\.com`.

