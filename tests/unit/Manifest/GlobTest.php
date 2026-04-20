<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\Glob;

/**
 * Unit tests for {@see Glob} pattern-to-regex conversion and matching semantics.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class GlobTest extends TestCase
{
    public function testDoubleStarCrossesDirectorySeparators(): void
    {
        self::assertTrue(
            Glob::matches('public/**', 'public/assets/css/app.css'),
            "'**' must match deep nested paths across '/' separators.",
        );
    }

    public function testDoubleStarPrefixMatchesRootFile(): void
    {
        self::assertTrue(
            Glob::matches('**/.gitignore', '.gitignore'),
            "'**/.gitignore' must match a root-level '.gitignore' because '**' can expand to empty.",
        );
    }

    public function testDoubleStarSlashMatchesDeeplyNestedPath(): void
    {
        self::assertTrue(
            Glob::matches('**/foo', 'a/b/c/foo'),
            "'**/foo' must match arbitrarily deep nested paths.",
        );
    }

    public function testExactPathMatchesItself(): void
    {
        self::assertTrue(
            Glob::matches('config/params.php', 'config/params.php'),
            'Literal patterns must match themselves byte-exact.',
        );
    }

    public function testExactPathRejectsDifferentFile(): void
    {
        self::assertFalse(
            Glob::matches('config/params.php', 'config/web.php'),
            'Literal patterns must not match a different file name.',
        );
    }

    public function testQuestionMarkMatchesSingleNonSeparatorChar(): void
    {
        self::assertTrue(
            Glob::matches('yii?', 'yii0'),
            "'?' must match a single non-separator character.",
        );
        self::assertFalse(
            Glob::matches('yii?', 'yii/0'),
            "'?' must not match the path separator.",
        );
    }

    public function testSingleStarDoesNotCrossDirectorySeparator(): void
    {
        self::assertFalse(
            Glob::matches('config/*.php', 'config/nested/params.php'),
            "A single '*' must not match across '/' separators.",
        );
    }

    public function testSingleStarMatchesSameLevel(): void
    {
        self::assertTrue(
            Glob::matches('config/*.php', 'config/params.php'),
            "'*.php' must match any file with '.php' extension at the same directory level.",
        );
    }

    public function testToRegexAnchorsTheFullPath(): void
    {
        self::assertSame(
            '#^config/[^/]*\.php$#',
            Glob::toRegex('config/*.php'),
            "'toRegex' must produce an anchored regex matching the full string.",
        );
    }

    public function testToRegexEscapesPunctuation(): void
    {
        self::assertSame(
            '#^file\.with\.dots$#',
            Glob::toRegex('file.with.dots'),
            "Literal '.' characters must be escaped in the produced regex.",
        );
    }

    public function testToRegexTreatsDoubleStarSlashAsOptionalDirectoryChain(): void
    {
        self::assertSame(
            '#^(?:.*/)?foo$#',
            Glob::toRegex('**/foo'),
            "'**/' must compile to '(?:.*/)?' so the match succeeds with zero or more directory levels.",
        );
    }
}
