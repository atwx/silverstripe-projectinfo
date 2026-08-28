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

## What has to be installed where

The two command line tasks reach other sites through a
[SilverGate](https://github.com/atwx/silverstripe-gate-client) estate. **None of
it is a Composer dependency** — this module holds no reference to a SilverGate
class and talks to all of it over HTTP. What it needs is that the pieces exist
and are reachable:

| Where | What | Why |
| --- | --- | --- |
| the machine you work at | this module | provides the tasks and the callback route |
| the manager | `gate-manager` + `gate-api` | signs the JWT, and runs the consent flow |
| the site you are aiming at | `gate-client` | trades that JWT for a session |
| the site you pull from | this module | serves the export endpoints `pull-live` reads |

`gate-api` is only wanted on the manager. A target site needs `gate-client`
alone: both tasks arrive as an ordinary logged-in session through
`/_silvergateclient/token/…`, not through the content API.

`tasks:remote` additionally needs the account the manager signs for to hold
`ADMIN` on the target site, because `/dev/tasks` is gated on it.

Without any of this the module still installs and the panel still works. The
tasks simply have nothing to talk to.

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

## tasks:pull-live

Copies a live site onto the machine you are sitting at: database first, then
assets, then imports both.

```bash
sake tasks:pull-live -u www.example.com
sake tasks:pull-live -u www.example.com --only-db
sake tasks:pull-live -u www.example.com --only-assets
```

The dump lands in `_livedata/db/`, the assets in `_livedata/assets/`, and both
are then imported over the local database and `ASSETS_PATH`. The download step
talks to the export endpoints above, so the module has to be installed on the
site being pulled from as well.

| Option | |
| --- | --- |
| `-u`, `--url` | the site to pull from, with or without the `www.` |
| `--only-db` / `--only-assets` | skip the other half |
| `--http-user` / `--http-pass` | HTTP basic auth, if the site is behind it |
| `-t`, `--token` | a personal access token instead of the browser flow |
| `-i`, `--intranet-url` | a manager other than the default |

Importing runs `mysql` against the credentials in the local environment. It
overwrites the local database without asking.

## tasks:remote

Runs a build task **on** a managed site.

```bash
sake tasks:remote SendMessagesTask -u www.example.com
sake tasks:remote ExportEmployers -u www.example.com > employers.csv
sake tasks:remote ExportEmployers -u www.example.com -- --with-offers
```

There is no API behind this and none is needed: the site's own `/dev/tasks`
runner does the work, reached with the session the module already establishes.
Silverstripe streams task output as it is produced, so it arrives line by line
rather than in one lump at the end. Everything the module itself has to say —
progress, the authorisation link, errors — goes to **stderr**, which is what
makes redirecting the output with a plain `>` give you just the task's own
output.

Anything after a `--` is passed on to the remote task as options.

> **Options with a shortcut cannot be passed by their long name.**
> `HttpRequestInput` in the framework overwrites the long name's value with the
> shortcut's, so `--only-db` arrives and `--url=…` does not, while `-u=…` does.
> Declare options without a shortcut on tasks meant to be run this way.

A task whose name the site does not know is reported as a failure. The runner
answers `200` either way and says so only in the page, so the exit code comes
from reading that.

## Authorisation

Both tasks need to prove who they are to the manager, which then signs a token
for the target site. There are two ways.

**Through the browser, by default.** The first call prints a link to the
manager's consent screen; approving it once yields a grant that renews itself
quietly from then on. Nothing to copy, nothing to keep in an environment file.
`tasks:pull-live` asks for read access, `tasks:remote` for write.

**With a personal access token**, by passing `--token`. Useful where no browser
is available, in cron or CI. These rotate, so they have to be fetched again each
time.

The grant lives in `~/.silvergate/<manager-host>.json`, one file per manager and
readable only by its owner. Deleting it asks for consent again.

### How the code gets back to the command line

A command line process cannot receive a browser redirect, and a loopback port
inside a container is not reachable from the browser on the host. The
development site is, so it is used as the landing spot:

```
CLI      prints the consent link, waits
browser  you approve at the manager
manager  redirects to https://<your-dev-site>/_silvergateauth?code=…
site     OAuthCallbackController leaves the code in the temp directory
CLI      picks it up, exchanges it, carries on
```

The command line process and the web server run as the same user on the same
machine, so a file is all the two of them need. The code on its own is worth
nothing: exchanging it takes the PKCE verifier, which never leaves the process
that started the flow.

That route is registered **only in dev mode**, and the controller checks again
before answering. It has no business existing on a production site.

The callback URL is taken from `DDEV_PRIMARY_URL`, falling back to
`SS_BASE_URL`. `Director::absoluteBaseURL()` is deliberately last: on the command
line it reports the container's internal hostname, which is neither reachable
from a browser nor `https`.

### www

Sites are recorded at the manager with or without a `www.`, and `-u` accepts
either. The manager answers with the spelling it actually knows, and the tasks
follow it from there — putting a `www.` in front of a site that has none tends
to fail the certificate check, so guessing is not an option.

## Requirements

Silverstripe 6 and `silverstripe/admin` 3, which between them settle the PHP
version. Dumping uses [spatie/db-dumper](https://github.com/spatie/db-dumper);
importing shells out to `mysql`, so that has to be on the path of whatever runs
`tasks:pull-live`.
