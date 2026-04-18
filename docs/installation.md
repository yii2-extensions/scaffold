# Installation guide

## System requirements

- [PHP](https://www.php.net/downloads) `8.3` or higher.
- [Composer](https://getcomposer.org/download/) `2.9` or higher.

The plugin is framework-agnostic. It works in Yii2, Yii3, Laravel, Symfony, Slim, Mezzio, or plain
PHP projects as long as they use Composer.

## Installation

Add the plugin to your project:

```bash
composer require yii2-extensions/scaffold
```

Because `yii2-extensions/scaffold` is a Composer plugin, you must allow it explicitly:

```bash
composer config allow-plugins.yii2-extensions/scaffold true
```

Or add the entry manually to your `composer.json`:

```json
{
    "config": {
        "allow-plugins": {
            "yii2-extensions/scaffold": true
        }
    }
}
```

## Authorize scaffold providers

Declare which packages are permitted to write files into your project under `extra.scaffold.allowed-packages`.
The plugin ignores all providers that are not listed here.

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": ["yii2-extensions/app-base"]
        }
    }
}
```

Run `composer install` or `composer update` to trigger the scaffold process.
The plugin applies all file mappings declared by the listed providers and writes `scaffold-lock.json`.

## Commit the lockfile

`scaffold-lock.json` records the hash of every file written by the scaffold process.
Commit it to version control alongside `composer.lock` so that collaborators and CI
can detect user-modified files and reproduce the exact scaffold state.

```bash
git add scaffold-lock.json
git commit -m "chore: add scaffold-lock.json"
```

## Invoke the console CLI

After `composer install`, the Symfony Console CLI is available at `vendor/bin/scaffold`:

```bash
vendor/bin/scaffold list                      # discover available commands
vendor/bin/scaffold status                    # what changed since last scaffold?
vendor/bin/scaffold providers                 # list providers + file counts
vendor/bin/scaffold diff config/params.php    # review changes on a single file
vendor/bin/scaffold reapply --force           # replay stubs, overwriting user edits
vendor/bin/scaffold eject config/params.php --yes   # detach a file from the lock
```

No framework bootstrap is required — the binary starts directly from Composer's autoloader.

## Next steps

- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
