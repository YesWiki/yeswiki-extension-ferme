# ferme extension

**Ferme, or how to create wikis from your wiki.**

With the ferme extension, filling in a form gives you a new wiki.

The click is easy, and that is the danger: creating wikis is quick, keeping them alive
and maintained is not. The farm helps you maintain them, but nothing is worse than
useless wikis that make cooperation look like it does not work.

> The French documentation is the reference and carries more narrative detail on the
> admin page. This page covers every section and every setting.

## Foreword

The Ferme extension was written mostly by Mrflos, alias Florian Schmitt. It answers a
need YesWiki users kept voicing: creating wikis easily, without FTP or MySQL knowledge.

### What the farm lets you do

- **find every wiki installed on your server** and administer them from one interface
- **write to the people responsible** for the farm's wikis, one by one or in batches, to
  warn them of a migration, a shutdown or a new feature
- **follow each wiki's activity**: entries, pages, accounts, files, disk space and date
  of last change, all kept up to date on their own
- **create new wikis** through a form
- **import a wiki and turn it into a model** from another server

### What it is useful for

- simply managing the wikis installed on your server
- installing new wikis very easily
- letting members or colleagues create their own wiki
- designing prepared wiki models to save time

## Actions

### Administering your farm's wikis: `{{adminwikis}}`

Once the farm is installed, "Gestion ferme à wikis" in the cog wheel opens the page
listing every wiki created.

A banner at the top gives the farm totals, including the number of wikis in service and
in hibernation, each clickable to show only those, plus labels that filter the list in
one click: to update, dormant, original content, heavy archives, suspect, spammed
content, in error, never measured, in hibernation.

"Original content" keeps the wikis still holding exactly what their model gave them:
either nobody ever wrote anything, or somebody tried, five pages at most, and never came
back in six months. A wiki installed less than a month ago is not listed, it has not had
its chance yet. A model's pages are written in one go, so anything arriving five minutes
later was typed by a person, and page rewrites from farm updates, which carry nobody's
name, do not count. A hibernating wiki is not counted among the dormant ones: it is
silent on purpose.

A "Sort by" menu orders wikis by title, responsible person, last activity, total
activity, entries, pages, accounts, forms or disk space, and the button beside it flips
the order. The table headers, name, content, last activity and disk, are clickable to
sort on them, a second click flips the order, and the menu follows. The chosen label, the
sort and the search are written into the page address: the link you copy reopens the same
list.

The table holds five columns:

- **Wiki name**: its title, leading to its bazar entry on the master wiki, an arrow to
  open the wiki itself in a new tab, the responsible person and their email, its version
  and its super admin account if it has one. Labels are added where relevant: "custom mis
  de côté" left by an interrupted update, "suspect" when the name smells of spam, "en
  hibernation" when the wiki takes no more writes, or the error hit at the last
  measurement. The unfoldable detail always states the status, even for a wiki never
  measured.
- **Content**: what the wiki holds, entries, pages, forms, accounts. A question mark as
  long as it has never been measured.
- **Last activity**: a small graph of the last twelve months, and "5 d ago" below, or the
  date itself beyond three months.
- **Disk**: the space its files take, and how many there are.
- **Actions**: the chevron unfolds the wiki detail (the figures in columns, the install
  date, the number of pages touched since, the measurement date, and the wiki's activity
  over the year as a calendar); the ⋯ button opens the menu: view the bazar entry, edit
  it, update to the master wiki's version, add or remove the super admin account, and
  delete the wiki. That last entry opens the deletion page in a new tab, keeping the list
  behind, and that page recalls in red what is about to disappear.

The unfoldable detail of a wiki labelled "spammed content" lists the pages at fault, each
with a link opening it in a new tab, the reason it is flagged, and what cleaning would do
to it, or, when no rule knows how to clean it, that it needs a human look. Each page
carries a "this is not spam" link: an honest list of resources is made of links and
nothing else, no rule will ever tell it apart, so you decide once and the farm remembers.
What it remembers is the page as you approved it: if the robot comes back, it becomes
spam again on its own. A "put back as spam" link cancels the approval, and the detail
keeps the list of approved pages even when the wiki has left the "spammed content" label,
without which a page approved by mistake would be out of reach.

These figures are not recomputed on every display: the farm measures wikis in the
background, and only recounts those that moved. On a large farm, better to run
`ferme:stats` from a scheduled task and set "stats on visit" to false.

At the bottom of the page: a "Select this page" checkbox, a "Select the N wikis" button
taking every wiki the current search and label retain, beyond the hundred displayed, a
"Deselect all" link, and, as soon as one wiki is ticked, an "Actions on the selection"
block under the table. It carries the number of ticked wikis and sorts the actions into
four columns: update, content, accounts and contact, wiki life.

- **Update the selected wikis**: files, migrations, and each wiki's own extensions.
- **Update only the extensions**: a wiki's own extensions move to the version published
  for the YesWiki version it runs, then their migrations run. The core is untouched.
- **Restore the custom folders**: an interrupted update can leave a wiki without its
  custom folder, set aside under the name custom.temp. Wikis in that state carry the
  "custom mis de côté" label under their title, and this entry puts them back.
- **Recompute the statistics**: measures the ticked wikis again straight away, without
  waiting for the next pass.
- **Look for spam (changing nothing)** and **Clean the spam**: the first says, wiki by
  wiki, which pages would go and which would be cleaned; the second does it. A
  hibernating wiki is cleaned like the others: it is woken for the cleaning and put back
  to sleep afterwards, even if the cleaning fails. A page the robot created outright is
  deleted; a page of yours whose few lines it spoiled keeps its history and loses those
  lines, and its write access moves to the admin group. Everything that goes is written
  to the farm backups first, so it can come back.
- **Add** or **Remove the admin account** on the selected wikis.
- **Send an email**: a message to the people responsible for the ticked wikis, whose
  template can name the wiki, its address and its last activity.
- **Back up the selected wikis**: each wiki builds its own archive, hibernating or not.
  The archive itself comes back into service, so that restoring gives a working wiki. A
  download button appears per wiki, and the archive leaves the wiki once downloaded.
- **Put into hibernation**: the chosen wikis stay readable but refuse every write, pages,
  entries, accounts, comments. That is what you do with a wiki nobody maintains any more,
  rather than delete it. **While a wiki sleeps the farm no longer changes it**: no update,
  no admin account. Lending the code as symbolic links is the exception, like cleaning the
  spam: the wiki is woken for the swap and put back to sleep after, even when the swap
  fails. It then serves code identical to the code it held. It can still be deleted, since
  hibernation protects a wiki from being changed, not from being thrown away, and the
  deletion page recalls that it was asleep.
- **Wake from hibernation**: they become writable again.
- **Delete the selected wikis**, after confirmation. Deleting an entry from bazar also
  erases the wiki: the deletion page says so in red and recalls what the wiki holds before
  you confirm.

Each run shows the wiki in progress and its rank out of the total, and an error on one
does not stop the others. Deletions go in batches of five, five batches at a time, which
gets through hundreds without losing the afternoon.

A "Look for other wikis on this server" button inspects the server and hands back the
list of installed wikis with no entry, to tick for import. Nothing is imported without
your click.

### The new wiki creation page

Form 1100 in input mode. Fill in the entry and your wiki is created. By default only a
basic wiki is offered.

### Creating a wiki model: `{{generatemodel}}`

By giving the address of another wiki you built, or one that looks like a good base, this
page turns it into a model offered among the install choices.

1. Enter the address of the wiki you prepared as a model, or one that inspires you.
2. Click "importer".
3. Click "Générer le fichier MYSQL modèle pour ce wiki".
4. Find the new line in "Modèles de contenus disponibles".
5. Tick the box at the start of the line and save.

You now have a new wiki type offered when installing through the farm.

## Configurable items

### Super admin accounts to administer hosted wikis

Settable in "Fichier de conf".

- add a super administrator to each wiki, to override or make up for that wiki's own
  admin account
- remove that super admin account per wiki

Two ways to do it. Either go to "gestion du site" / "Fichier de conf" / "Ferme à wikis",
fill in "Nom du compte super-administrateur..." and "Mot de passe de ce compte
super-administrateur" and validate. Each setting there carries a sentence saying what it
is for, with its key beside it. Or add these two lines to `wakka.config.php`:

```php
'yeswiki-farm-admin-name' => 'NomWikidusuperadmin',
'yeswiki-farm-admin-pass' => 'votremotdepasse',
```

An "ajouter le compte" button then appears beside every wiki on the administration page.
Once a super admin account exists for a wiki, that button turns red and reads "supprimer
le compte". Pressing it removes the super admin account on that wiki only.

### Wiki storage folder

Settable in "Fichier de conf".

By default a wiki created by the farm has its files placed in a folder named after it, at
the root of the farm wiki. To keep your wikis' folders away from the farm's own, two
settings work together: the storage folder name, and the base URL of the farm's wikis.

The folder must exist on your server.

**The folder name** uses `yeswiki-farm-root-folder`, the relative path of the storage
folder. For wikis created in a `wikis` subfolder of the farm:

```php
'yeswiki-farm-root-folder' => 'wikis',
```

The default is `'yeswiki-farm-root-folder' => '.'`.

**The wikis' base URL** uses `yeswiki-farm-root-url`. With a farm at
`https://ma.ferme.url/` and wikis in its `wikis` subfolder:

```php
'yeswiki-farm-root-url' => 'https://ma.ferme.url/wikis/',
```

There is no default.

Careful, those two settings must agree. Setting `yeswiki-farm-root-folder` to `wikis`
without also setting `yeswiki-farm-root-url` means the created wikis can never be
reached.

### Extra themes

Settable in "Fichier de conf".

Additional themes, copied into each created wiki on top of the core ones. They must be
installed in the farm wiki's `themes` folder, otherwise creation reports it and moves on.

```php
'yeswiki-farm-extra-themes' => ['montheme'],
```

### Theme selection interface

Settable in `wakka.config.php` only.

The array of theme choices, which is not displayed when only one choice exists. The
screenshot is a filename to drop into `tools/ferme/screenshots/`, not an address: a
missing file is simply ignored and the theme stays on offer without an image.

```php
'yeswiki-farm-themes' => [
    [
      'label' => 'Margot (thème par défaut de YesWiki)', // name on screen
      'screenshot' => 'margot.jpg', // file in tools/ferme/screenshots
      'theme' => 'margot', // theme name
      'squelette' => '1col.tpl.html', // default skeleton
      'style' => 'margot.css' // default style
    ],
    [
      'label' => 'Margot clair',
      'screenshot' => false, // no screenshot
      'theme' => 'margot',
      'squelette' => '1col.tpl.html',
      'style' => 'light.css'
    ],
  ],
```

### Extra tools

Settable in "Fichier de conf".

Additional extensions, copied into each created wiki on top of the core ones. They must
be present in the farm wiki's `tools` folder.

```php
'yeswiki-farm-extra-tools' => [],
```

### Access rights

Settable in `wakka.config.php` only.

Offers a choice of access rights, not displayed when only one choice exists.

```php
'yeswiki-farm-acls' => [
    [
      'label'    => 'Wiki ouvert', // description of the access rights
      'read'     => '*', // read
      'write'    => '*', // write
      'comments' => '*'  // comments
    ],
],
```

### Other settings

The French page also covers the main page name, the additions offered at creation, the
account on the farm wiki, and the settings inherited by created wikis. Each of them is
described in "Fichier de conf" with its key beside it.

## Running the farm

### Updating a wiki

Update each wiki. A wiki counts as up to date when it runs the same version as the master
wiki, so keep your main wiki up to date.

### Deleting a wiki

The interface offers a bin button, deleting the wiki entry and the content that goes with
it. Deleting through the bazar interface works too, and triggers the deletion of that
wiki.

### One job at a time per wiki

Creating, updating and deleting a wiki cannot happen at the same time on the same wiki.
The second job does not wait: it gives up saying what the other one is doing, and a queue
of deletions or updates moves on to the next wiki.

A copy that did not bring everything is a failed copy: an incomplete creation fails and
erases the folder it started, and an update stops before stamping the version.

### What the statistics pass tidies up

`ferme:stats`, which runs anyway, takes the opportunity to give each wiki the `private`
folder, and `private/backups`, that it should have, and to throw away the archives nobody
came to fetch. One week by default, `yeswiki-farm-archive-keep` decides, and 0 keeps them
forever. On a farm of 3,149 wikis that pass costs a tenth of a second.

## Fetching the sql files of source wikis

The sql files of the wikis meant to serve as farm models can be fetched automatically.
See the `yeswiki-farm-models` setting in `wakka.config.php`.

1. Put `{{generatemodel}}` in a page to get the import module.
2. On that page, in the "Générer un modèle à partir d'une adresse URL" field, enter the
   source wiki's URL.
3. Click "Importer".
4. A description of that wiki's content appears. Click "Générer le fichier MySQL modèle
   pour ce wiki".
5. A message says the sql file named after the source wiki has been generated and copied
   into the master wiki's `tools/ferme/sql` folder.
6. Then edit `wakka.config.php`:

```php
  // model folders in `custom/wiki-models`
  'yeswiki-farm-models' => [
    'default-content', // special alias for the default installation model
    'pnth-terreenaction.org--sourcecollectif',
  ],
```

## Command line

They run from the farm wiki root, like every YesWiki command:

```
./yeswicli ferme:list
./yeswicli ferme:config --smtp --dry-run
./yeswicli ferme:admin --user Support --password 'une longue phrase de passe'
./yeswicli ferme:update
```

The four commands share the way wikis are chosen: with nothing, they work on the farm's
wikis; `--path` scans another folder, `--depth` says how far down to go, `--wiki` targets
one folder only. The master wiki is never a target of `ferme:update`. A wiki the user
running the command cannot write to is reported as a failure: there is no `sudo` here.
`--dry-run` shows what would be done without writing anything.

**`ferme:list`** says, for each wiki found, whether it has an entry in the farm form, what
it holds (pages, entries, accounts, last change, from the statistics already measured),
whether its database answers, whether a table is missing, and who administers it.
`--format=json|csv` outputs the same for another program. `--import` creates the missing
entries, with the email of the first admin of the wiki concerned.

On a farm open for a long time, the vast majority of entry-less folders are wikis created
and never used, many of them spam: importing them all would build hundreds of pointless
entries. Five filters restrict the import to what deserves an entry, all based on the
statistics already measured, and therefore free:

```
./yeswicli ferme:list --import --dry-run \
    --min-entries=15 --min-users=2 --active-since=180d \
    --name-excludes='bet|casino|win|slot|clb|essay|writing|homework'
```

`--min-entries`, `--min-pages`, `--min-users` and `--active-since` set aside what was
never used, `--name-excludes` sets aside by name. The report says how many wikis each
filter left out, and a wiki never measured is left out rather than guessed at. Always
start with `--dry-run`.

Each wiki also gets a **suspicion score**, computed at measurement time and stored with
the rest. Five signals, calibrated on a farm of 3,735 wikis: writing in another alphabet,
and betting, escort or homework-selling vocabulary, are worth 3 points each; a link in the
name, a name in a language other than the farm's, and a wiki that never went beyond its
model are worth 2 each. From 3 points, so as soon as a content signal is present and not
only inactivity, the wiki is flagged: 500 wikis out of 3,735, two of them wrongly on
reading the list back. The "looks like spam" label gathers them on the admin page,
`--skip-suspect` keeps them out of an import, and `yeswiki-farm-spam-threshold` and
`yeswiki-farm-spam-words` tune the severity and the vocabulary.

A wiki straight out of its model that nobody touched has a recognisable signature, around
128 pages, 9 entries and 1 account: that is what `--min-entries=15` or `--min-users=2` set
aside. `--never-edited` answers the same question with no threshold to guess: it keeps
only the wikis still holding what their model gave them, meaning those where nobody ever
wrote anything, and those where somebody touched five pages at most and never came back in
six months.
