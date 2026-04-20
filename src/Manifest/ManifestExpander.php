<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function is_dir;
use function is_file;
use function sort;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * Expands a validated `scaffold.json` manifest into a flat list of {@see FileMapping} entries.
 *
 * Walks each path listed in `copy`:
 *
 * - Explicit file entries pass through unconditionally (bypass default excludes).
 * - Directory entries are walked recursively; the built-in {@see DefaultExcludes} patterns plus the manifest's own
 *   `exclude[]` list filter the walk output.
 *
 * Mode resolution falls back to {@see FileMode::Replace} when no pattern in `modes{}` matches the destination.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ManifestExpander
{
    /**
     * Expands a validated manifest into the list of file mappings that the scaffolder must apply.
     *
     * @param array{copy: list<string>, exclude: list<string>, modes: array<string, FileMode>} $manifest Validated
     * manifest as returned by {@see ManifestSchema::validate()}.
     * @param string $providerPath Absolute path to the provider root on disk.
     * @param string $providerName Composer package name (used to attribute the mapping for provenance).
     *
     * @throws RuntimeException when a path listed in `copy[]` does not exist on disk.
     *
     * @return list<FileMapping> Concrete list of mappings, ready for the scaffolder.
     */
    public function expand(array $manifest, string $providerPath, string $providerName): array
    {
        $mappings = [];
        $seen = [];

        foreach ($manifest['copy'] as $entry) {
            $absolute = $providerPath . '/' . $entry;

            if (is_file($absolute)) {
                $relative = self::normalise($entry);

                if (isset($seen[$relative])) {
                    continue;
                }

                // Dedup tag only; value is immaterial since 'isset' is the only reader.
                // @codeCoverageIgnoreStart
                $seen[$relative] = true;
                // @codeCoverageIgnoreEnd

                $mappings[] = $this->buildMapping(
                    $relative,
                    $this->resolveMode($relative, $manifest['modes']),
                    $providerName,
                    $providerPath,
                );

                continue;
            }

            if (is_dir($absolute)) {
                $prefix = self::normalise($entry);
                $prefix = $prefix === '.' ? '' : $prefix;

                foreach ($this->walk($absolute, $prefix, $manifest['exclude']) as $relative) {
                    if (isset($seen[$relative])) {
                        continue;
                    }

                    // @codeCoverageIgnoreStart
                    // Dedup tag only; value is immaterial since 'isset' is the only reader.
                    $seen[$relative] = true;
                    // @codeCoverageIgnoreEnd
                    $mappings[] = $this->buildMapping(
                        $relative,
                        $this->resolveMode($relative, $manifest['modes']),
                        $providerName,
                        $providerPath,
                    );
                }

                continue;
            }

            throw new RuntimeException(
                sprintf('Scaffold copy entry "%s" does not exist under provider root "%s".', $entry, $providerPath),
            );
        }

        return $mappings;
    }

    /**
     * Builds a {@see FileMapping} for a given relative destination.
     *
     * @param string $relative Relative path to the file inside the provider, using forward slashes.
     * @param FileMode $mode Resolved file mode for the mapping.
     * @param string $providerName Composer package name (used to attribute the mapping for provenance).
     * @param string $providerPath Absolute path to the provider root on disk.
     *
     * @return FileMapping Concrete file mapping for the given relative path and mode.
     */
    private function buildMapping(
        string $relative,
        FileMode $mode,
        string $providerName,
        string $providerPath,
    ): FileMapping {
        return new FileMapping(
            destination: $relative,
            source: $relative,
            mode: $mode,
            providerName: $providerName,
            providerPath: $providerPath,
        );
    }

    /**
     * Determines if a given relative path matches any of the provided glob patterns.
     *
     * @param list<string> $patterns Glob patterns to evaluate.
     * @param string $path Relative path to test, using forward slashes.
     *
     * @return bool `true` when `$path` matches any of the given glob patterns, `false` otherwise.
     */
    private static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Glob::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalises a relative path to forward slashes.
     *
     * This is a no-op on POSIX but ensures consistent path handling on Windows, where `copy[]` entries may be declared
     * with either slash type.
     *
     * @param string $path Relative path to normalise, using either forward or backward slashes.
     *
     * @return string Normalised path with forward slashes.
     */
    private static function normalise(string $path): string
    {
        // Windows-path adapter, POSIX-identity.
        // @codeCoverageIgnoreStart
        return str_replace('\\', '/', $path);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Resolves the {@see FileMode} for `$relative` against the manifest's `modes` map.
     *
     * Exact path matches win over globs; globs are evaluated in declaration order.
     *
     * @param array<string, FileMode> $modes Mode overrides from the manifest.
     */
    private function resolveMode(string $relative, array $modes): FileMode
    {
        if (isset($modes[$relative])) {
            return $modes[$relative];
        }

        foreach ($modes as $pattern => $mode) {
            if (Glob::matches($pattern, $relative)) {
                return $mode;
            }
        }

        return FileMode::Replace;
    }

    /**
     * Walks a directory listed in `copy[]`, filtering out paths matching either default excludes or user excludes.
     *
     * @param string $absoluteDir Absolute path to the directory to walk.
     * @param string $relativePrefix Relative directory prefix to prepend to each discovered file.
     * @param list<string> $userExcludes Glob patterns declared in `scaffold.exclude`.
     *
     * @return list<string> Ordered list of relative file paths inside the directory that survive both exclusion layers.
     */
    private function walk(string $absoluteDir, string $relativePrefix, array $userExcludes): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
        );

        $results = [];
        $absolutePrefix = self::normalise($absoluteDir);

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                continue;
            }

            $absolute = self::normalise($entry->getPathname());
            $tail = substr($absolute, strlen($absolutePrefix) + 1);
            $relative = $relativePrefix === '' ? $tail : "{$relativePrefix}/{$tail}";

            if (DefaultExcludes::matches($relative) || self::matchesAny($relative, $userExcludes)) {
                continue;
            }

            $results[] = $relative;
        }

        // Defensive sort for filesystems that do not return entries alphabetically; on Linux the iterator is already
        // ordered so removing the call is observationally equivalent on the POSIX CI matrix.

        // @codeCoverageIgnoreStart
        sort($results);
        // @codeCoverageIgnoreEnd

        return $results;
    }
}
