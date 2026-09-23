# ferme extension

Creates and manages YesWiki wikis from a bazar form.

## Actions

### `{{adminwikis}}`

Lists the farm's wikis. Open it from "Gestion ferme à wikis" in the cog menu.

- Filter with the labels at the top, sort with the menu or the column headers.
- Unfold a wiki to see its detail. On a spammed wiki, "this is not spam" approves a page.
- A wiki's ⋯ menu lets you view, edit, update or delete it.

Actions on the selection:

- update, or update extensions only
- restore a wiki after an interrupted update
- recompute statistics
- look for or clean spam
- add or remove the super admin account
- email the people responsible
- back up
- hibernate (read only) or wake
- delete

"Look for other wikis on this server" imports installed wikis that have no entry.

### Creation form

Form 1100 in input mode creates a wiki.

### `{{generatemodel}}`

Turns an existing wiki into an install model.

1. Enter the source wiki URL, click "Importer".
2. Click "Générer le fichier MySQL modèle pour ce wiki".
3. Add the model to `yeswiki-farm-models`:

```php
'yeswiki-farm-models' => [
  'default-content',
  'model-name',
],
```

## Configuration

Most settings are edited in "Gestion du site" > "Fichier de conf" > "Ferme à wikis". These are set in `wakka.config.php` only: `yeswiki-farm-themes`, `-acls`, `-options`, `-models`, `-extra-config`, `yeswiki_symlinked_files`.

### Super admin account

```php
'yeswiki-farm-admin-name' => 'SuperAdmin',
'yeswiki-farm-admin-pass' => 'password',
```

Each wiki then gets a button to add or remove this account.

### Wiki folder and URL

```php
'yeswiki-farm-root-folder' => 'wikis',
'yeswiki-farm-root-url' => 'https://my.farm.url/wikis/',
```

Create the folder first. Both settings must point to the same place.

### Copied themes and extensions

```php
'yeswiki-farm-extra-themes' => ['mytheme'],
'yeswiki-farm-extra-tools' => [],
```

Install them on the farm first.

### Theme choice

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

`screenshot`: a file in `tools/ferme/screenshots/`, or `false`.

### Access rights

```php
'yeswiki-farm-acls' => [
  ['label' => 'Open wiki', 'read' => '*', 'write' => '*', 'comments' => '*'],
  ['label' => 'Protected wiki', 'read' => '{{user}}', 'write' => '{{user}}', 'comments' => '{{user}}', 'create_user' => true],
],
```

### Additions offered at creation

```php
'yeswiki-farm-options' => [
  [
    'label' => 'Add a pad',
    'checked' => false,
    'page' => 'PageMenuHaut',
    'content' => " - [[EtherPad Pad]]\n",
  ],
],
```

### Other settings

```php
'yeswiki-farm-homepage' => 'PagePrincipale',
'yeswiki-farm-extra-config' => ['BAZ_ADRESSE_MAIL_ADMIN' => 'admin@example.org'],
'yeswiki-farm-archive-keep' => 604800,
'yeswiki-farm-mattermost-webhook' => '',
'yeswiki-farm-migrate-on-update' => true,
```

- `extra-config`: settings given to created wikis.
- `archive-keep`: how long backups are kept, in seconds, 0 for ever.
- `mattermost-webhook`: notifies a channel on each creation or deletion.
- `migrate-on-update`: migrates the wikis when the master wiki is updated.

### Files shared by symbolic link

```php
'yeswiki_symlinked_files' => ['javascripts', 'vendor', 'styles', 'includes', 'lang', 'tools/bazar'],
```

Created wikis share this code with the farm instead of copying it. `[]` to copy everything. `ferme:symlink` does the same for existing wikis.

## Commands

Run from the farm root. `--wiki` targets a single wiki, `--dry-run` shows without doing anything.

- `ferme:list`: lists wikis. `--import` creates missing entries, `--skip-suspect` leaves out suspect ones.
- `ferme:update`: brings wikis to the master version. `--workers=4` runs several at once.
- `ferme:symlink`: shares the farm's code with existing wikis. `--undo` to revert.
- `ferme:config`: `--set key=value`, `--unset key`, `--smtp`.
- `ferme:admin`: creates or removes (`--remove`) an admin account in each wiki.
- `ferme:clean-spam`: cleans spam.
- `ferme:spam-index`: finds spam copied across several wikis.
- `ferme:stats`: updates statistics.

Start with `--dry-run`.

### Statistics

On a large farm, set `yeswiki-farm-stats-on-visit` to `false` and add to cron:

```
*/15 * * * * cd /path/to/farm && php includes/commands/console ferme:stats --stale=10m
```

### Spam

- `yeswiki-farm-spam-threshold`: score from which a wiki is suspect (default 3).
- `yeswiki-farm-spam-words`: suspect vocabulary.
- `yeswiki-farm-spam-hosts`: domains to treat as spam, for example `[/.]example\.com`.
