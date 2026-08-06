# Silverstripe Module for admin information
Displays information about the current Silverstripe installation in the settings admin.

## Branches

| Branch | Silverstripe | Composer constraint |
| --- | --- | --- |
| `main` | 6 (`framework ^6`, `admin ^3`) | `^1.0` |
| `ss5` | 4 / 5 (`framework ^4 \|\| ^5`, `admin ^1 \|\| ^2`) | `dev-ss5` |

## Pull Live

The `PullLiveTask` downloads the database and the assets from a live site and imports
them locally. Authentication happens via [silverstripe-gate-client](https://github.com/atwx/silverstripe-gate-client),
so the module has to be installed and configured on the remote site as well.

On `main` (Silverstripe 6) the task uses the Symfony console options:

```bash
sake tasks:pull-live -u docs.atw.io -t <token>
```

On `ss5` the Silverstripe 4/5 `BuildTask` API has no options, so the parameters are
passed as GET vars instead:

```bash
vendor/bin/sake dev/tasks/pull-live site=docs.atw.io token=<token>
```

Available parameters: `site`, `token`, `intranet-url`, `only-db`, `only-assets`,
`http-user`, `http-pass`.

Note the remote site is `site` here, not `url` as on `main`. Silverstripe's
`CLIRequestBuilder` overwrites the `url` GET var with the route being called, so a
`url` parameter would never reach the task.

Downloads land in `_livedata/db` and `_livedata/assets` before being imported into the
database and `ASSETS_PATH`.
