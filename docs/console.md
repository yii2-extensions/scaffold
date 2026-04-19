# Console commands

The scaffold plugin ships a standalone [Symfony Console](https://symfony.com/doc/current/components/console.html) CLI
at `vendor/bin/scaffold`. It works in any PHP project (Yii2, Yii3, Laravel, Symfony, plain PHP) with no framework
bootstrap required the binary starts directly from Composer's autoloader.

Run `vendor/bin/scaffold list` to discover every available command; each entry below also responds to
`--help` for detailed option documentation.

## vendor/bin/scaffold status

Reads `scaffold-lock.json` and compares the recorded hash of every tracked file to its current on-disk hash.
Outputs a status table.

```bash
vendor/bin/scaffold status
```

Example output:

```text
File                                     Provider                       Mode       Status
--------------------------------------------------------------------------------------------
config/params.php                        yii2-extensions/app-base       replace    synced
config/web.php                           yii2-extensions/app-base       preserve   synced
.env.example                             yii2-extensions/app-base       append     modified
docker/nginx/nginx.conf                  yii2-extensions/app-nginx      replace    missing
```

| Status     | Meaning                                                        |
| ---------- | -------------------------------------------------------------- |
| `synced`   | On-disk hash matches the hash recorded at scaffold time.       |
| `modified` | The file has been changed since the last scaffold run.         |
| `missing`  | The file was written by scaffold but no longer exists on disk. |

## vendor/bin/scaffold diff `<file>`

Shows a line-by-line diff between the provider stub and the current on-disk file.

```bash
vendor/bin/scaffold diff config/params.php
```

Lines present only in the stub are prefixed with `-`, lines present only in the current file with `+`, and unchanged
lines with two spaces.

```diff
<?php
- return [];
+ return ['adminEmail' => 'admin@example.com'];
```

## vendor/bin/scaffold reapply `[file]` `[--force]` `[--provider=<name>]`

Re-copies stubs from `vendor/` to the project, updating `scaffold-lock.json` hashes on success.

```bash
# reapply a single file
vendor/bin/scaffold reapply config/params.php

# reapply all files from one provider
vendor/bin/scaffold reapply --provider=yii2-extensions/app-base

# reapply all tracked files
vendor/bin/scaffold reapply

# overwrite even user-modified files
vendor/bin/scaffold reapply config/params.php --force
```

Without `--force`, files whose on-disk hash differs from the lock hash are reported and skipped.
With `--force`, user-modified files are overwritten and the lock hash is updated.

## vendor/bin/scaffold eject `<file>` `[--yes]`

Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
After ejection the file is no longer managed by scaffold.

```bash
# preview what would happen
vendor/bin/scaffold eject config/params.php

# perform the ejection
vendor/bin/scaffold eject config/params.php --yes
```

Without `--yes`, the command only describes what would happen and exits without modifying the lock.

## vendor/bin/scaffold providers

Lists all providers recorded in `scaffold-lock.json` with their file counts.

```bash
vendor/bin/scaffold providers
```

Example output:

```text
Provider                                     Files
----------------------------------------------------
yii2-extensions/app-base                     4
yii2-extensions/app-nginx                    2
```

## Typical post-install workflow

```bash
# 1. Check what changed after composer update
vendor/bin/scaffold status

# 2. Review a modified file
vendor/bin/scaffold diff config/params.php

# 3a. Accept the stub version (overwrite)
vendor/bin/scaffold reapply config/params.php --force

# 3b. Keep your version and stop tracking it
vendor/bin/scaffold eject config/params.php --yes
```

## Exit codes

All commands follow standard Symfony Console conventions:

| Code | Meaning                                                                                                                      |
| ---- | ---------------------------------------------------------------------------------------------------------------------------- |
| `0`  | Success (including preview / no-op runs).                                                                                    |
| `1`  | Recoverable error (for example, untracked file passed to `diff` / `eject`, or filter in `reapply` matched no tracked files). |
| `2`  | Input validation error raised by Symfony Console (for example, missing required argument).                                   |

Use the exit code in CI scripts to halt on failures (for example, `vendor/bin/scaffold status` always returns `0`
regardless of `modified` / `missing` entries inspect the text output yourself if you want CI to gate on drift).
