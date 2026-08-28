# Silverstripe Project Info

Tells you what a Silverstripe install is running on, hands out its database and
assets, and lets you reach another install from your own command line.

The module wears two hats depending on where it is installed. On a **live site**
it exposes the panel and the export endpoints, so the site can be copied and
inspected. On a **development machine** it provides the tasks that go and fetch
that copy, or run a build task on the live site without opening a browser.
Installing it in both places is the normal case.

## Installation

```bash
composer require atwx/silverstripe-projectinfo
```

Nothing needs configuring for the settings panel, and nothing else has to be
installed for it.

## The Project Info panel

`ProjectInfoExtension` adds a **Project Info** tab to the CMS settings area
showing the database name and the size of the assets directory, with buttons for
the export endpoints below it.

`LeftAndMainBackupExport` puts those endpoints on the settings controller:

| Endpoint | Returns |
| --- | --- |
| `admin/settings/doBackup` | a `.sql` dump of the database |
| `admin/settings/doDownloadAssets` | every asset as one ZIP |
| `admin/settings/doListAssets` | the asset tree as JSON |
| `admin/settings/doDownloadAsset?path=…` | one asset by its relative path |

The methods carry no permission check of their own. They are actions on the
settings controller, and it is `LeftAndMain` that gates them: an unauthenticated
request to any of the four is redirected to the login. Keep them there — moved
onto a plain `Controller` they would hand out the database to anyone.

The last two exist because a large assets directory is better fetched file by
file than as a single archive that times out, which is exactly what
`tasks:pull-live` does.

## Pulling this site somewhere else

The endpoints above are what
[`atwx/silverstripe-gate-tasks`](https://github.com/atwx/silverstripe-gate-tasks)
reads when it copies a site onto a development machine. That module used to live
here; it moved out so this one could go back to being about the panel and the
export.

## Requirements

Silverstripe 6 and `silverstripe/admin` 3, which between them settle the PHP
version. Dumping uses [spatie/db-dumper](https://github.com/spatie/db-dumper).
