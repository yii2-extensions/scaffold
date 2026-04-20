<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use function str_ends_with;
use function substr;

/**
 * Built-in exclusion patterns applied when {@see ManifestExpander} walks a directory listed in `scaffold.copy`.
 *
 * These patterns guarantee that provider meta-files (composer metadata, CI configuration, docs, etc.) never leak into
 * the consumer project just because the provider's tree is copied wholesale. Providers that need to distribute a file
 * that matches a default exclude may list it as an explicit file entry in `copy[]`; explicit file entries bypass the
 * default excludes by design.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class DefaultExcludes
{
    /**
     * @var list<string> Glob patterns excluded by default from directory walks.
     */
    public const array PATTERNS = [
        // Composer metadata.
        'composer.json',
        'composer.lock',
        'vendor/**',
        // Version control.
        '.git/**',
        '.github/**',
        '.gitattributes',
        '.gitignore',
        // Test framework.
        'tests/**',
        'phpunit.xml',
        'phpunit.xml.dist',
        '.phpunit.cache/**',
        'phpunit.cache/**',
        // Static analysis and style.
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan.cache/**',
        '.editorconfig',
        '.php-cs-fixer.php',
        '.php-cs-fixer.dist.php',
        'ecs.php',
        'psalm.xml',
        'psalm.xml.dist',
        // Mutation testing.
        'infection.json5',
        'infection.json',
        'infection.log',
        '.infection/**',
        // Documentation and meta.
        'README.md',
        'CHANGELOG.md',
        'LICENSE',
        'docs/**',
        // Scaffold metadata itself.
        'scaffold.json',
        'scaffold-lock.json',
        // Runtime bucket (providers ship explicit '.gitignore' if they want).
        'runtime/**',
    ];

    /**
     * Returns `true` when `$relativePath` matches any built-in exclusion pattern.
     *
     * @param string $relativePath Path relative to the provider root, normalised to forward slashes.
     *
     * @return bool `true` when the path is default-excluded, `false` otherwise.
     */
    public static function matches(string $relativePath): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (Glob::matches($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns `true` when `$relativeDir` can be pruned from the walk because a pattern of the form `$relativeDir/**`
     * would exclude every possible descendant.
     *
     * @param string $relativeDir Directory path relative to the walk root, normalised to forward slashes.
     *
     * @return bool `true` when descent into the directory is safe to skip entirely, `false` otherwise.
     */
    public static function matchesDirectory(string $relativeDir): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (str_ends_with($pattern, '/**') && substr($pattern, 0, -3) === $relativeDir) {
                return true;
            }
        }

        return false;
    }
}
