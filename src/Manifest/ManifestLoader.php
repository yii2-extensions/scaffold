<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use Composer\Package\PackageInterface;
use RuntimeException;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_starts_with;

/**
 * Loads and expands a scaffold provider's manifest into a flat list of {@see FileMapping} entries.
 *
 * Supports both inline `extra.scaffold` declarations and external JSON manifests referenced by
 * `extra.scaffold.manifest` relative to the provider root.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ManifestLoader
{
    public function __construct(private readonly ManifestSchema $schema, private readonly ManifestExpander $expander) {}

    /**
     * Loads the provider's manifest and expands it into concrete file mappings.
     *
     * @param PackageInterface $package Provider Composer package.
     * @param string $packagePath Absolute path to the provider root on disk.
     *
     * @throws RuntimeException when the manifest file is missing, malformed, or structurally invalid.
     *
     * @return list<FileMapping> File mappings ready for the scaffolder.
     */
    public function load(PackageInterface $package, string $packagePath): array
    {
        $extra = $package->getExtra();

        $scaffold = $extra['scaffold'] ?? null;

        if (!is_array($scaffold)) {
            return [];
        }

        $raw = isset($scaffold['manifest'])
            ? $this->readExternal($scaffold['manifest'], $package->getName(), $packagePath)
            : $scaffold;

        $validated = $this->schema->validate($raw);

        return $this->expander->expand($validated, $packagePath, $package->getName());
    }

    /**
     * Reads and decodes an external manifest file referenced from `extra.scaffold.manifest`.
     *
     * @param mixed $manifestPath Raw manifest path from the provider declaration, expected to be a non-empty string.
     * @param string $packageName Provider Composer package name (used for error messages).
     * @param string $packagePath Absolute path to the provider root on disk.
     *
     * @throws RuntimeException on any failure to read or decode the file.
     *
     * @return array<mixed> Raw decoded JSON content ready for {@see ManifestSchema::validate()}.
     */
    private function readExternal(mixed $manifestPath, string $packageName, string $packagePath): array
    {
        if (!is_string($manifestPath) || $manifestPath === '') {
            throw new RuntimeException(
                sprintf('Provider "%s": "manifest" must be a non-empty string.', $packageName),
            );
        }

        // Windows-absolute-path guards, equivalent under POSIX.
        // @codeCoverageIgnoreStart
        $isAbsolute = str_starts_with($manifestPath, '/')
            || str_starts_with($manifestPath, '\\')
            || preg_match('/^[A-Za-z]:/', $manifestPath) === 1;
        // @codeCoverageIgnoreEnd

        if ($isAbsolute) {
            throw new RuntimeException(
                sprintf('Provider "%s": "manifest" must be a relative path inside the provider root.', $packageName),
            );
        }

        $segments = preg_split('#[/\\\\]#', $manifestPath);

        if (is_array($segments) && in_array('..', $segments, true)) {
            throw new RuntimeException(
                sprintf('Provider "%s": "manifest" must be a relative path inside the provider root.', $packageName),
            );
        }

        $absolutePath = $packagePath . '/' . $manifestPath;

        if (!is_file($absolutePath)) {
            throw new RuntimeException(
                sprintf('Provider "%s": manifest file not found at "%s".', $packageName, $absolutePath),
            );
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new RuntimeException(
                sprintf('Provider "%s": could not read manifest file "%s".', $packageName, $absolutePath),
            );
        }

        $raw = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($raw)) {
            throw new RuntimeException(
                sprintf('Provider "%s": manifest file "%s" must decode to an object.', $packageName, $absolutePath),
            );
        }

        return $raw;
    }
}
