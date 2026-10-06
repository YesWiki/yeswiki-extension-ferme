# ferme extension

Runs a wiki farm from a form: each entry of the farm form is a wiki, and saving a new entry creates it.

## How a farm works on Ectoplasme

A farm is one YesWiki **Program** (the code: `src/`, `vendor/`, `themes/`, `extensions/`) serving several **Instances** (the wikis). The farm wiki is an Instance. Each wiki it creates is another Instance, in the farm's folder: an `index.php` loading the shared Program, its own `yeswicli`, its `yeswiki.config.php`, its `cache/`, `custom/`, `files/` and `private/` folders, and its database. No code is copied into a wiki and no symbolic link is made.

The farm does none of this by hand. It asks core:

- **Creating a wiki** runs `core:create-instance` to make the folder, then the new wiki's own `core:install` to create its database, its configuration and its first account. The farm then sets the theme, access rights and settings it imposes in that configuration, and switches on the extensions listed in `yeswiki-farm-extensions` with `extension:enable`.
- **Creating a wiki from a model** places the model's archive in the new wiki's `private/backups/` and runs `core:install --from-archive`, which restores its pages, entries, files and settings and rewrites its links. The creator's account is then added with `user:create`.
- **Deleting a wiki** runs the wiki's own `core:destroy`: an archive first (kept in the farm's `private/backups/ferme/deleted/`, unless `yeswiki-farm-archive-on-delete` is `false`), then its database and account. The farm removes the folder afterwards.
- **Archiving a wiki** runs its `core:archive`. The archive stays in that wiki's `private/backups/` and can be downloaded from the wikis screen.
- **The super admin account and the referents' accounts** go through the wiki's `user:create`, `user:update`, `user:add-to-group` and `user:delete`.
- **Hibernation** writes `wiki_status: hibernate` in the wiki's configuration through core's ConfigurationService. Core's HibernationService then refuses every write.

### Updating the code of the farm

The Program is updated once for every wiki, from the Instance that owns it (the one where the Program and the Instance are the same folder, see ADR-0007): `/admin/updates` or `./yeswicli upgrade` there. Each wiki then has to run its own migrations. The farm notices the new version on its housekeeping sweep and runs `migrate` in every wiki in the background, once per version (switch it off with `yeswiki-farm-migrate-on-update: false`). `ferme:migrate` does the same from a shell, and the wikis screen has a "Migrate" action per wiki.

### Databases

- When `yeswiki-farm-db-admin-user` and `yeswiki-farm-db-admin-password` are set, each wiki gets a database and an account of its own, created by `core:install --db-admin-user` and dropped by `core:destroy`. This is what core recommends: a wiki holds credentials to its own database and nothing else.
- Otherwise each wiki shares the farm's database and credentials under a table prefix of its own (`yeswiki-farm-prefix` followed by the folder name). Deleting such a wiki drops its tables only.
- With `yeswiki-farm-db-driver: sqlite`, each wiki keeps an SQLite file in its `private/` folder, which goes with the folder.

## What was removed, and why

- **Symbolic links and file copies** (`yeswiki_symlinked_files`, `yeswiki-farm-lent-files`, `yeswiki_files`, `yeswiki_empty_folders`, `yeswiki-farm-extra-themes`, `yeswiki-farm-extra-tools`, `ferme:symlink`): a wiki loads the shared Program, there is nothing to copy or link. Themes come from the Program or from the wiki's `custom/themes/`, extensions from the Program's `extensions/` or the wiki's `custom/extensions/`.
- **`ferme:update` and the per wiki "Update" button**: a wiki no longer has code of its own to bring up to date. They are replaced by `ferme:migrate` and the "Migrate" action.
- **The `client/` extension** that stopped a farm wiki from updating lent code: core refuses a core update from any Instance but the one owning the Program.
- **The `custom` folder put aside during updates**: core sets a Doryphore `custom/` aside itself when a wiki moves to Ectoplasme.
- **Extension versions checked against the repository**: each wiki upgrades its own `custom/extensions/` with `./yeswicli upgrade <extension>`.
- **SQL dump models** (`custom/wiki-models/`, `yeswiki-farm-models`): a model is now a full archive of a wiki. Models made on Doryphore have to be made again.
- **`yeswiki-farm-backup-dir`, `yeswiki-farm-archive-url`, `yeswiki-farm-stats-interval`**: the farm's files live in its `private/backups/ferme/`, and the statistics follow the wiki's housekeeping sweep (every 30 minutes).
- **The warning on an entry's deletion page**: deleting a farm entry, from any screen, now deletes its wiki. Its archive is kept in `private/backups/ferme/deleted/`.

## Actions

### `{{adminwikis}}`

The farm's wikis, on the AdminWikis page. The figures come from the last measurement (see Statistics).

- The labels at the top filter the wikis, the menu and the column headers sort them.
- Unfold a wiki to see its activity over a year, its suspicious pages (each one can be marked as not spam) and its archives.
- Each wiki's menu opens it, shows or edits its entry, migrates it, measures it again, archives it, puts it to sleep or wakes it, adds or removes the super admin, cleans its spam, writes to its referent, changes its lifetime, or deletes it.
- Select wikis, or every wiki the filter keeps, to do the same to all of them.
- "Look for other wikis on this server" lists the wikis found in the farm's folder that have no entry, and gives an entry to those you pick.

### Creation form

The AjouterWiki page shows form 1100 (`bazar_farm_id`). Its `yeswiki` field asks for the address of the wiki and, unless the farm sets them, the name, email and password of its first account. It also offers the farm's themes, models, access rights, options and lifetimes when there are several.

### `{{generatemodel}}`

The farm's models, on the ModelesWiki page. A model is a full archive of a wiki: its pages, entries, files and settings. Make one from a wiki of the farm, or from any wiki where you have an administrator account (the archive is fetched the way `core:clone` fetches one). Rename a model, choose whether creators are offered it, or delete it.

### `{{contactwikis}}`

The referents of the farm's wikis and their email addresses.

## Configuration

Most settings are edited in the settings screen, under "ferme". These are set in `yeswiki.config.php` only: `yeswiki-farm-themes`, `yeswiki-farm-acls`, `yeswiki-farm-options`, `yeswiki-farm-extra-config`, `yeswiki-farm-group`.

### Folder and address

```php
'yeswiki-farm-root-folder' => 'wikis',
'yeswiki-farm-root-url' => 'https://my.farm.url/wikis/',
```

The folder is relative to the farm wiki's folder, or absolute. Create it first, and point the web server at it so that `https://my.farm.url/wikis/<folder>/` reaches `<folder>/index.php`. With `.`, the wikis are made next to the farm's own folders, and folder names like `files` or `private` are refused.

### Database

```php
'yeswiki-farm-db-driver' => '',
'yeswiki-farm-db-host' => '',
'yeswiki-farm-db-port' => '',
'yeswiki-farm-db-admin-user' => 'root',
'yeswiki-farm-db-admin-password' => '…',
'yeswiki-farm-prefix' => 'yeswiki_',
```

Empty driver, host and port mean the farm's own.

### First account of each wiki

```php
'yeswiki-farm-default-WikiAdmin' => '',
'yeswiki-farm-password-WikiAdmin' => '',
'yeswiki-farm-email-WikiAdmin' => 'bf_mail',
'yeswiki-farm-create-user' => false,
```

Empty name or password: the creator chooses them. `email-WikiAdmin` names the entry field holding the email. `create-user` also creates that account in the farm wiki.

### Super admin account

```php
'yeswiki-farm-admin-name' => 'SuperAdmin',
'yeswiki-farm-admin-pass' => 'password',
'yeswiki-farm-admin-email' => 'admin@example.org',
```

Each wiki then gets a button to add or remove this account, in its `admins` group.

### Theme choice

```php
'yeswiki-farm-themes' => [
  ['label' => 'YesWiki', 'theme' => 'yeswiki', 'squelette' => '1col.twig', 'style' => 'yeswiki.css', 'preset' => '', 'screenshot' => ''],
],
```

A theme is offered only when its folder exists in the Program's `themes/` or the farm's `custom/themes/`. A `screenshot` is a file name in the farm's `custom/ferme/screenshots/`.

### Access rights

```php
'yeswiki-farm-acls' => [
  ['label' => 'Open wiki', 'read' => '*', 'write' => '*', 'comments' => '*'],
  ['label' => 'Protected wiki', 'read' => '{{user}}', 'write' => '{{user}}', 'comments' => '{{user}}', 'create_user' => true],
],
```

`{{user}}` is the account the creator chooses when `create_user` is set.

### Additions offered at creation

```php
'yeswiki-farm-options' => [
  ['label' => 'Add a pad', 'checked' => false, 'page' => 'PagePrincipale', 'content' => "\n[[https://pad.example.org Pad]]\n"],
],
```

The content is appended to that page of the new wiki, which is created when missing.

### Other settings

```php
'yeswiki-farm-homepage' => 'PagePrincipale',
'yeswiki-farm-extensions' => ['contrib'],
'yeswiki-farm-extra-config' => ['contact_from' => 'admin@example.org'],
'yeswiki-farm-archive-on-delete' => true,
'yeswiki-farm-archive-keep' => 604800,
'yeswiki-farm-mattermost-webhook' => '',
'yeswiki-farm-migrate-on-update' => true,
```

- `extensions`: switched on in every new wiki.
- `extra-config`: settings given to every new wiki, locked in its settings screen.
- `archive-keep`: how long a wiki's archives are kept, in seconds, 0 for ever.
- `mattermost-webhook`: notifies a channel on each creation and deletion.

### Welcome mail

```php
'yeswiki-farm-welcome-mail-subject' => '',
'yeswiki-farm-welcome-mail-body' => '',
```

When a wiki is created, its referent gets its address and the admin username. Empty means the default text, `\n` is a line break. Placeholders: `{title}` `{url}` `{referent}` `{folder}` `{username}` `{lifetime}` `{expires}` `{daysLeft}` `{donateUrl}`. A line holding `{lifetime}`, `{expires}` or `{daysLeft}` is left out for a permanent wiki, and the line holding `{username}` when the farm sets the password.

### Limited-lifetime wikis

```php
'yeswiki-farm-lifetime' => true,
'yeswiki-farm-lifetime-short' => 90,
'yeswiki-farm-lifetime-long' => 365,
'yeswiki-farm-lifetime-grace' => 180,
'yeswiki-farm-lifetime-reminders' => [30, 7],
'yeswiki-farm-donate-url' => 'https://example.org/donate',
```

People choose between a quick test and a yearly test. An admin can also create a permanent wiki.

- Quick test: deleted with no archive on its deadline.
- Yearly test: on its deadline, archived into `private/backups/ferme/expired/` and deleted. The archive and the entry go after `grace` days.
- Mails go out `reminders` days before the deadline, with a link to renew, and on archiving. Their texts are `yeswiki-farm-lifetime-mail-subject`, `-mail-body`, `-archived-subject` and `-archived-body`.
- Wikis registered after the fact stay permanent.

## Commands

Run from the farm wiki's folder. `--wiki` targets one wiki, `--dry-run` shows without doing anything.

- `ferme:list`: lists the wikis in the farm's folder. `--import` gives an entry to those that have none.
- `ferme:migrate`: runs each wiki's migrations.
- `ferme:config`: `--set key=value`, `--unset key`, `--smtp` (copies the farm's mail settings).
- `ferme:admin`: creates or removes (`--remove`) an account in each wiki.
- `ferme:clean-spam`: cleans spam. `--stuck` lists what no rule can clean, `--approved` the pages marked as not spam.
- `ferme:spam-index`: learns the lines of links pasted across several wikis.
- `ferme:stats`: measures the wikis.
- `ferme:lifetime`: sends reminders, deletes and archives expired wikis. `--list` shows deadlines.

### Statistics

The farm measures a hundred wikis (`yeswiki-farm-stats-per-visit`) after each housekeeping sweep of the farm wiki. On a large farm, set `yeswiki-farm-stats-on-visit` to `false` and add to cron:

```
*/15 * * * * cd /path/to/farm && ./yeswicli ferme:stats --stale=10m
```

### Spam

- `yeswiki-farm-spam-threshold`: score from which a wiki is suspect (default 3).
- `yeswiki-farm-spam-words`: suspect vocabulary, as a regular expression.
- `yeswiki-farm-spam-hosts`: domains to treat as spam, for example `[/.]example\.com`.

Cleaning deletes the pages a robot created and strips the spam lines from the pages it edited, which are then closed to writers. What it removes is written first to `private/backups/ferme/spam/`.
