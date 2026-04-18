<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold;

use RuntimeException;

use function dirname;
use function is_array;
use function is_string;
use function ltrim;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * Resolves absolute scaffold paths from base roots and relative segments.
 *
 * Centralizes path joining, directory creation, and provider-root resolution used by mode strategies and console
 * controllers. Callers remain responsible for validating the relative segments with {@see PathValidator} beforehand.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PathResolver
{
    /**
     * Joins a project root with a relative destination path using the platform separator.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param string $destination Relative path to a destination file or directory inside the project, using forward
     * slashes as separators.
     *
     * @return string Absolute path to the destination file or directory inside the project, with platform separators.
     */
    public static function destination(string $projectRoot, string $destination): string
    {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($destination, '/\\');
    }

    /**
     * Creates the parent directory for the given absolute file path when it does not exist.
     *
     * @throws RuntimeException when the directory cannot be created.
     */
    public static function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }

    /**
     * Returns the canonical absolute path of `$path`, falling back to a trimmed copy when `realpath()` fails.
     *
     * @param string $path Absolute or relative path to resolve.
     *
     * @return string Canonical absolute path or trimmed fallback.
     */
    public static function realpathOrFallback(string $path): string
    {
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : rtrim($path, '/\\');
    }

    /**
     * Resolves the absolute root of a provider package, honoring the lock-recorded path when it stays inside the vendor
     * directory.
     *
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param string $providerName Provider package name (for example, `yii2-extensions/app-base`).
     * @param mixed $providerLock Decoded `providers.<name>` entry from `scaffold-lock.json`, when present.
     *
     * @return array{root: string, warning: string|null} An array containing the resolved provider root and an optional
     * warning message when the lock-recorded path is invalid.
     */
    public static function resolveProviderRoot(string $vendorDir, string $providerName, mixed $providerLock): array
    {
        $safeVendorDir = self::realpathOrFallback($vendorDir);

        $defaultRoot = $safeVendorDir . DIRECTORY_SEPARATOR . $providerName;

        if (!is_array($providerLock) || !is_string($providerLock['path'] ?? null)) {
            return ['root' => $defaultRoot, 'warning' => null];
        }

        $candidate = self::realpathOrFallback($providerLock['path']);

        if (str_starts_with($candidate . DIRECTORY_SEPARATOR, $safeVendorDir . DIRECTORY_SEPARATOR)) {
            return ['root' => $candidate, 'warning' => null];
        }

        return [
            'root' => $defaultRoot,
            'warning' => sprintf(
                '[scaffold] Provider root for "%s" resolves outside vendor dir; using default path.',
                $providerName,
            ),
        ];
    }

    /**
     * Joins a provider root with a relative source path using the platform separator.
     *
     * Forward slashes inside `$source` are normalized to the platform separator.
     *
     * @param string $providerPath Absolute path to the provider root directory.
     * @param string $source Relative path to a source file or directory inside the provider, using forward slashes as
     * separators.
     *
     * @return string Absolute path to the source file or directory inside the provider, with platform separators.
     */
    public static function source(string $providerPath, string $source): string
    {
        return rtrim($providerPath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($source, '/\\'));
    }
}
