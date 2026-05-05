<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use yii\scaffold\EventSubscriber;

/**
 * Unit tests for {@see EventSubscriber} event registration.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class EventSubscriberTest extends TestCase
{
    public function testOnPostCreateProjectShortCircuitsWhenInstallScaffoldRanIsTrue(): void
    {
        $reflection = new ReflectionClass(EventSubscriber::class);

        $property = $reflection->getProperty('installScaffoldRan');

        $property->setValue(null, true);

        $io = new BufferIO();

        $config = self::createStub(Config::class);

        // An empty 'vendor-dir' would cause 'runScaffold' to write the 'Unable to resolve vendor-dir' error message.
        // The early return must prevent 'runScaffold' from being reached, so the buffer must remain empty.
        $config->method('get')->willReturn('');

        $composer = self::createStub(Composer::class);

        $composer->method('getConfig')->willReturn($config);

        (new EventSubscriber())->onPostCreateProject(
            new ScriptEvent(ScriptEvents::POST_CREATE_PROJECT_CMD, $composer, $io, true),
        );

        self::assertSame(
            '',
            $io->getOutput(),
            "'onPostCreateProject' must short-circuit silently when 'installScaffoldRan' is 'true'.",
        );
    }
    public function testRegistersPostCreateProjectCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_CREATE_PROJECT_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-create-project-cmd event.',
        );
    }

    public function testRegistersPostInstallCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_INSTALL_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-install-cmd event.',
        );
    }

    public function testRegistersPostUpdateCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_UPDATE_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-update-cmd event.',
        );
    }

    public function testRunScaffoldAbortsWhenVendorDirIsEmpty(): void
    {
        $io = new BufferIO();

        $config = self::createStub(Config::class);

        $config->method('get')->willReturn('');

        $composer = self::createStub(Composer::class);

        $composer->method('getConfig')->willReturn($config);

        (new EventSubscriber())->onPostInstall(
            new ScriptEvent(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true),
        );

        self::assertStringContainsString(
            'Unable to resolve vendor-dir',
            $io->getOutput(),
            'An empty vendor-dir must short-circuit the scaffold run with a clear error on stderr.',
        );
    }

    public function testRunScaffoldProceedsWhenScaffoldExtraIsNotArray(): void
    {
        // 'extra.scaffold' set to a non-array value must default to auto-enabled, so the run proceeds and the
        // vendor-dir guard fires its error (proving the auto check did not short-circuit).
        $io = new BufferIO();

        $config = self::createStub(Config::class);

        $config->method('get')->willReturn('');

        $package = self::createStub(RootPackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => 'not-an-array']);

        $composer = self::createStub(Composer::class);

        $composer->method('getConfig')->willReturn($config);
        $composer->method('getPackage')->willReturn($package);

        (new EventSubscriber())->onPostInstall(
            new ScriptEvent(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true),
        );

        self::assertStringContainsString(
            'Unable to resolve vendor-dir',
            $io->getOutput(),
            'Non-array "extra.scaffold" must be treated as auto-enabled and let the run proceed past the auto guard.',
        );
    }

    public function testRunScaffoldRespectsAutoFalseFlag(): void
    {
        $io = new BufferIO();

        $config = self::createStub(Config::class);

        // Empty 'vendor-dir' would emit the resolve error if reached; the auto guard must fire first.
        $config->method('get')->willReturn('');

        $package = self::createStub(RootPackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['auto' => false]]);

        $composer = self::createStub(Composer::class);

        $composer->method('getConfig')->willReturn($config);
        $composer->method('getPackage')->willReturn($package);

        (new EventSubscriber())->onPostInstall(
            new ScriptEvent(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true),
        );

        self::assertStringContainsString(
            'Auto-scaffold disabled',
            $io->getOutput(),
            'Explicit "extra.scaffold.auto = false" must short-circuit auto-trigger with a clear notice.',
        );
        self::assertStringNotContainsString(
            'Unable to resolve vendor-dir',
            $io->getOutput(),
            'When auto is disabled, the run must return BEFORE the vendor-dir guard.',
        );
    }

    public function testRunScaffoldRunsWhenAutoFlagIsExplicitlyTrue(): void
    {
        // Explicit 'auto: true' must behave identical to the default and let the run proceed; we assert via the
        // vendor-dir guard which fires further down the runScaffold pipeline.
        $io = new BufferIO();

        $config = self::createStub(Config::class);

        $config->method('get')->willReturn('');

        $package = self::createStub(RootPackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['auto' => true]]);

        $composer = self::createStub(Composer::class);

        $composer->method('getConfig')->willReturn($config);
        $composer->method('getPackage')->willReturn($package);

        (new EventSubscriber())->onPostInstall(
            new ScriptEvent(ScriptEvents::POST_INSTALL_CMD, $composer, $io, true),
        );

        self::assertStringContainsString(
            'Unable to resolve vendor-dir',
            $io->getOutput(),
            'Explicit "auto: true" must let the run proceed past the auto guard.',
        );
    }

    /**
     * Resets {@see EventSubscriber::$installScaffoldRan} so each test starts with a clean lifecycle slate; keeps the
     * suite order-independent regardless of which event handlers previous tests invoked.
     */
    protected function setUp(): void
    {
        $reflection = new ReflectionClass(EventSubscriber::class);

        $property = $reflection->getProperty('installScaffoldRan');

        $property->setValue(null, false);
    }
}
