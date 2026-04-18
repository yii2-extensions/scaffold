<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\NullIO;
use Composer\Plugin\{Capable, PluginInterface};
use PHPUnit\Framework\Attributes\{DoesNotPerformAssertions, Group};
use PHPUnit\Framework\TestCase;
use yii\scaffold\{EventSubscriber, Plugin};

/**
 * Unit tests for {@see Plugin} interface implementations and capabilities.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class PluginTest extends TestCase
{
    public function testActivateRegistersSubscriberOnEventDispatcher(): void
    {
        $dispatcher = self::createMock(EventDispatcher::class);

        $dispatcher
            ->expects(self::once())
            ->method('addSubscriber')
            ->with(self::isInstanceOf(EventSubscriber::class));

        $composer = self::createStub(Composer::class);
        $composer->method('getEventDispatcher')->willReturn($dispatcher);

        (new Plugin())->activate($composer, new NullIO());
    }

    public function testDeactivateIsIdempotentWhenCalledTwice(): void
    {
        $dispatcher = self::createMock(EventDispatcher::class);

        $dispatcher->expects(self::once())->method('addSubscriber');
        $dispatcher->expects(self::once())->method('removeListener');

        $composer = self::createStub(Composer::class);

        $composer->method('getEventDispatcher')->willReturn($dispatcher);

        $plugin = new Plugin();
        $plugin->activate($composer, new NullIO());
        $plugin->deactivate($composer, new NullIO());
        $plugin->deactivate($composer, new NullIO());
    }

    public function testDeactivateIsNoopWhenNotActivated(): void
    {
        $dispatcher = self::createMock(EventDispatcher::class);

        $dispatcher->expects(self::never())->method('removeListener');

        $composer = self::createStub(Composer::class);

        $composer->method('getEventDispatcher')->willReturn($dispatcher);

        (new Plugin())->deactivate($composer, new NullIO());
    }

    public function testDeactivateRemovesSubscriberAfterActivate(): void
    {
        $dispatcher = self::createMock(EventDispatcher::class);

        $dispatcher->expects(self::once())->method('addSubscriber');
        $dispatcher
            ->expects(self::once())
            ->method('removeListener')
            ->with(self::isInstanceOf(EventSubscriber::class));

        $composer = self::createStub(Composer::class);

        $composer->method('getEventDispatcher')->willReturn($dispatcher);

        $plugin = new Plugin();

        $plugin->activate($composer, new NullIO());
        $plugin->deactivate($composer, new NullIO());
    }

    public function testGetCapabilitiesReturnsEmptyArray(): void
    {
        self::assertCount(
            0,
            (new Plugin())->getCapabilities(),
            'Plugin capabilities array is not empty.',
        );
    }

    public function testPluginImplementsCapable(): void
    {
        self::assertInstanceOf(
            Capable::class,
            new Plugin(),
            'Plugin does not implement the Capable interface.',
        );
    }

    public function testPluginImplementsPluginInterface(): void
    {
        self::assertInstanceOf(
            PluginInterface::class,
            new Plugin(),
            'Plugin does not implement the PluginInterface.',
        );
    }

    #[DoesNotPerformAssertions]
    public function testUninstallIsNoop(): void
    {
        $composer = self::createStub(Composer::class);

        // `uninstall()` is an explicit no-op per PluginInterface; this test guards against accidentally adding behavior
        // that would throw during `composer remove`.
        (new Plugin())->uninstall($composer, new NullIO());
    }
}
