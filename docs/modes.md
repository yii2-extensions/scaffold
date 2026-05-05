# File modes

Every file mapping in a provider manifest declares a `mode` that controls how the plugin writes
the stub file into the project. The mode also determines how the hash-tracking mechanism reacts
when a file has been modified by the developer after scaffolding.

## Mode reference

| Mode       | File absent                | File present (unmodified)                                                                                                                                     | File present (user-modified)                                                                          |
| ---------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `replace`  | Writes stub, records hash. | Overwrites with stub, updates hash.                                                                                                                           | **Skips** emits warning to stderr.                                                                    |
| `preserve` | Writes stub, records hash. | **Skips**; never overwrites.                                                                                                                                  | **Skips**; never overwrites.                                                                          |
| `append`   | Writes stub, records hash. | Appends only stub lines not already present in the file (idempotent line merge); updates hash. Returns `Skipped` when every stub line is already in the file. | Appends only stub lines not already present in the file (idempotent line merge); user lines are kept. |
| `prepend`  | Writes stub, records hash. | Prepends stub content before existing content, updates hash (full scaffold only; skipped on `post-install-cmd`/`post-update-cmd` if already in lock).         | Prepends stub content, updates hash (full scaffold only).                                             |

## Hash tracking

After writing a file the plugin computes `sha256:<hash_file('sha256', $path)>` and records it in `scaffold-lock.json`.
On subsequent runs the plugin compares the current on-disk hash to the recorded hash:

- **Hashes match**; the file has not been changed since the last scaffold run. The plugin re-applies the stub
  (for `replace`) or skips (for `preserve`).
- **Hashes differ**; the developer has modified the file. For `replace`, the plugin skips the file and writes a warning
  to stderr. For `preserve`, the file is always skipped regardless of hash.

`append` does not compare hashes; instead it diffs the stub lines against the destination and writes only the missing
ones. The operation is therefore idempotent repeated runs over a file that already contains every stub line are a no-op.
On partial scaffold runs (`post-install-cmd`, `post-update-cmd`), `append` and `prepend` are also skipped at the
scaffolder level for files already recorded in the lockfile as a performance optimisation; the line-diff guarantees
correctness even without that skip.

## replace

Intended for configuration files that the scaffold system owns. The developer is expected to leave
these files as-is or accept that custom changes may be warned about on re-scaffold.

```json
"config/params.php": {
    "source": "stubs/config/params.php",
    "mode": "replace"
}
```

## preserve

Intended for files that provide sensible defaults but are designed to be customised by the developer.
Once written, the file is never touched again by the scaffold process.

```json
"config/db.php": {
    "source": "stubs/config/db.php",
    "mode": "preserve"
}
```

## append

Idempotent line merge. The plugin reads both the stub and the destination, splits each on `\n` (after trimming the
trailing newline), and appends only the stub lines that are not already present in the destination. Repeated runs over
a destination that already contains every stub line are a no-op (`ApplyOutcome::Skipped`); developer-added lines are
never removed.

Use it for line-based, comment-tolerant files where the destination may already contain the stub content (because the
project follows the same baseline) or where the developer routinely adds project-specific entries:

- `.gitignore`, `.gitattributes`, `.editorconfig`
- `.prettierignore`, `.stylelintignore`
- Environment variable lists, plain-text allowlists

```json
".gitignore": {
    "source": "stubs/gitignore-additions.txt",
    "mode": "append"
}
```

> **Note:** `append` operates at the line level. It is not safe for structured documents (JSON, YAML, TOML, XML); use
> `replace` or `preserve` for those.

## prepend

Prepends the stub content before the existing destination content.
Useful for inserting PHP `declare(strict_types=1)` headers, autoload includes, or licence headers.

```json
"bootstrap/web.php": {
    "source": "stubs/bootstrap-header.php",
    "mode": "prepend"
}
```

## --force reapply workflow

When a `replace` file has been user-modified, the scaffold process warns and skips it.
To intentionally overwrite the file with the current stub, use the console command:

```bash
vendor/bin/scaffold reapply config/params.php --force
```

This overwrites the file, computes the new hash, and updates `scaffold-lock.json`.

## Next steps

- 📖 [Readme](../README.md)
- 🖥️ [Console Commands](console.md)
- ⚙️ [Configuration Reference](configuration.md)
