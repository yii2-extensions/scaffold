<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\TestCase;
use yii\scaffold\EventSubscriber;
use yii\scaffold\Plugin;

/**
 * Unit tests for {@see Plugin} and {@see EventSubscriber} bootstrap correctness.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class PluginTest extends TestCase
{
    public function testEventSubscriberRegistersPostCreateProjectCmd(): void
    {
        self::assertArrayHasKey(ScriptEvents::POST_CREATE_PROJECT_CMD, EventSubscriber::getSubscribedEvents());
    }

    public function testEventSubscriberRegistersPostInstallCmd(): void
    {
        self::assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, EventSubscriber::getSubscribedEvents());
    }

    public function testEventSubscriberRegistersPostUpdateCmd(): void
    {
        self::assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, EventSubscriber::getSubscribedEvents());
    }

    public function testGetCapabilitiesReturnsEmptyArray(): void
    {
        self::assertCount(0, (new Plugin())->getCapabilities());
    }

    public function testPluginImplementsCapable(): void
    {
        self::assertInstanceOf(Capable::class, new Plugin());
    }
    public function testPluginImplementsPluginInterface(): void
    {
        self::assertInstanceOf(PluginInterface::class, new Plugin());
    }
}
