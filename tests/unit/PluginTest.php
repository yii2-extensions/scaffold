<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Plugin\{Capable, PluginInterface};
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\{EventSubscriber, Plugin};

/**
 * Unit tests for {@see Plugin} and {@see EventSubscriber} bootstrap correctness.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class PluginTest extends TestCase
{
    public function testEventSubscriberRegistersPostCreateProjectCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_CREATE_PROJECT_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-create-project-cmd event.'
        );
    }

    public function testEventSubscriberRegistersPostInstallCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_INSTALL_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-install-cmd event.'
        );
    }

    public function testEventSubscriberRegistersPostUpdateCmd(): void
    {
        self::assertArrayHasKey(
            ScriptEvents::POST_UPDATE_CMD,
            EventSubscriber::getSubscribedEvents(),
            'Event subscriber does not register for the post-update-cmd event.'
        );
    }

    public function testGetCapabilitiesReturnsEmptyArray(): void
    {
        self::assertCount(
            0,
            (new Plugin())->getCapabilities(),
            'Plugin capabilities array is not empty.'
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
}
