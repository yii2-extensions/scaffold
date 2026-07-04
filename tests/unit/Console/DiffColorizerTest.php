<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Console\DiffColorizer;

use function explode;

/**
 * Unit tests for {@see DiffColorizer} ANSI styling of unified diff lines.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console')]
final class DiffColorizerTest extends TestCase
{
    public function testColorizeLeavesContextLinesUnstyled(): void
    {
        self::assertSame(
            ' context',
            self::colorizedLine(self::sampleDiff(), 3),
            'Context lines must carry no escape codes.',
        );
    }

    public function testColorizeLeavesNoNewlineMarkerUnstyled(): void
    {
        self::assertSame(
            '\\ No newline at end of file',
            self::colorizedLine(self::sampleDiff(), 6),
            'The backslash marker must carry no escape codes.',
        );
    }

    public function testColorizeStylesRemovedLineStartingWithDashesAsRemoved(): void
    {
        $diff = "--- a/notes.md\n+++ b/notes.md\n@@ -1 +1 @@\n--- section marker\n+updated";

        self::assertSame(
            "\e[31m--- section marker\e[0m",
            self::colorizedLine($diff, 3),
            'A body line rendering as three dashes must be red, not bold.',
        );
    }

    public function testColorizeWrapsAddedLinesInGreen(): void
    {
        self::assertSame(
            "\e[32m+new\e[0m",
            self::colorizedLine(self::sampleDiff(), 5),
            'Added lines must be wrapped in green.',
        );
    }

    public function testColorizeWrapsFileHeaderLinesInBold(): void
    {
        self::assertSame(
            "\e[1m--- a/config/params.php\e[0m",
            self::colorizedLine(self::sampleDiff(), 0),
            "The 'a/' header must be wrapped in bold.",
        );
        self::assertSame(
            "\e[1m+++ b/config/params.php\e[0m",
            self::colorizedLine(self::sampleDiff(), 1),
            "The 'b/' header must be wrapped in bold.",
        );
    }

    public function testColorizeWrapsHunkHeadersInCyan(): void
    {
        self::assertSame(
            "\e[36m@@ -1,2 +1,2 @@\e[0m",
            self::colorizedLine(self::sampleDiff(), 2),
            'Hunk headers must be wrapped in cyan.',
        );
    }

    public function testColorizeWrapsRemovedLinesInRed(): void
    {
        self::assertSame(
            "\e[31m-old\e[0m",
            self::colorizedLine(self::sampleDiff(), 4),
            'Removed lines must be wrapped in red.',
        );
    }

    /**
     * Colorizes `$diff` and returns the line at `$index`.
     *
     * @param string $diff Unified diff to colorize.
     * @param int $index Zero-based line position within the colorized output.
     *
     * @return string Colorized line at the requested position, or an empty string when out of range.
     */
    private static function colorizedLine(string $diff, int $index): string
    {
        $lines = explode("\n", (new DiffColorizer())->colorize($diff));

        return $lines[$index] ?? '';
    }

    /**
     * Builds a representative unified diff covering every line kind the colorizer styles.
     *
     * @return string Unified diff with headers, hunk, context, removed, added, and no-newline marker lines.
     */
    private static function sampleDiff(): string
    {
        return "--- a/config/params.php\n"
            . "+++ b/config/params.php\n"
            . "@@ -1,2 +1,2 @@\n"
            . " context\n"
            . "-old\n"
            . "+new\n"
            . '\\ No newline at end of file';
    }
}
