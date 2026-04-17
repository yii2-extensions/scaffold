<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit;

use Composer\Plugin\{Capable, PluginInterface};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Plugin;

/**
 * Unit tests for {@see Plugin} interface implementations and capabilities.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class PluginTest extends TestCase
{
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
}
