<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
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
