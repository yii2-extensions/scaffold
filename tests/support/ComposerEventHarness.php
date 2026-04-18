<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\{BufferIO, IOInterface};
use Composer\Package\{CompletePackage, PackageInterface};
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use ReflectionClass;
use RuntimeException;
use yii\scaffold\EventSubscriber;

use function sprintf;

/**
 * Harness for functional tests that need a real {@see Composer} instance and programmatically dispatched script events.
 *
 * Centralizes the boilerplate of (1) calling {@see Factory::create()} against a fake project, (2) seeding the local
 * repository with mock provider packages, and (3) building {@see ScriptEvent} instances for the three lifecycle events
 * the scaffold plugin subscribes to.
 *
 * Tests dispatch events directly against a {@see \yii\scaffold\EventSubscriber} instance, bypassing the full Composer
 * installation pipeline. This exercises the same code path the plugin runs in production without the cost and
 * brittleness of shelling out to `composer install`.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
trait ComposerEventHarness
{
    /**
     * Adds a mock provider package to the local repository with the given scaffold manifest.
     *
     * @param Composer $composer Live Composer instance whose local repository receives the package.
     * @param string $name Composer package name (for example, `demo/scaffold`).
     * @param array<string, mixed> $scaffoldManifest Raw `extra.scaffold` array, typically a `{file-mapping: ...}` entry
     * or an external `{manifest: "scaffold.json"}` pointer.
     * @param string $version Pretty version string persisted into `scaffold-lock.json`.
     *
     * @return PackageInterface Package that was added to the local repository.
     */
    protected function addMockProvider(
        Composer $composer,
        string $name,
        array $scaffoldManifest,
        string $version = '1.0.0',
    ): PackageInterface {
        $package = new CompletePackage($name, "{$version}.0", $version);
        $package->setType('yii2-scaffold');
        $package->setExtra(['scaffold' => $scaffoldManifest]);

        $composer->getRepositoryManager()->getLocalRepository()->addPackage($package);

        return $package;
    }

    /**
     * Creates a {@see Composer} instance for a fake project using {@see Factory::create()}.
     *
     * Plugins are disabled because tests manually instantiate {@see \yii\scaffold\EventSubscriber} and dispatch events
     * against it, so the plugin manager does not need to auto-load this package.
     *
     * @param string $projectRoot Absolute path to the fake project directory containing `composer.json`.
     * @param IOInterface|null $io Optional IO; a {@see BufferIO} is created when omitted so callers can inspect output.
     *
     * @return Composer Composer instance bootstrapped for the fake project, with plugins disabled.
     */
    protected function buildComposerForProject(string $projectRoot, IOInterface|null $io = null): Composer
    {
        $io ??= new BufferIO();

        $composerJson = $projectRoot . '/composer.json';

        if (!is_file($composerJson)) {
            throw new RuntimeException(
                sprintf(
                    'Fake project "%s" is missing composer.json; call FakeProjectBuilder::createComposerJson first.',
                    $projectRoot,
                ),
            );
        }

        return Factory::create($io, $composerJson, disablePlugins: true, disableScripts: true);
    }

    /**
     * Builds a `post-create-project-cmd` script event bound to `$composer` and `$io`.
     *
     * @param Composer $composer Live Composer instance to bind to the event.
     * @param IOInterface $io IO instance to bind to the event.
     *
     * @return ScriptEvent Script event instance with name `post-create-project-cmd` and the given composer and IO.
     */
    protected function makePostCreateProjectEvent(Composer $composer, IOInterface $io): ScriptEvent
    {
        return new ScriptEvent(ScriptEvents::POST_CREATE_PROJECT_CMD, $composer, $io, true);
    }

    /**
     * Builds a `post-install-cmd` script event bound to `$composer` and `$io`.
     *
     * @param Composer $composer Live Composer instance to bind to the event.
     * @param IOInterface $io IO instance to bind to the event.
     *
     * @return ScriptEvent Script event instance with name `post-install-cmd` and the given composer and IO.
     */
    protected function makePostInstallEvent(Composer $composer, IOInterface $io): ScriptEvent
    {
        return new ScriptEvent(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true);
    }

    /**
     * Builds a `post-update-cmd` script event bound to `$composer` and `$io`.
     *
     * @param Composer $composer Live Composer instance to bind to the event.
     * @param IOInterface $io IO instance to bind to the event.
     *
     * @return ScriptEvent Script event instance with name `post-update-cmd` and the given composer and IO.
     */
    protected function makePostUpdateEvent(Composer $composer, IOInterface $io): ScriptEvent
    {
        return new ScriptEvent(ScriptEvents::POST_UPDATE_CMD, $composer, $io, true);
    }

    /**
     * Resets the process-wide {@see EventSubscriber::$installScaffoldRan} flag via reflection.
     *
     * The flag persists across tests by design (it guards against duplicate scaffolding within a single Composer
     * invocation). Tests that dispatch multiple events across different scenarios must reset it to get deterministic
     * behavior.
     */
    protected function resetInstallScaffoldRanFlag(): void
    {
        $reflection = new ReflectionClass(EventSubscriber::class);
        $property = $reflection->getProperty('installScaffoldRan');
        $property->setValue(null, false);
    }
}
