# Console commands

The `yii scaffold/*` commands give developers visibility into and control over the scaffold state
without running a full `composer install`.

## Module registration

Add the module to `config/console.php` before using any command:

```php
return [
    // ...
    'modules' => [
        'scaffold' => \yii\scaffold\Module::class,
    ],
];
```

## yii scaffold/status

Reads `scaffold-lock.json` and compares the recorded hash of every tracked file to its current
on-disk hash. Outputs a status table.

```bash
yii scaffold/status
```

Example output:

```
File                                     Provider                       Mode       Status
--------------------------------------------------------------------------------------------
config/params.php                        yii2-extensions/app-base       replace    synced
config/web.php                           yii2-extensions/app-base       preserve   synced
.env.example                             yii2-extensions/app-base       append     MODIFIED
docker/nginx/nginx.conf                  yii2-extensions/app-nginx      replace    missing
```

| Status     | Meaning                                                        |
| ---------- | -------------------------------------------------------------- |
| `synced`   | On-disk hash matches the hash recorded at scaffold time.       |
| `MODIFIED` | The file has been changed since the last scaffold run.         |
| `missing`  | The file was written by scaffold but no longer exists on disk. |

## yii scaffold/diff `<file>`

Shows a line-by-line diff between the provider stub and the current on-disk file.

```bash
yii scaffold/diff config/params.php
```

Lines present only in the stub are prefixed with `- `, lines present only in the current file
with `+ `, and unchanged lines with two spaces.

```
  <?php
- return [];
+ return ['adminEmail' => 'admin@example.com'];
```

## yii scaffold/reapply `[file]` `[--force]` `[--provider=]`

Re-copies stubs from `vendor/` to the project, updating `scaffold-lock.json` hashes on success.

```bash
# reapply a single file
yii scaffold/reapply config/params.php

# reapply all files from one provider
yii scaffold/reapply --provider=yii2-extensions/app-base

# reapply all tracked files
yii scaffold/reapply

# overwrite even user-modified files
yii scaffold/reapply config/params.php --force
```

Without `--force`, files whose on-disk hash differs from the lock hash are reported and skipped.
With `--force`, user-modified files are overwritten and the lock hash is updated.

## yii scaffold/eject `<file>` `[--yes]`

Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
After ejection the file is no longer managed by scaffold.

```bash
# preview what would happen
yii scaffold/eject config/params.php

# perform the ejection
yii scaffold/eject config/params.php --yes
```

Without `--yes`, the command only describes what would happen and exits without modifying the lock.

## yii scaffold/providers

Lists all providers recorded in `scaffold-lock.json` with their file counts.

```bash
yii scaffold/providers
```

Example output:

```
Provider                                     Files
----------------------------------------------------
yii2-extensions/app-base                     4
yii2-extensions/app-nginx                    2
```

## Typical post-install workflow

```bash
# 1. Check what changed after composer update
yii scaffold/status

# 2. Review a modified file
yii scaffold/diff config/params.php

# 3a. Accept the stub version (overwrite)
yii scaffold/reapply config/params.php --force

# 3b. Keep your version and stop tracking it
yii scaffold/eject config/params.php --yes
```
