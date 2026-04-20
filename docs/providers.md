# Creating providers

A scaffold provider is a Composer package that declares which of its files scaffold should copy into consumer projects
on `composer install`.

Providers do not need a special package type; any installed package can contribute scaffold files once it appears in the
root project's `allowed-packages` list.

## Manifest shape

Every provider declares a `scaffold` manifest with three keys:

- `copy` (required, array of paths) — directories or files relative to the provider root. Directories are walked
  recursively.
- `exclude` (optional, array of glob patterns) — patterns omitted from the walk. Only applies to directory entries in
  `copy`; explicit file entries bypass this filter.
- `modes` (optional, map of glob pattern → [file mode](modes.md)) — overrides the default write mode per destination.
  Exact path matches win over glob matches; the default when no pattern matches is `replace`.

## Inline manifest

Declare under `extra.scaffold` in the provider's `composer.json`:

```json
{
    "name": "yii2-extensions/app-backend",
    "type": "yii2-scaffold",
    "extra": {
        "scaffold": {
            "copy": [
                "src",
                "config",
                "migrations",
                "public",
                "resources",
                "yii",
                ".env.dist",
                ".gitignore"
            ],
            "modes": {
                "config/*.php": "preserve",
                "public/assets/.gitkeep": "preserve",
                ".env.dist": "preserve",
                ".gitignore": "append"
            }
        }
    }
}
```

## External manifest

For larger providers, point to a `scaffold.json` file next to `composer.json`:

```json
{
    "name": "yii2-extensions/app-backend",
    "type": "yii2-scaffold",
    "extra": {
        "scaffold": {
            "manifest": "scaffold.json"
        }
    }
}
```

`scaffold.json` at the provider root uses the same `copy` / `exclude` / `modes` shape:

```json
{
    "copy": [
        "src",
        "config",
        "migrations",
        "public",
        "resources",
        "yii",
        ".env.dist"
    ],
    "exclude": ["config/test-local.php"],
    "modes": {
        "config/*.php": "preserve",
        "public/assets/.gitkeep": "preserve",
        ".env.dist": "preserve"
    }
}
```

The plugin resolves the path relative to the provider's installation directory inside `vendor/`.

## Glob syntax

Patterns in `exclude` and the keys of `modes` support:

| Token   | Matches                                                                            |
| ------- | ---------------------------------------------------------------------------------- |
| `*`     | Any sequence of characters that does NOT include `/`.                              |
| `**`    | Any sequence of characters including `/` (crosses directories).                    |
| `**/`   | Zero or more directory levels (so `**/.gitignore` also matches root `.gitignore`). |
| `?`     | A single character that is not `/`.                                                |
| literal | Any other character matches byte-exact.                                            |

## File modes

| Mode       | Behaviour                                                                                            |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| `replace`  | Writes the stub. Skips if the destination has been modified by the user since the last scaffold run. |
| `preserve` | Writes the stub only if the destination does not already exist on disk. Never overwrites.            |
| `append`   | Appends the stub content to the destination. Creates the file if absent.                             |
| `prepend`  | Prepends the stub content before existing destination content. Creates the file if absent.           |

See [File Modes](modes.md) for a detailed description of each mode and the hash-tracking mechanism.

## Default excludes

The following patterns are hardcoded and always skipped when walking a directory listed in `copy`, so providers never
accidentally ship their own development metadata:

```text
composer.json, composer.lock, vendor/**
.git/**, .github/**, .gitattributes, .gitignore
tests/**, phpunit.xml, phpunit.xml.dist, .phpunit.cache/**, phpunit.cache/**
phpstan.neon, phpstan.neon.dist, phpstan.cache/**
infection.json5, infection.json, infection.log, .infection/**
.editorconfig, .php-cs-fixer.php, .php-cs-fixer.dist.php, ecs.php
psalm.xml, psalm.xml.dist
README.md, CHANGELOG.md, LICENSE, docs/**
scaffold.json, scaffold-lock.json
runtime/**
```

A provider that needs to distribute a file matching one of these patterns (for example, a `runtime/.gitignore` template)
can list the exact path as an explicit file entry in `copy`; explicit file entries bypass the default excludes.

## Provider layout

Because `copy` walks the provider tree directly, providers look like real Yii2 apps — there is no `stubs/` wrapper and
no per-file declaration:

```text
yii2-extensions/app-backend/
├── composer.json          # declares extra.scaffold or extra.scaffold.manifest
├── scaffold.json          # optional external manifest
├── src/                   # copied verbatim into the consumer's src/
├── config/
├── migrations/
├── public/
├── resources/
├── yii
├── tests/                 # default-excluded, lives in the provider for its own CI
├── phpunit.xml            # default-excluded
├── phpstan.neon           # default-excluded
└── infection.json5        # default-excluded
```

This layout lets the provider be a standalone Yii2 application: you can run `composer install` inside it, then
`./vendor/bin/phpunit`, `./vendor/bin/phpstan`, and `composer mutation-static` to prove the distributed code works
before any consumer depends on it.

## Security model

The plugin validates every entry before writing:

- **Package allowlist**: the provider must appear in `allowed-packages`. Unknown providers cause an error and no file
  is written.
- **Path traversal rejection**: entries in `copy` and `exclude` must be relative and must not contain `..` segments.
  Absolute paths and Windows drive letters are rejected.
- **File existence**: every path listed in `copy` must exist on disk at expansion time; missing entries fail the run
  loudly instead of silently skipping.
- **Walk boundary**: the filesystem walk is constrained to the provider's installation directory inside `vendor/`.

## Next steps

- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
