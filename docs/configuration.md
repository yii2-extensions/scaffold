# Configuration reference

All scaffold configuration lives under the `extra.scaffold` key in the root project's `composer.json`.
Provider packages are never trusted unless they appear in `allowed-packages`.

## allowed-packages

**Required.** An ordered list of Composer package names that are permitted to contribute scaffold files.

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": [
                "yii2-extensions/app-base",
                "yii2-extensions/app-nginx",
                "yii2-extensions/app-vue"
            ]
        }
    }
}
```

**Ordering matters.** When two providers declare the same destination file, the provider listed **later** wins.
This allows specialised layers to override base-layer defaults without forking them.

If the list is empty or absent the plugin writes a notice and exits without modifying any files.

## auto

**Optional, defaults to `true`.** Controls whether the plugin auto-triggers on Composer script events
(`post-install-cmd`, `post-update-cmd`, `post-create-project-cmd`).

```json
{
    "extra": {
        "scaffold": {
            "auto": false,
            "allowed-packages": ["php-forge/baseline"]
        }
    }
}
```

When `auto: false`, the plugin remains installed and authorised but does not hook into Composer events.
The CLI commands at `vendor/bin/scaffold` continue to work, so the consumer can sync provider templates
on demand:

```bash
vendor/bin/scaffold reapply --provider=php-forge/baseline
vendor/bin/scaffold diff <file>
vendor/bin/scaffold status
```

Use this when **explicit, opt-in updates** matter more than continuous synchronisation, for example on
repositories where every change to project standards should be reviewed before being applied.

Only an explicit `false` disables the auto-trigger; missing, `null`, non-boolean, or any other value defaults to
enabled to avoid silent disable on typo.

## Full annotated example

```json
{
    "require": {
        "yii2-extensions/scaffold": "^0.1",
        "yii2-extensions/app-base": "^0.1"
    },
    "config": {
        "allow-plugins": {
            "yii2-extensions/scaffold": true
        }
    },
    "extra": {
        "scaffold": {
            "allowed-packages": ["yii2-extensions/app-base"]
        }
    }
}
```

## scaffold-lock.json

The plugin writes `scaffold-lock.json` at the project root after every successful run.
It records the SHA-256 hash, provider name, source path, and mode for every file it has written.

**Commit this file.** It serves the same role as `composer.lock`: it lets collaborators and CI
reproduce the exact scaffold state and detect files that have been modified after scaffolding.

Example lock entry:

```json
{
    "providers": {
        "yii2-extensions/app-base": {
            "version": "0.1.0",
            "path": "vendor/yii2-extensions/app-base"
        }
    },
    "files": {
        "config/params.php": {
            "hash": "sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            "provider": "yii2-extensions/app-base",
            "source": "stubs/config/params.php",
            "mode": "replace"
        }
    }
}
```

Provider paths are recorded **relative to the project root** so the lockfile stays stable across developer machines.
Versions come from Composer's `getPrettyVersion()`.

## Event differentiation

| Composer event            | Behaviour                                                                                                                          |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `post-create-project-cmd` | Full scaffold: all modes applied, all files written, lock created.                                                                 |
| `post-install-cmd`        | Partial scaffold: `append` and `prepend` files already in the lock are skipped. `replace` files whose hashes match are re-applied. |
| `post-update-cmd`         | Same as `post-install-cmd`.                                                                                                        |

## Next steps

- 📖 [Readme](../README.md)
- 📦 [Creating Providers](providers.md)
- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
