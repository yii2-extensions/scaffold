<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use function explode;
use function implode;
use function str_starts_with;

/**
 * Wraps unified diff lines in raw ANSI color escape codes for decorated terminals.
 *
 * Styles the two leading `--- a/…` and `+++ b/…` file headers in bold, `@@` hunk headers in cyan, added lines in
 * green, and removed lines in red. Header detection is positional (first two lines), so a removed body line that
 * happens to start with `---` is still styled as removed. Raw escape codes keep the output compatible with
 * {@see SymfonyOutputWriter}, which bypasses Symfony's formatter.
 *
 * Usage example:
 * ```php
 * $colorized = (new \yii\scaffold\Console\DiffColorizer())->colorize($unifiedDiff);
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class DiffColorizer
{
    private const string BOLD = "\e[1m";
    private const string CYAN = "\e[36m";
    private const string GREEN = "\e[32m";
    private const string RED = "\e[31m";
    private const string RESET = "\e[0m";

    /**
     * Colorizes a unified diff produced by {@see \yii\scaffold\Services\DiffService::buildDiff()}.
     *
     * @param string $diff Unified diff whose first two lines are the `---`/`+++` file headers.
     *
     * @return string Diff with each styled line wrapped in ANSI escape codes; context lines stay unstyled.
     */
    public function colorize(string $diff): string
    {
        $styled = [];

        foreach (explode("\n", $diff) as $index => $line) {
            $styled[] = self::styleLine($line, $index);
        }

        return implode("\n", $styled);
    }

    /**
     * Styles a single diff line according to its position and prefix.
     *
     * @param string $line Diff line without a trailing newline.
     * @param int $index Zero-based position of the line within the diff.
     *
     * @return string Line wrapped in ANSI escape codes, or unchanged for context lines.
     */
    private static function styleLine(string $line, int $index): string
    {
        if ($index < 2) {
            return self::BOLD . $line . self::RESET;
        }

        if (str_starts_with($line, '@@')) {
            return self::CYAN . $line . self::RESET;
        }

        if (str_starts_with($line, '+')) {
            return self::GREEN . $line . self::RESET;
        }

        if (str_starts_with($line, '-')) {
            return self::RED . $line . self::RESET;
        }

        return $line;
    }
}
