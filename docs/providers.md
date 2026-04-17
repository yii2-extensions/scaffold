# Creating providers

A scaffold provider is any Composer package that declares file mappings under `extra.scaffold` in its
`composer.json`. Providers do not need a special package type — any installed package can contribute scaffold files
once it appears in the root project's `allowed-packages` list.

## Inline manifest

Declare mappings directly in `composer.json` under `extra.scaffold.file-mapping`:

```json
{
  "name": "yii2-extensions/app-base-scaffold",
  "extra": {
    "scaffold": {
      "file-mapping": {
        "config/params.php": {
          "source": "stubs/config/params.php",
          "mode": "replace"
        },
        "config/web.php": {
          "source": "stubs/config/web.php",
          "mode": "preserve"
        },
        ".env.example": {
          "source": "stubs/.env.example",
          "mode": "append"
        }
      }
    }
  }
}
```

Each key is the **destination path** relative to the project root.
Each value must include `source` (path relative to the provider package root) and `mode`.

## External manifest

For larger providers, point to a `scaffold.json` file instead of embedding mappings inline:

```json
{
  "name": "yii2-extensions/app-nginx-scaffold",
  "extra": {
    "scaffold": {
      "manifest": "scaffold.json"
    }
  }
}
```

`scaffold.json` at the provider root:

```json
{
  "file-mapping": {
    "docker/nginx/nginx.conf": {
      "source": "stubs/nginx/nginx.conf",
      "mode": "replace"
    },
    "docker/nginx/default.conf": {
      "source": "stubs/nginx/default.conf",
      "mode": "preserve"
    }
  }
}
```

The plugin resolves `scaffold.json` relative to the provider's installation path inside `vendor/`.

## File modes

| Mode       | Behaviour                                                                                            |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| `replace`  | Writes the stub. Skips if the destination has been modified by the user since the last scaffold run. |
| `preserve` | Writes the stub only if the destination does not already exist on disk. Never overwrites.            |
| `append`   | Appends the stub content to the destination. Creates the file if absent.                             |
| `prepend`  | Prepends the stub content before existing destination content. Creates the file if absent.           |

See [File Modes](modes.md) for a detailed description of each mode and the hash-tracking mechanism.

## Provider layout example

```text
yii2-extensions/app-base/
├── composer.json          # declares extra.scaffold.file-mapping or extra.scaffold.manifest
├── scaffold.json          # optional external manifest
└── stubs/
    ├── config/
    │   ├── params.php
    │   └── web.php
    └── .env.example
```

## Security model

The plugin validates every mapping before writing:

- **Package allowlist** — the provider must appear in `allowed-packages`. Unknown providers cause an error and no file is written.
- **Path traversal rejection** — both `source` and `destination` are validated with `realpath()`. Any path that escapes the provider root or the project root is rejected.

## Next steps

- 🔀 [File Modes](modes.md)
- 🖥️ [Console Commands](console.md)
