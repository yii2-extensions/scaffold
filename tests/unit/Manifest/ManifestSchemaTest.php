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
    public function testValidateAcceptsCopyEntryAsFromToObjectWithRemap(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'copy' => [
                    ['from' => 'metadata/.editorconfig', 'to' => '.editorconfig'],
                    ['from' => 'metadata/.gitignore', 'to' => '.gitignore'],
                ],
            ],
        );

        self::assertSame(
            [
                ['from' => 'metadata/.editorconfig', 'to' => '.editorconfig'],
                ['from' => 'metadata/.gitignore', 'to' => '.gitignore'],
            ],
            $result['copy'],
            "Object 'copy' entries must round-trip with separate 'from' and 'to' to support source-destination remapping.",
        );
    }

    public function testValidateAcceptsCopyEntryContainingColonInNonLeadingPosition(): void
    {
        // Pins the '^' anchor in '/^[A-Za-z]:/': non-leading colons (namespaces, stream wrappers) must not be rejected.
        $result = (new ManifestSchema())->validate(['copy' => ['some/module:file.php']]);

        self::assertSame(
            [['from' => 'some/module:file.php', 'to' => 'some/module:file.php']],
            $result['copy'],
            'A colon past the second character must be treated as a literal byte; the drive-letter check fires only on leading.',
        );
    }

    public function testValidateAcceptsFullManifest(): void
    {
        $result = (new ManifestSchema())->validate(
            [
                'copy' => ['src', 'config'],
                'exclude' => ['config/test-local.php'],
                'modes' => ['config/*.php' => 'preserve'],
            ],
        );

        self::assertSame(
            [
                ['from' => 'src', 'to' => 'src'],
                ['from' => 'config', 'to' => 'config'],
            ],
            $result['copy'],
        );
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
            "Every 'exclude[]' entry must round-trip through the validator; dropping entries hides expander patterns.",
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
            [
                'copy' => [['from' => 'src', 'to' => 'src']],
                'exclude' => [],
                'modes' => [],
            ],
            $result,
            "Minimal manifest with only 'copy' must default 'exclude' to empty list and 'modes' to empty map.",
        );
    }

    public function testValidateThrowsWhenCopyEntryContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entry "../escape" must not contain path traversal segments.',
        );

        (new ManifestSchema())->validate(['copy' => ['../escape']]);
    }

    public function testValidateThrowsWhenCopyEntryIsAbsoluteUnixPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entry "/etc" must be a relative path.',
        );

        (new ManifestSchema())->validate(['copy' => ['/etc']]);
    }

    public function testValidateThrowsWhenCopyEntryIsAbsoluteWindowsBackslashPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entry "\Windows\System32" must be a relative path.',
        );

        (new ManifestSchema())->validate(['copy' => ['\\Windows\\System32']]);
    }

    public function testValidateThrowsWhenCopyEntryIsAbsoluteWindowsPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entry "C:\\Windows" must be a relative path.',
        );

        (new ManifestSchema())->validate(['copy' => ['C:\\Windows']]);
    }

    public function testValidateThrowsWhenCopyEntryIsEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entries must be non-empty strings.',
        );

        (new ManifestSchema())->validate(['copy' => ['']]);
    }

    public function testValidateThrowsWhenCopyEntryIsNeitherStringNorFromToObject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" entries must be either a string or an object with "from" and "to" keys.',
        );

        (new ManifestSchema())->validate(['copy' => [42]]);
    }

    public function testValidateThrowsWhenCopyFromContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy.from" entry "../escape" must not contain path traversal segments.',
        );

        (new ManifestSchema())->validate(
            ['copy' => [['from' => '../escape', 'to' => 'escape']]],
        );
    }

    public function testValidateThrowsWhenCopyIsEmptyArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy" must declare at least one path.',
        );

        (new ManifestSchema())->validate(['copy' => []]);
    }

    public function testValidateThrowsWhenCopyIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest is missing required key "copy" or it is not an array.',
        );

        (new ManifestSchema())->validate(['copy' => 'src']);
    }

    public function testValidateThrowsWhenCopyKeyIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest is missing required key "copy" or it is not an array.',
        );

        (new ManifestSchema())->validate([]);
    }

    public function testValidateThrowsWhenCopyToIsAbsolutePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "copy.to" entry "/etc/foo" must be a relative path.',
        );

        (new ManifestSchema())->validate(
            ['copy' => [['from' => 'metadata/foo', 'to' => '/etc/foo']]],
        );
    }

    public function testValidateThrowsWhenExcludeEntryContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "exclude" entry "../bad" must not contain path traversal segments.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'exclude' => ['../bad']]);
    }

    public function testValidateThrowsWhenExcludeIsNotArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "exclude" must be an array when present.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'exclude' => 'not-an-array']);
    }

    public function testValidateThrowsWhenModesIsNotObject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" must be an object when present.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => 'invalid']);
    }

    public function testValidateThrowsWhenModesKeyContainsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" entry "../escape" must not contain path traversal segments.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['../escape' => 'preserve']]);
    }

    public function testValidateThrowsWhenModesKeyIsAbsoluteUnixPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" entry "/etc/passwd" must be a relative path.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['/etc/passwd' => 'preserve']]);
    }

    public function testValidateThrowsWhenModesKeyIsAbsoluteWindowsPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" entry "C:\Windows" must be a relative path.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['C:\\Windows' => 'preserve']]);
    }

    public function testValidateThrowsWhenModesKeyIsEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" entries must be non-empty strings.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['' => 'preserve']]);
    }

    public function testValidateThrowsWhenModesValueIsNotString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" value for pattern "config/*.php" must be a string.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['config/*.php' => 123]]);
    }

    public function testValidateThrowsWhenModesValueIsUnknownMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Manifest "modes" value for pattern "config/*.php" has invalid mode "unknown-mode". Allowed: append, '
            . 'prepend, preserve, replace.',
        );

        (new ManifestSchema())->validate(['copy' => ['src'], 'modes' => ['config/*.php' => 'unknown-mode']]);
    }
}
