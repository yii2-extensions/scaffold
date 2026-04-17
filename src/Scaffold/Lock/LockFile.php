<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Lock;

use RuntimeException;

/**
 * Reads and writes the `scaffold-lock.json` file that tracks applied file hashes.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class LockFile
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * Returns `true` when `scaffold-lock.json` exists on disk.
     */
    public function exists(): bool
    {
        return is_file($this->getPath());
    }

    /**
     * Returns the hash recorded at scaffold time for the given destination, or `null` if not tracked.
     *
     * @param string $destination Relative destination path as declared in the manifest.
     */
    public function getHashAtScaffold(string $destination): string|null
    {
        $data = $this->read();
        $entry = $data['files'][$destination] ?? null;
        return $entry !== null ? $entry['hash'] : null;
    }

    /**
     * Returns the absolute path to `scaffold-lock.json`.
     */
    public function getPath(): string
    {
        return $this->projectRoot . '/scaffold-lock.json';
    }

    /**
     * Reads and returns the lock data, or a default empty structure when the file does not exist.
     *
     * @return array{providers: array<string, mixed>, files: array<string, array{hash: string, provider: string, source: string, mode: string}>}
     *
     * @throws RuntimeException when the file exists but cannot be decoded as valid JSON.
     */
    public function read(): array
    {
        $path = $this->getPath();

        if (!is_file($path)) {
            return ['providers' => [], 'files' => []];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read lock file "%s".', $path));
        }

        /** @var array{providers: array<string, mixed>, files: array<string, array{hash: string, provider: string, source: string, mode: string}>} $data */
        $data = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Writes lock data to `scaffold-lock.json` with stable JSON formatting.
     *
     * @param array<string, mixed> $data Lock data to persist.
     *
     * @throws RuntimeException when the file cannot be written.
     */
    public function write(array $data): void
    {
        $path = $this->getPath();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException(sprintf('Could not write lock file "%s".', $path));
        }
    }
}
