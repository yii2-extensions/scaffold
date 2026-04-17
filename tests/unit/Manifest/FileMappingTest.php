<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\FileMapping;

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
            'preserve',
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
            'replace',
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'replace',
            $mapping->mode,
            'Constructor should assign the mode property correctly.',
        );
    }

    public function testConstructorAssignsProviderName(): void
    {
        $mapping = new FileMapping(
            'config/params.php',
            'stubs/params.php',
            'preserve',
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
            'preserve',
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
            'preserve',
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
            'preserve',
            'vendor/pkg',
            '/path',
        );

        self::assertSame(
            'config/params.php',
            $mapping->destination,
            'Constructor should assign the destination property correctly.',
        );
        self::assertSame(
            'stubs/params.php',
            $mapping->source,
            'Constructor should assign the source property correctly.',
        );
        self::assertSame(
            'preserve',
            $mapping->mode,
            'Constructor should assign the mode property correctly.',
        );
        self::assertSame(
            'vendor/pkg',
            $mapping->providerName,
            'Constructor should assign the providerName property correctly.',
        );
        self::assertSame(
            '/path',
            $mapping->providerPath,
            'Constructor should assign the providerPath property correctly.',
        );
    }
}
