# Installation guide

## System requirements

- [PHP](https://www.php.net/downloads) `8.3` or higher.
- [Composer](https://getcomposer.org/download/) `2.9` or higher.
- [Yii2](https://github.com/yiisoft/yii2) `22.x`.

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
            "allowed-packages": ["yii2-extensions/app-base-scaffold"]
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

## Register the console module (optional)

To enable `yii scaffold/*` console commands, register the module in `config/console.php`:

```php
return [
    // ...
    'modules' => [
        'scaffold' => \yii\scaffold\Module::class,
    ],
];
```

## Next steps

- ⚙️ [Configuration Reference](configuration.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
