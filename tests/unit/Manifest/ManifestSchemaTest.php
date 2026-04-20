<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\{FileMode, ManifestSchema};

/**
 * Unit tests for {@see ManifestSchema} validation of the `copy` / `exclude` / `modes` shape.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class ManifestSchemaTest extends TestCase
{
    public function testValidateAcceptsFullManifest(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'copy' => ['src', 'config'],
                'exclude' => ['config/test-local.php'],
                'modes' => ['config/*.php' => 'preserve'],
            ],
        );

        self::assertSame(['src', 'config'], $result['copy']);
        self::assertSame(['config/test-local.php'], $result['exclude']);
        self::assertSame([FileMode::Preserve], array_values($result['modes']));
        self::assertSame(['config/*.php'], array_keys($result['modes']));
    }

    public function testValidatePreservesAllExcludeEntries(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'copy' => ['src'],
                'exclude' => ['first.php', 'second.php', 'third.php'],
            ],
        );

        self::assertSame(
            ['first.php', 'second.php', 'third.php'],
            $result['exclude'],
            "Every 'exclude[]' entry must round-trip through the validator; dropping entries would hide patterns that "
            . 'the expander must apply.',
        );
    }

    public function testValidateResolvesAllFourModesCorrectly(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'copy' => ['src'],
                'modes' => [
                    'replace.php' => 'replace',
                    'preserve.php' => 'preserve',
                    'append.txt' => 'append',
                    'prepend.txt' => 'prepend',
                ],
            ],
        );

        self::assertSame(
            [
                'replace.php' => FileMode::Replace,
                'preserve.php' => FileMode::Preserve,
                'append.txt' => FileMode::Append,
                'prepend.txt' => FileMode::Prepend,
            ],
            $result['modes'],
            'All four FileMode cases must resolve correctly from their string values.',
        );
    }
    public function testValidateReturnsTypedStructureForMinimalManifest(): void
    {
        $result = (new ManifestSchema())->validate(['copy' => ['src']]);

        self::assertSame(
            ['copy' => ['src'], 'exclude' => [], 'modes' => []],
            $result,
            "Minimal manifest with only 'copy' must default 'exclude' to empty list and 'modes' to empty map.",
        );
    }

    public function testValidateThrowsWhenCopyEntryContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('traversal');

        (new ManifestSchema())->validate(['copy' => ['../escape']]);
    }

    public function testValidateThrowsWhenCopyEntryIsAbsoluteUnixPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        (new ManifestSchema())->validate(['copy' => ['/etc']]);
    }

    public function testValidateThrowsWhenCopyEntryIsAbsoluteWindowsPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        (new ManifestSchema())->validate(['copy' => ['C:\\Windows']]);
    }

    public function testValidateThrowsWhenCopyEntryIsEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty');

        (new ManifestSchema())->validate(['copy' => ['']]);
    }

    public function testValidateThrowsWhenCopyIsEmptyArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one path');

        (new ManifestSchema())->validate(['copy' => []]);
    }

    public function testValidateThrowsWhenCopyIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"copy"');

        (new ManifestSchema())->validate(['copy' => 'src']);
    }

    public function testValidateThrowsWhenCopyKeyIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"copy"');

        (new ManifestSchema())->validate([]);
    }

    public function testValidateThrowsWhenExcludeEntryContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('traversal');

        (new ManifestSchema())->validate(['copy' => ['src'], 'exclude' => ['../bad']]);
    }

    public function testValidateThrowsWhenExcludeIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"exclude"');

        (new ManifestSchema())->validate(['copy' => ['src'], 'exclude' => 'not-an-array']);
    }

    public function testValidateThrowsWhenModesIsNotObject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"modes"');

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => 'invalid']);
    }

    public function testValidateThrowsWhenModesKeyIsEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty');

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['' => 'preserve']]);
    }

    public function testValidateThrowsWhenModesValueIsNotString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a string');

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['config/*.php' => 123]]);
    }

    public function testValidateThrowsWhenModesValueIsUnknownMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"unknown-mode"');

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['config/*.php' => 'unknown-mode']]);
    }
}
