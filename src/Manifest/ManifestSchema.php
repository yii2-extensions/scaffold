<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use RuntimeException;

/**
 * Validates and normalizes the raw decoded JSON structure of a scaffold manifest.
 *
 * Returns a typed file-mapping array when validation succeeds, or throws on the first
 * structural violation found.
 *
 * @phpstan-type FileMappingEntry array{source: string, mode: string}
 * @phpstan-type ValidatedFileMapping array<string, FileMappingEntry>
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ManifestSchema
{
    private const VALID_MODES = ['append', 'prepend', 'preserve', 'replace'];

    /**
     * Validates a raw decoded manifest array and returns the typed file-mapping.
     *
     * @param array<mixed> $raw Decoded JSON content of the manifest.
     *
     * @return array<string, array{source: string, mode: string}> Validated and typed file-mapping entries.
     *
     * @throws RuntimeException when the manifest structure is invalid.
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

            if (!in_array($entry['mode'], self::VALID_MODES, strict: true)) {
                throw new RuntimeException(
                    sprintf(
                        'Manifest entry for "%s" has invalid mode "%s". Allowed: %s.',
                        $destination,
                        $entry['mode'],
                        implode(', ', self::VALID_MODES),
                    ),
                );
            }

            $typed[(string) $destination] = ['source' => $entry['source'], 'mode' => $entry['mode']];
        }

        return $typed;
    }
}
