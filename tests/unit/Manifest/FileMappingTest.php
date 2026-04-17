<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\FileMapping;

/**
 * Unit tests for {@see FileMapping} value object construction and property access.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class FileMappingTest extends TestCase
{
    public function testConstructorAssignsDestination(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'preserve', 'vendor/pkg', '/path');

        self::assertSame('config/params.php', $mapping->destination);
    }

    public function testConstructorAssignsMode(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'replace', 'vendor/pkg', '/path');

        self::assertSame('replace', $mapping->mode);
    }

    public function testConstructorAssignsProviderName(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'preserve', 'yii2-extensions/nginx-scaffold', '/path');

        self::assertSame('yii2-extensions/nginx-scaffold', $mapping->providerName);
    }

    public function testConstructorAssignsProviderPath(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'preserve', 'vendor/pkg', '/vendor/yii2-extensions/nginx-scaffold');

        self::assertSame('/vendor/yii2-extensions/nginx-scaffold', $mapping->providerPath);
    }

    public function testConstructorAssignsSource(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'preserve', 'vendor/pkg', '/path');

        self::assertSame('stubs/params.php', $mapping->source);
    }

    public function testPropertiesAreReadonly(): void
    {
        $mapping = new FileMapping('config/params.php', 'stubs/params.php', 'preserve', 'vendor/pkg', '/path');

        self::assertSame('config/params.php', $mapping->destination);
        self::assertSame('stubs/params.php', $mapping->source);
        self::assertSame('preserve', $mapping->mode);
        self::assertSame('vendor/pkg', $mapping->providerName);
        self::assertSame('/path', $mapping->providerPath);
    }
}
