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

        $composerJson = rtrim($projectRoot, '/\\') . '/composer.json';

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
     * @param string $path Absolute or relative filesystem path.
     * @param string $projectRoot Absolute path used as the base for relative `$path` values.
     *
     * @return string Absolute path with no trailing directory separator.
     */
    private static function absolutize(string $path, string $projectRoot): string
    {
        $trimmed = rtrim($path, '/\\');

        $isAbsolute = str_starts_with($trimmed, '/')
            || str_starts_with($trimmed, '\\')
            || preg_match('/^[A-Za-z]:/', $trimmed) === 1;

        return $isAbsolute
            ? $trimmed
            : rtrim($projectRoot, '/\\') . '/' . ltrim($trimmed, '/\\');
    }
}
