<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use Composer\Package\PackageInterface;
use RuntimeException;

use function is_array;

/**
 * Loads scaffold file mappings from a Composer package manifest.
 *
 * Supports both inline `extra.scaffold.file-mapping` declarations and external `extra.scaffold.manifest` JSON files
 * relative to the provider root.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ManifestLoader
{
    public function __construct(private readonly ManifestSchema $schema) {}

    /**
     * Loads all file mappings declared by a scaffold provider package.
     *
     * @param PackageInterface $package The provider Composer package.
     * @param string $packagePath Absolute path to the provider root inside vendor.
     *
     * @return list<FileMapping> List of file mappings declared by the provider.
     *
     * @throws RuntimeException when the manifest file is missing or invalid.
     */
    public function load(PackageInterface $package, string $packagePath): array
    {
        $extra = $package->getExtra();

        $scaffold = $extra['scaffold'] ?? null;

        if (!is_array($scaffold)) {
            return [];
        }

        if (isset($scaffold['manifest'])) {
            return $this->loadExternal($scaffold['manifest'], $package->getName(), $packagePath);
        }

        if (isset($scaffold['file-mapping'])) {
            return $this->buildFromValidated(
                $this->schema->validate(['file-mapping' => $scaffold['file-mapping']]),
                $package->getName(),
                $packagePath,
            );
        }

        return [];
    }

    /**
     * Builds file mappings from already validated manifest data.
     *
     * @param array<string, array{source: string, mode: string}> $fileMapping Raw file mapping data from the manifest,
     * already validated against the schema.
     *
     * @return list<FileMapping> List of file mappings built from the validated manifest data.
     */
    private function buildFromValidated(array $fileMapping, string $packageName, string $packagePath): array
    {
        $mappings = [];

        foreach ($fileMapping as $destination => $entry) {
            $mappings[] = new FileMapping(
                destination: $destination,
                source: $entry['source'],
                mode: $entry['mode'],
                providerName: $packageName,
                providerPath: $packagePath,
            );
        }

        return $mappings;
    }

    /**
     * Loads file mappings from an external manifest JSON file declared by the provider.
     *
     * @param mixed $manifestPath Raw manifest path from the provider declaration, expected to be a non-empty string.
     * @param string $packageName Name of the provider package.
     * @param string $packagePath Absolute path to the provider root inside vendor.
     *
     * @return list<FileMapping> List of file mappings loaded from the external manifest file.
     */
    private function loadExternal(mixed $manifestPath, string $packageName, string $packagePath): array
    {
        if (!is_string($manifestPath) || $manifestPath === '') {
            throw new RuntimeException(
                sprintf('Provider "%s": "manifest" must be a non-empty string.', $packageName),
            );
        }

        if (
            str_starts_with($manifestPath, '/')
            || str_starts_with($manifestPath, '\\')
            || preg_match('/^[A-Za-z]:/', $manifestPath) === 1
        ) {
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

        return $this->buildFromValidated(
            $this->schema->validate($raw),
            $packageName,
            $packagePath,
        );
    }
}
