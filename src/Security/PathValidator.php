<?php

declare(strict_types=1);

namespace yii\scaffold\Security;

use RuntimeException;

use function sprintf;

/**
 * Validates scaffold paths against path traversal and absolute path injection.
 *
 * Both destination (project-relative) and source (provider-relative) paths are validated before any filesystem
 * operation. Rejects paths containing `..` as a segment and paths that begin with a directory separator.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PathValidator
{
    /**
     * Validates that a destination path does not escape the project root.
     *
     * @param string $destination Relative destination path declared in the manifest.
     * @param string $projectRoot Absolute path to the project root directory.
     *
     * @throws RuntimeException on path traversal or absolute path attempt.
     */
    public function validateDestination(string $destination, string $projectRoot): void
    {
        $this->assertRelative($destination, 'destination');
        $this->assertNoTraversal($destination, 'destination');

        $realRoot = realpath($projectRoot);

        if ($realRoot === false) {
            throw new RuntimeException(
                sprintf('Project root does not exist or is not accessible: "%s".', $projectRoot),
            );
        }

        /**
         * After assertRelative + assertNoTraversal the relative segment cannot escape the base, so normalizePath's
         * literal concatenation is guaranteed to stay under $realRoot; the remaining risk is a symlinked ancestor,
         * which assertNoSymlinkEscape covers below.
         */
        $normalized = $this->normalizePath($realRoot, $destination);

        $this->assertNoSymlinkEscape($normalized, $realRoot, 'destination', $destination);
    }

    /**
     * Validates that a source path does not escape the provider root.
     *
     * @param string $source Relative source path declared in the manifest.
     * @param string $providerRoot Absolute path to the provider root inside vendor.
     *
     * @throws RuntimeException on path traversal or absolute path attempt.
     */
    public function validateSource(string $source, string $providerRoot): void
    {
        $this->assertRelative($source, 'source');
        $this->assertNoTraversal($source, 'source');

        $realRoot = realpath($providerRoot);

        if ($realRoot === false) {
            throw new RuntimeException(
                sprintf('Provider root does not exist or is not accessible: "%s".', $providerRoot),
            );
        }

        /**
         * See validateDestination: the redundant containment check was removed because normalizePath's literal
         * concatenation cannot escape $realRoot after assertRelative + assertNoTraversal; symlink ancestors are
         * still verified below.
         */
        $normalized = $this->normalizePath($realRoot, $source);

        $this->assertNoSymlinkEscape($normalized, $realRoot, 'source', $source);
    }

    /**
     * Walks up from the deepest existing ancestor of `$normalized` and rejects the path if that ancestor resolves
     * outside `$realRoot` via a symlink.
     *
     * Checking only `realpath(dirname($normalized))` misses the case where the immediate parent does not yet exist but
     * a higher-level ancestor is a symlink that escapes the root. This method finds the deepest path segment that
     * already exists on disk, resolves it, and enforces containment.
     *
     * @param string $normalized Absolute, traversal-free path to validate.
     * @param string $realRoot Resolved root boundary (output of `realpath()`).
     * @param string $context Human-readable label for error messages (`'source'` or `'destination'`).
     * @param string $originalPath Original user-supplied path, used only in the exception message.
     *
     * @throws RuntimeException when a symlink ancestor escapes `$realRoot`.
     */
    private function assertNoSymlinkEscape(
        string $normalized,
        string $realRoot,
        string $context,
        string $originalPath,
    ): void {
        $resolved = realpath($normalized);

        if ($resolved !== false) {
            if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(
                    sprintf('%s path escapes the root via symlink: "%s".', ucfirst($context), $originalPath),
                );
            }

            return;
        }

        $dir = dirname($normalized);

        while ($dir !== $realRoot && $dir !== dirname($dir)) {
            $resolved = realpath($dir);

            if (
                $resolved !== false
                && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException(
                    sprintf('%s path escapes the root via symlink: "%s".', ucfirst($context), $originalPath),
                );
            }

            $dir = dirname($dir);
        }
    }

    /**
     * Asserts that no path segment is `..`, preventing directory traversal.
     *
     * @param string $path Path to validate.
     * @param string $context Contextual name for error messages.
     *
     * @throws RuntimeException when a traversal segment is detected.
     */
    private function assertNoTraversal(string $path, string $context): void
    {
        /**
         * Normalize both separators to `/` then split; `explode` always returns array<string>, avoiding the
         * defensive false-branch that `preg_split` would have produced for malformed input.
         */
        $segments = explode('/', str_replace('\\', '/', $path));

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new RuntimeException(
                    sprintf('Path traversal detected in %s: "%s".', $context, $path),
                );
            }
        }
    }

    /**
     * Asserts that a path is relative (does not start with a directory separator or drive letter).
     *
     * @throws RuntimeException when an absolute path is detected.
     */
    private function assertRelative(string $path, string $context): void
    {
        if (
            str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            throw new RuntimeException(
                sprintf('Absolute path is not allowed in %s: "%s".', $context, $path),
            );
        }
    }

    /**
     * Normalizes a base + relative path without requiring the target to exist on disk.
     *
     * The relative path must have already passed traversal validation this method does not re-validate and assumes no
     * `..` segments are present.
     *
     * @param string $base Absolute base path.
     * @param string $relative Relative path to combine with the base.
     *
     * @return string Normalized absolute path combining the base and relative segments.
     */
    private function normalizePath(string $base, string $relative): string
    {
        // @codeCoverageIgnoreStart
        $normalizedRelative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        // @codeCoverageIgnoreEnd

        $combined = $base . DIRECTORY_SEPARATOR . $normalizedRelative;

        return rtrim($combined, DIRECTORY_SEPARATOR);
    }
}
