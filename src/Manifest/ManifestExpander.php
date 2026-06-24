<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function is_dir;
use function is_file;
use function rtrim;
use function sort;
use function sprintf;
use function str_ends_with;
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
 *
 * @phpstan-type ValidatedManifest = array{
 *   copy: list<array{from: string, to: string}>,
 *   exclude: list<string>,
 *   modes: array<string, FileMode>,
 * }
 */
final class ManifestExpander
{
    /**
     * Expands a validated manifest into the list of file mappings that the scaffolder must apply.
     *
     * @param ValidatedManifest $manifest Validated manifest as returned by {@see ManifestSchema::validate()}.
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
            $from = $entry['from'];
            $to = $entry['to'];
            $absolute = "{$providerPath}/{$from}";

            if (is_file($absolute)) {
                $source = self::normalise($from);
                $destination = self::normalise($to);

                if (isset($seen[$destination])) {
                    continue;
                }

                // The seen-map value is irrelevant; only key existence matters in the isset() guard above.
                // @codeCoverageIgnoreStart
                $seen[$destination] = true;
                // @codeCoverageIgnoreEnd

                $mappings[] = $this->buildMapping(
                    $destination,
                    $source,
                    $this->resolveMode($destination, $manifest['modes']),
                    $providerName,
                    $providerPath,
                );

                continue;
            }

            if (is_dir($absolute)) {
                // The +1 offset in self::translate() already skips the prefix separator, so trailing slashes here are
                // absorbed downstream; the rtrim is kept defensively for clarity and substring-safety.
                // @codeCoverageIgnoreStart
                $sourcePrefix = rtrim(self::normalise($from), '/');
                // @codeCoverageIgnoreEnd
                $sourcePrefix = $sourcePrefix === '.' ? '' : $sourcePrefix;
                $destPrefix = rtrim(self::normalise($to), '/');
                $destPrefix = $destPrefix === '.' ? '' : $destPrefix;

                foreach ($this->walk($absolute, $sourcePrefix, $manifest['exclude']) as $source) {
                    $destination = self::translate($source, $sourcePrefix, $destPrefix);

                    if (isset($seen[$destination])) {
                        continue;
                    }

                    // The seen-map value is irrelevant; only key existence matters in the isset() guard above.
                    // @codeCoverageIgnoreStart
                    $seen[$destination] = true;
                    // @codeCoverageIgnoreEnd

                    $mappings[] = $this->buildMapping(
                        $destination,
                        $source,
                        $this->resolveMode($destination, $manifest['modes']),
                        $providerName,
                        $providerPath,
                    );
                }

                continue;
            }

            throw new RuntimeException(
                sprintf('Scaffold copy entry "%s" does not exist under provider root "%s".', $from, $providerPath),
            );
        }

        return $mappings;
    }

    /**
     * Recursive filter callback deciding whether an iterator entry should be visited.
     *
     * Files pass through unconditionally so they can be exclude-filtered at file level; directories are tested against
     * the default and user dir-prune patterns so that excluded subtrees (for example `vendor/**`) are never descended.
     *
     * @param SplFileInfo $current Filesystem entry offered by the inner iterator.
     * @param string $absolutePrefix Rtrim'd absolute path of the walk root on disk.
     * @param string $relativePrefix Relative directory prefix to prepend when deriving the walk-relative path.
     * @param list<string> $userExcludes User-declared exclude patterns from the manifest.
     *
     * @return bool `true` when the iterator should accept the entry (and descend, for dirs), `false` to prune it.
     */
    private function acceptIteratorEntry(
        SplFileInfo $current,
        string $absolutePrefix,
        string $relativePrefix,
        array $userExcludes,
    ): bool {
        // Files pass through unconditionally; only directories are subject to the descent-prune patterns. Without
        // this branch, a file like 'secrets' would be dropped when the manifest contains 'secrets/**', since
        // canDescend treats matching exclude prefixes as a hard reject regardless of entry type.
        // @codeCoverageIgnoreStart
        if ($current->isDir() === false) {
            return true;
        }
        // @codeCoverageIgnoreEnd

        return self::canDescend(
            self::relativise($current, $absolutePrefix, $relativePrefix),
            $userExcludes,
        );
    }

    /**
     * Builds a {@see FileMapping} for a given source-destination pair.
     *
     * @param string $destination Relative destination path inside the consumer project, using forward slashes.
     * @param string $source Relative source path inside the provider root, using forward slashes.
     * @param FileMode $mode Resolved file mode for the mapping.
     * @param string $providerName Composer package name (used to attribute the mapping for provenance).
     * @param string $providerPath Absolute path to the provider root on disk.
     *
     * @return FileMapping Concrete file mapping for the given source/destination pair and mode.
     */
    private function buildMapping(
        string $destination,
        string $source,
        FileMode $mode,
        string $providerName,
        string $providerPath,
    ): FileMapping {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: $mode,
            providerName: $providerName,
            providerPath: $providerPath,
        );
    }

    /**
     * Determines whether the iterator may descend into `$relativeDir` based on both default and user exclude patterns.
     *
     * The directory may be pruned whenever a pattern of the form `$relativeDir/**` exists in either layer, because
     * every possible descendant would then match an exclude and be dropped at file level anyway.
     *
     * @param string $relativeDir Directory path relative to the walk root, using forward slashes.
     * @param list<string> $userExcludes User-declared exclude patterns from the manifest.
     *
     * @return bool `true` when the iterator should descend, `false` when the subtree may be skipped entirely.
     */
    private static function canDescend(string $relativeDir, array $userExcludes): bool
    {
        if (DefaultExcludes::matchesDirectory($relativeDir)) {
            return false;
        }

        foreach ($userExcludes as $pattern) {
            if (str_ends_with($pattern, '/**') && substr($pattern, 0, -3) === $relativeDir) {
                return false;
            }
        }

        return true;
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
        // Windows-only normalisation: str_replace is a no-op on POSIX (no backslash separators).
        // @codeCoverageIgnoreStart
        return str_replace('\\', '/', $path);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Derives the walk-relative path for a filesystem entry, using forward slashes.
     *
     * Called both by the recursive filter (to decide whether to prune a directory) and by the main loop (to emit file
     * destinations), so it produces the exact same string for a given entry in either context.
     *
     * @param SplFileInfo $entry Filesystem entry returned by the iterator.
     * @param string $absolutePrefix Rtrim'd absolute path of the walk root on disk.
     * @param string $relativePrefix Relative directory prefix to prepend, or an empty string when walking from root.
     *
     * @return string Path relative to the walk root, using forward slashes.
     */
    private static function relativise(SplFileInfo $entry, string $absolutePrefix, string $relativePrefix): string
    {
        $absolute = self::normalise($entry->getPathname());

        $tail = substr($absolute, strlen($absolutePrefix) + 1);

        return $relativePrefix === '' ? $tail : "{$relativePrefix}/{$tail}";
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
     * Translates a walk-relative source path into its destination path by swapping the source prefix for the
     * destination prefix.
     *
     * Used when a `copy[]` entry remaps a directory (`{from: "metadata/.github", to: ".github"}`) so each file
     * discovered under the source prefix lands at the equivalent location under the destination prefix.
     *
     * @param string $source Walk-relative source path (already prefixed with the source directory).
     * @param string $sourcePrefix Source directory prefix without trailing slash, or empty when walking from root.
     * @param string $destPrefix Destination directory prefix without trailing slash, or empty when destination is
     * the consumer root.
     *
     * @return string Destination path equivalent to `$source` after prefix translation.
     */
    private static function translate(string $source, string $sourcePrefix, string $destPrefix): string
    {
        if ($sourcePrefix === '') {
            return $destPrefix === '' ? $source : "{$destPrefix}/{$source}";
        }

        $tail = substr($source, strlen($sourcePrefix) + 1);

        return $destPrefix === '' ? $tail : "{$destPrefix}/{$tail}";
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
        $results = [];
        $absolutePrefix = rtrim(self::normalise($absoluteDir), '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
                fn(SplFileInfo $current): bool => $this->acceptIteratorEntry(
                    $current,
                    $absolutePrefix,
                    $relativePrefix,
                    $userExcludes,
                ),
            ),
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = self::relativise($entry, $absolutePrefix, $relativePrefix);

            if (DefaultExcludes::matches($relative) || self::matchesAny($relative, $userExcludes)) {
                continue;
            }

            $results[] = $relative;
        }

        // Determinism guarantee for cross-filesystem reproducibility; many filesystems already return sorted dirents,
        // so removing the sort is mutation-equivalent on POSIX/ext4 testbeds.
        // @codeCoverageIgnoreStart
        sort($results);
        // @codeCoverageIgnoreEnd

        return $results;
    }
}
