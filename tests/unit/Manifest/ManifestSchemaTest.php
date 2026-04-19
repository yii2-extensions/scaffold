<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\{FileMode, ManifestSchema};

/**
 * Unit tests for {@see ManifestSchema} manifest structure validation.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class ManifestSchemaTest extends TestCase
{
    public function testAllFourModesInOneMappingPass(): void
    {
        $this->expectNotToPerformAssertions();

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'a.php' => [
                        'source' => 'stubs/a.php',
                        'mode' => 'replace',
                    ],
                    'b.php' => [
                        'source' => 'stubs/b.php',
                        'mode' => 'preserve',
                    ],
                    'c.txt' => [
                        'source' => 'stubs/c.txt',
                        'mode' => 'append',
                    ],
                    'd.txt' => [
                        'source' => 'stubs/d.txt',
                        'mode' => 'prepend',
                    ],
                ],
            ],
        );
    }

    public function testDestinationKeyWithIntegerThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file-mapping key must be a non-empty string');

        (new ManifestSchema())->validate(
            [
                // an integer key bypasses PHP's string-key conversion and triggers the is_string check.
                'file-mapping' => [
                    [
                        'source' => 'stubs/x.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }

    public function testEmptyFileMappingReturnsEmptyArray(): void
    {
        $result = (new ManifestSchema())->validate(['file-mapping' => []]);

        self::assertSame(
            [],
            $result,
            "Expected empty array for 'file-mapping' when no entries are provided.",
        );
    }

    public function testEntryEmptySourceThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"source"');

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => [
                        'source' => '',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );
    }

    public function testEntryInvalidModeThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"unknown-mode"');

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => [
                        'source' => 'stubs/params.php',
                        'mode' => 'unknown-mode',
                    ],
                ],
            ],
        );
    }

    public function testEntryIsNotArrayThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => 'not-an-object',
                ],
            ],
        );
    }

    public function testEntryMissingModeKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"mode"');

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => ['source' => 'stubs/params.php'],
                ],
            ],
        );
    }

    public function testEntryMissingSourceKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"source"');

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => ['mode' => 'preserve'],
                ],
            ],
        );
    }

    public function testFileMappingIsNotArrayThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"file-mapping"');

        (new ManifestSchema())->validate(
            [
                'file-mapping' => 'not-an-array',
            ],
        );
    }

    public function testMissingFileMappingKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"file-mapping"');

        (new ManifestSchema())->validate(
            [],
        );
    }

    public function testValidateReturnsTypedFileMappingArray(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'nginx.conf' => [
                        'source' => 'stubs/nginx.conf',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $entry = $result['nginx.conf'] ?? null;

        if ($entry === null) {
            self::fail('Expected "nginx.conf" key in validated result.');
        }

        self::assertSame(
            'stubs/nginx.conf',
            $entry['source'],
            "Expected 'source' to be 'stubs/nginx.conf' for 'nginx.conf' entry.",
        );
        self::assertSame(
            FileMode::Replace,
            $entry['mode'],
            "Expected 'mode' to be 'FileMode::Replace' for 'nginx.conf' entry.",
        );
    }

    public function testValidMappingWithAppendModePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
    }

    public function testValidMappingWithPrependModePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    '.env.dist' => [
                        'source' => 'stubs/.env.dist',
                        'mode' => 'prepend',
                    ],
                ],
            ],
        );
    }

    public function testValidMappingWithPreserveModePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/params.php' => [
                        'source' => 'stubs/params.php',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );
    }
    public function testValidMappingWithReplaceModePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new ManifestSchema())->validate(
            [
                'file-mapping' => [
                    'config/web.php' => [
                        'source' => 'stubs/web.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }
}
