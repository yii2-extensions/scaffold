<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
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
}
