<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use RuntimeException;

use function array_column;
use function array_key_exists;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Validates and normalizes the raw decoded JSON structure of a scaffold manifest.
 *
 * Returns a typed file-mapping array (with `mode` already resolved to a {@see FileMode} case) when validation succeeds,
 * or throws on the first structural violation found.
 *
 * @phpstan-type FileMappingEntry array{source: string, mode: FileMode}
 * @phpstan-type ValidatedFileMapping array<string, FileMappingEntry>
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ManifestSchema
{
    /**
     * Validates a raw decoded manifest array and returns the typed file-mapping.
     *
     * @param array<mixed> $raw Decoded JSON content of the manifest.
     *
     * @throws RuntimeException when the manifest structure is invalid.
     *
     * @return array<string, array{source: string, mode: FileMode}> Validated and typed file-mapping entries with `mode`
     * resolved to the corresponding {@see FileMode} case.
     */
    public function validate(array $raw): array
    {
        if (!array_key_exists('file-mapping', $raw) || !is_array($raw['file-mapping'])) {
            throw new RuntimeException(
                'Manifest is missing required key "file-mapping" or it is not an array.',
            );
        }

        $typed = [];

        foreach ($raw['file-mapping'] as $destination => $entry) {
            if (!is_string($destination) || $destination === '') {
                throw new RuntimeException(
                    sprintf('Manifest file-mapping key must be a non-empty string, got "%s".', $destination),
                );
            }

            if (!is_array($entry)) {
                throw new RuntimeException(
                    sprintf('Manifest entry for "%s" must be an object.', $destination),
                );
            }

            if (!isset($entry['source']) || !is_string($entry['source']) || $entry['source'] === '') {
                throw new RuntimeException(
                    sprintf('Manifest entry for "%s" is missing a non-empty "source" key.', $destination),
                );
            }

            if (!isset($entry['mode']) || !is_string($entry['mode'])) {
                throw new RuntimeException(
                    sprintf('Manifest entry for "%s" is missing a "mode" key.', $destination),
                );
            }

            $mode = FileMode::tryFrom($entry['mode']);

            if ($mode === null) {
                throw new RuntimeException(
                    sprintf(
                        'Manifest entry for "%s" has invalid mode "%s". Allowed: %s.',
                        $destination,
                        $entry['mode'],
                        implode(', ', array_column(FileMode::cases(), 'value')),
                    ),
                );
            }

            $typed[$destination] = ['source' => $entry['source'], 'mode' => $mode];
        }

        return $typed;
    }
}
