<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use function preg_match;
use function preg_quote;
use function preg_replace_callback;

/**
 * Glob-to-regex converter and matcher for scaffold manifest patterns.
 *
 * Supports the subset used by `scaffold.json`:
 *
 * - `*` — any sequence of characters that does NOT include a path separator.
 * - `**` — any sequence, including path separators (matches across directories).
 * - `?` — a single character that is not a path separator.
 * - literals — everything else matches byte-exact.
 *
 * Paths are normalised to forward slashes before matching so providers can share patterns across POSIX and Windows.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Glob
{
    /**
     * Returns `true` when `$path` matches `$pattern` under scaffold's glob semantics.
     *
     * @param string $pattern Glob pattern.
     * @param string $path Relative path to test (normalised to forward slashes).
     *
     * @return bool `true` when the pattern matches the full path, `false` otherwise.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex($pattern), $path) === 1;
    }

    /**
     * Converts a scaffold glob pattern into an anchored PCRE regex.
     *
     * @param string $pattern Glob pattern.
     *
     * @return string Anchored PCRE regex matching the full string (e.g. `'#^config/[^/]+\.php$#'`).
     */
    public static function toRegex(string $pattern): string
    {
        // '**/' expands to "any number of directories including zero" so '**/foo' matches both 'foo' and 'a/b/foo'.
        // '(string)' cast is defensive; preg_replace_callback only returns null on invalid regex.
        // @codeCoverageIgnoreStart
        $regex = (string) preg_replace_callback(
            '#\*\*/|\*\*|\*|\?|[^*?]+#',
            static fn(array $match) => match ($match[0]) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($match[0], '#'),
            },
            $pattern,
        );
        // @codeCoverageIgnoreEnd

        return "#^{$regex}$#";
    }
}
