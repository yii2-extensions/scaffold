# Testing guide

This document describes how the test suite is organized, how to run specific subsets, and how to add new fixtures or
tests when contributing.

## Suite layout

```text
tests/
├── unit/                  # pure, fast unit tests per src/ namespace
│   ├── Commands/          # console controllers via spies (no real stdout writes)
│   ├── Manifest/
│   ├── Modes/
│   ├── Scaffold/
│   │   └── Lock/
│   └── Security/
├── functional/            # real Composer instance, event-driven, temporary project
├── support/               # shared helpers, spies, traits
│   └── Spies/             # Controller subclasses that buffer stdout/stderr
└── fixtures/              # static manifests / invalid payloads used as read-only inputs
    └── providers/
```

The `unit` and `functional` groups are reflected in PHPUnit `#[Group]` attributes. Every test also belongs to the
`scaffold` meta-group.

## Running the suite

```bash
vendor/bin/phpunit                              # full suite, quiet
vendor/bin/phpunit --group commands             # only console-controller tests
vendor/bin/phpunit --group functional           # only Composer-driven end-to-end tests
vendor/bin/phpunit --filter MultiLayer          # substring filter on test class name
```

## Unit tests

Unit tests exercise a single class in isolation. Controller tests use **spies** under `tests/support/Spies/` because
Yii's console `Controller::stdout()` writes directly to the `STDOUT` stream, which bypasses PHPUnit's output capture.
Each spy extends its production controller and overrides `stdout()`/`stderr()` so tests can assert on buffered output.

```php
final class ProvidersControllerTest extends TestCase
{
    public function testExample(): void
    {
        $controller = new ProvidersControllerSpy('providers', $module);
        $controller->actionIndex();

        self::assertStringContainsString('pkg/name', $controller->stdoutBuffer);
    }
}
```

Production controllers are declared without `final` precisely to support these spies.

## Functional tests

Functional tests spin up a **real `Composer\Composer` instance** against a fake project generated in a temporary
directory, seed its local repository with mock provider packages, and dispatch the same script events the plugin
subscribes to in production. They exercise the entire chain

```text
Script event → EventSubscriber → Scaffolder → modes → lock file
```

without the cost or flakiness of a full `composer install`.

The `ComposerEventHarness` trait at `tests/support/ComposerEventHarness.php` centralizes the boilerplate:

```php
final class MyTest extends TestCase
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function testExample(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('demo/scaffold', 'stubs/config/app.php', "<?php\n");
        $builder->createComposerJson([
            'name' => 'demo/smoke-project',
            'config' => ['vendor-dir' => $builder->getVendorDir()],
            'extra' => ['scaffold' => ['allowed-packages' => ['demo/scaffold']]],
        ]);

        $io = new BufferIO();
        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider($composer, 'demo/scaffold', [
            'file-mapping' => [
                'config/app.php' => ['source' => 'stubs/config/app.php', 'mode' => 'replace'],
            ],
        ]);
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostInstall($this->makePostInstallEvent($composer, $io));

        self::assertFileExists($builder->getProjectRoot() . '/config/app.php');
    }
}
```

`resetInstallScaffoldRanFlag()` clears the process-wide static flag in `EventSubscriber`, which is required when a
single test dispatches multiple lifecycle events.

## Fixtures

Static fixtures under `tests/fixtures/providers/` are read-only sample provider packages used by manifest-loader and
security tests. Keep them minimal — anything that needs a composer.json on disk belongs in a fixture; anything that can
be built at runtime belongs in `FakeProjectBuilder`.

## Adding a new test

- **Testing a pure class?** Add a unit test mirroring the `src/` path.
- **Testing a console controller?** Use or create a spy under `tests/support/Spies/`.
- **Testing an event handler or multi-provider scenario?** Use `ComposerEventHarness` and dispatch
  the real script event; do not call `Scaffolder::scaffold()` directly.
- **Introducing a new persistent input?** Prefer `FakeProjectBuilder`/`FakeProjectBuilder::createComposerJson`
  over static fixtures when the file only matters for one test.

## Static analysis and style

```bash
vendor/bin/phpstan analyse                      # level max, zero tolerance
vendor/bin/ecs check                            # PER-3 + PSR-12 + PHP-CS-Fixer
vendor/bin/ecs check --fix                      # auto-fix style violations
vendor/bin/infection                            # mutation testing
```

Failing static analysis is always a blocker. Do not suppress PHPStan errors with `@phpstan-ignore`; fix the underlying
cause or narrow the type.
