<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use function file_get_contents;
use function getenv;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function rtrim;
use function str_starts_with;

/**
 * Resolves the absolute path of Composer's vendor directory for a given project root.
 *
 * Honors (in priority order):
 *
 * 1. The `COMPOSER_VENDOR_DIR` environment variable.
 * 2. The `config.vendor-dir` entry of the project's `composer.json`.
 * 3. The default `<project-root>/vendor`.
 *
 * Keeps the `diff` / `reapply` commands correct for projects that customise the vendor directory via Composer's
 * `config.vendor-dir` key or the `COMPOSER_VENDOR_DIR` environment variable.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class VendorDirResolver
{
    /**
     * Returns the absolute path to the vendor directory for the project rooted at `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     *
     * @return string Absolute path to the resolved vendor directory.
     */
    public static function resolve(string $projectRoot): string
    {
        $env = getenv('COMPOSER_VENDOR_DIR');

        if (is_string($env) && $env !== '') {
            return self::absolutize($env, $projectRoot);
        }

        // @codeCoverageIgnoreStart defensive rtrim, POSIX-equivalent.
        $trimmedRoot = rtrim($projectRoot, '/\\');
        // @codeCoverageIgnoreEnd

        $composerJson = $trimmedRoot . '/composer.json';

        if (is_file($composerJson)) {
            $raw = file_get_contents($composerJson);

            if (is_string($raw)) {
                $decoded = json_decode($raw, true);

                $config = is_array($decoded) && isset($decoded['config']) && is_array($decoded['config'])
                    ? $decoded['config']
                    : [];

                $custom = $config['vendor-dir'] ?? null;

                if (is_string($custom) && $custom !== '') {
                    return self::absolutize($custom, $projectRoot);
                }
            }
        }

        return rtrim($projectRoot, '/\\') . '/vendor';
    }

    /**
     * Converts `$path` to an absolute path, resolving relative segments against `$projectRoot`.
     *
     * Absoluteness detection runs on the original `$path` so Windows drive-relative inputs like `C:vendor` (distinct
     * from the absolute `C:\vendor`) fall through to the `$projectRoot`-joined branch. Drive-root inputs like `C:\` and
     * `C:/` keep their trailing separator intact because stripping it would turn them into the drive-relative `C:`
     * token.
     *
     * @param string $path Absolute or relative filesystem path.
     * @param string $projectRoot Absolute path used as the base for relative `$path` values.
     *
     * @return string Absolute path. A drive-root input preserves its trailing separator; every other absolute path has
     * trailing separators stripped.
     */
    private static function absolutize(string $path, string $projectRoot): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;

        if ($isAbsolute) {
            // preserve drive-root separator: "C:\" and "C:/" must remain 3 characters.
            if (preg_match('#^([A-Za-z]:)([/\\\\])$#', $path, $matches) === 1) {
                return $matches[1] . $matches[2];
            }

            return rtrim($path, '/\\');
        }

        // @codeCoverageIgnoreStart defensive ltrim, equivalent in the else branch.
        $strippedPath = ltrim($path, '/\\');
        // @codeCoverageIgnoreEnd

        return rtrim($projectRoot, '/\\') . '/' . rtrim($strippedPath, '/\\');
    }
}
