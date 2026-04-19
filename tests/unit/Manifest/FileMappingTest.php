<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use Error;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use yii\scaffold\Manifest\{FileMapping, FileMode};

/**
 * Unit tests for {@see FileMapping} value object construction and property access.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class FileMappingTest extends TestCase
{
    public function testConstructorAssignsDestination(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'config/params.php',
            $mapping->destination,
            'Constructor should assign the destination property correctly.',
        );
    }

    public function testConstructorAssignsMode(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Replace,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            FileMode::Replace,
            $mapping->mode,
            'Constructor should assign the mode property correctly.',
        );
    }

    public function testConstructorAssignsProviderName(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'yii2-extensions/nginx-scaffold',
            '/path',
        );

        self::assertSame(
            'yii2-extensions/nginx-scaffold',
            $mapping->providerName,
            'Constructor should assign the providerName property correctly.',
        );
    }

    public function testConstructorAssignsProviderPath(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/vendor/yii2-extensions/nginx-scaffold',
        );

        self::assertSame(
            '/vendor/yii2-extensions/nginx-scaffold',
            $mapping->providerPath,
            'Constructor should assign the providerPath property correctly.',
        );
    }

    public function testConstructorAssignsSource(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'stubs/params.php',
            $mapping->source,
            'Constructor should assign the source property correctly.',
        );
    }

    public function testPropertiesAreReadonly(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            FileMode::Preserve,
            'vendor/pkg',
            '/path',
        );

        $this->expectException(Error::class);

        (new ReflectionProperty(FileMapping::class, 'destination'))->setValue($mapping, 'other.php');
    }
}
