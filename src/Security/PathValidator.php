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

        $normalized = $this->normalizePath($realRoot, $destination);

        if (!str_starts_with($normalized . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(
                sprintf('Destination path escapes the project root: "%s".', $destination),
            );
        }
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

        $normalized = $this->normalizePath($realRoot, $source);

        if (!str_starts_with($normalized . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(
                sprintf('Source path escapes the provider root: "%s".', $source),
            );
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
        $segments = preg_split('#[/\\\\]#', $path);

        if (!is_array($segments)) {
            return;
        }

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
        $combined = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return rtrim($combined, DIRECTORY_SEPARATOR);
    }
}
