<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Lock;

use RuntimeException;

use function is_array;
use function is_string;

/**
 * Reads and writes the `scaffold-lock.json` file that tracks applied file hashes.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class LockFile
{
    /**
     * @var array{
     *   providers: array<string, mixed>,
     *   files: array<string, array{hash: string, provider: string, source: string, mode: string}>,
     * }|null Cached lock data to avoid redundant file reads. Set to `null` when the cache is invalidated.
     */
    private array|null $cache = null;

    public function __construct(private readonly string $projectRoot) {}

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
        $hash = $entry['hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * Returns the absolute path to `scaffold-lock.json`.
     */
    public function getPath(): string
    {
        return rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'scaffold-lock.json';
    }

    /**
     * Reads and returns the lock data, or a default empty structure when the file does not exist.
     *
     * @throws RuntimeException when the file exists but cannot be decoded as valid JSON.
     *
     * @return array{
     *   providers: array<string, mixed>,
     *   files: array<string, array{hash: string, provider: string, source: string, mode: string}>,
     * } Lock data structure containing provider information and tracked file hashes.
     */
    public function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->getPath();

        if (!is_file($path)) {
            $this->cache = ['providers' => [], 'files' => []];

            return $this->cache;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read lock file "%s".', $path));
        }

        $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Lock file "%s" does not contain a valid JSON object.', $path));
        }

        $providers = is_array($decoded['providers'] ?? null) ? $decoded['providers'] : [];
        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];

        /**
         * @var array{
         *   providers: array<string, mixed>,
         *   files: array<string, array{hash: string, provider: string, source: string, mode: string}>,
         * } $normalized */
        $normalized = ['providers' => $providers, 'files' => $files];

        $this->cache = $normalized;

        return $this->cache;
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
        $this->cache = null;

        $path = $this->getPath();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $tmp = "{$path}.tmp";

        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            throw new RuntimeException(sprintf('Could not write lock file "%s".', $path));
        }
    }
}
