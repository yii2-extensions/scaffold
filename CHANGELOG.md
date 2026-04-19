# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## 0.1.0 Under development

- feat: initial `yii2-extensions/scaffold` package structure.
- fix: update package names in documentation and code references to reflect new naming conventions.
- refactor: extract `PathResolver` to centralize destination, source, directory and provider-root resolution.
- test: raise Infection MSI and Covered Code MSI to `100%` via targeted tests, `xepozz/internal-mocker` fixtures, and explicit ignores for POSIX-equivalent mutants.
- feat: record `providers[name]` in `scaffold-lock.json` as `{version, path}` with project-root-relative paths so committed locks stay stable across machines.
- test: add real-Composer functional tests for `post-install-cmd`, `post-create-project-cmd`, and multi-layer provider precedence.
- test: cover `eject`, `providers`, and `reapply` console commands via buffered-output spies (un-final'd for subclassing); add `docs/testing.md` and a lock-example fix.
- test: raise suite to 100% line/method/class coverage by exercising error paths via `xepozz/internal-mocker` intercepts and removing dead defensive code.
- feat: add `scaffold/help` console command listing module subcommands with descriptions.
- feat: port console commands to Symfony Console and ship `vendor/bin/scaffold` as a standalone CLI usable from Yii2, Yii3, Laravel, Symfony, or plain PHP projects.
- test: kill remaining path-normalization mutants and keep Infection MSI at `100%`.
