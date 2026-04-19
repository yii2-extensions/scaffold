<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Console\Application;
use yii\scaffold\Console\Command\{DiffCommand, EjectCommand, ProvidersCommand, ReapplyCommand, StatusCommand};

use function sprintf;

/**
 * Unit tests for {@see Application} verifying command registration, name stability, and the runtime-derived
 * version lookup via Composer metadata.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console')]
final class ApplicationTest extends TestCase
{
    public function testApplicationNameIsStable(): void
    {
        self::assertSame(
            Application::NAME,
            (new Application())->getName(),
            "'Application::getName()' must return the fixed 'Application::NAME'.",
        );
    }

    public function testApplicationRegistersTheFiveScaffoldCommands(): void
    {
        $app = new Application();

        $commands = [
            'status' => StatusCommand::class,
            'providers' => ProvidersCommand::class,
            'diff' => DiffCommand::class,
            'eject' => EjectCommand::class,
            'reapply' => ReapplyCommand::class,
        ];

        foreach ($commands as $name => $expectedClass) {
            self::assertTrue(
                $app->has($name),
                sprintf("Application must register a '%s' command.", $name),
            );
            self::assertInstanceOf(
                $expectedClass,
                $app->get($name),
                sprintf("Command '%s' must resolve to '%s'.", $name, $expectedClass),
            );
        }
    }

    public function testApplicationVersionIsNonEmptyAtRuntime(): void
    {
        self::assertNotEmpty(
            (new Application())->getVersion(),
            "'Application::getVersion()' must always return a non-empty string so 'vendor/bin/scaffold --version' "
            . 'renders a meaningful value regardless of how the plugin was installed.',
        );
    }

    public function testResolveVersionFallsBackWhenPackageIsNotInstalled(): void
    {
        self::assertSame(
            Application::FALLBACK_VERSION,
            Application::resolveVersion('definitely/not-a-real-package-for-tests'),
            "Unknown Composer packages must fall back to 'Application::FALLBACK_VERSION' so the CLI stays runnable.",
        );
    }

    public function testResolveVersionReturnsComposerPrettyVersionWhenPackageIsInstalled(): void
    {
        self::assertNotEmpty(
            Application::resolveVersion(Application::NAME),
            "'Application::resolveVersion()' must return the Composer pretty_version when the package is installed.",
        );
    }
}
