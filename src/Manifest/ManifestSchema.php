<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

use RuntimeException;

use function array_column;
use function array_key_exists;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_starts_with;

/**
 * Validates and normalises the `scaffold.json` manifest structure.
 *
 * Accepts the `copy` / `exclude` / `modes` schema introduced in `0.2.0` and returns a typed structure ready for
 * {@see ManifestExpander} to consume. Throws {@see RuntimeException} with a descriptive message on the first
 * structural violation found.
 *
 * @phpstan-type ValidatedManifest array{copy: list<string>, exclude: list<string>, modes: array<string, FileMode>}
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ManifestSchema
{
    /**
     * Validates a raw decoded manifest array and returns the typed structure.
     *
     * @param array<mixed> $raw Decoded JSON content of the manifest (or the inline `extra.scaffold` array).
     *
     * @throws RuntimeException when the manifest structure is invalid.
     *
     * @return array{copy: list<string>, exclude: list<string>, modes: array<string, FileMode>} Typed manifest with
     * `copy[]` paths normalised to forward slashes and `modes` values resolved to {@see FileMode} cases.
     */
    public function validate(array $raw): array
    {
        return [
            'copy' => $this->validateCopy($raw),
            'exclude' => $this->validateExclude($raw),
            'modes' => $this->validateModes($raw),
        ];
    }

    /**
     * Asserts that `$path` is a non-empty relative string without traversal segments or absolute prefixes.
     *
     * @param mixed $path Candidate path (validated to be string).
     * @param string $key Name of the manifest key being validated (for error messages).
     *
     * @throws RuntimeException when the path fails any of the safety checks.
     *
     * @phpstan-assert non-empty-string $path
     */
    private function assertSafeRelativePath(mixed $path, string $key): void
    {
        if (!is_string($path) || $path === '') {
            throw new RuntimeException(
                sprintf('Manifest "%s" entries must be non-empty strings.', $key),
            );
        }

        // reject absolute paths: POSIX ('/foo'), Windows UNC/backslash ('\foo'), Windows drive ('C:\foo').
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1;

        if ($isAbsolute) {
            throw new RuntimeException(
                sprintf('Manifest "%s" entry "%s" must be a relative path.', $key, $path),
            );
        }

        $segments = preg_split('#[/\\\\]#', $path);

        if (is_array($segments) && in_array('..', $segments, true)) {
            throw new RuntimeException(
                sprintf('Manifest "%s" entry "%s" must not contain path traversal segments.', $key, $path),
            );
        }
    }

    /**
     * Validates the `copy` key.
     *
     * @param array<mixed> $raw Raw manifest array.
     *
     * @return list<string> List of non-empty, relative, non-traversal paths.
     */
    private function validateCopy(array $raw): array
    {
        if (!array_key_exists('copy', $raw) || !is_array($raw['copy'])) {
            throw new RuntimeException('Manifest is missing required key "copy" or it is not an array.');
        }

        if ($raw['copy'] === []) {
            throw new RuntimeException('Manifest "copy" must declare at least one path.');
        }

        $paths = [];

        foreach ($raw['copy'] as $path) {
            $this->assertSafeRelativePath($path, 'copy');

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Validates the optional `exclude` key.
     *
     * @param array<mixed> $raw Raw manifest array.
     *
     * @return list<string> List of non-empty, relative, non-traversal patterns.
     */
    private function validateExclude(array $raw): array
    {
        if (!array_key_exists('exclude', $raw)) {
            return [];
        }

        if (!is_array($raw['exclude'])) {
            throw new RuntimeException('Manifest "exclude" must be an array when present.');
        }

        $patterns = [];

        foreach ($raw['exclude'] as $pattern) {
            $this->assertSafeRelativePath($pattern, 'exclude');

            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * Validates the optional `modes` key.
     *
     * @param array<mixed> $raw Raw manifest array.
     *
     * @return array<string, FileMode> Mode map keyed by pattern.
     */
    private function validateModes(array $raw): array
    {
        if (!array_key_exists('modes', $raw)) {
            return [];
        }

        if (!is_array($raw['modes'])) {
            throw new RuntimeException('Manifest "modes" must be an object when present.');
        }

        $resolved = [];

        foreach ($raw['modes'] as $pattern => $modeValue) {
            $this->assertSafeRelativePath($pattern, 'modes');

            if (!is_string($modeValue)) {
                throw new RuntimeException(
                    sprintf('Manifest "modes" value for pattern "%s" must be a string.', $pattern),
                );
            }

            $mode = FileMode::tryFrom($modeValue);

            if ($mode === null) {
                throw new RuntimeException(
                    sprintf(
                        'Manifest "modes" value for pattern "%s" has invalid mode "%s". Allowed: %s.',
                        $pattern,
                        $modeValue,
                        implode(', ', array_column(FileMode::cases(), 'value')),
                    ),
                );
            }

            $resolved[$pattern] = $mode;
        }

        return $resolved;
    }
}
