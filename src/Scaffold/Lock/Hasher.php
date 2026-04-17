<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Lock;

use RuntimeException;

use function sprintf;

/**
 * Computes and compares SHA-256 hashes for scaffold file tracking.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Hasher
{
    /**
     * Returns `true` when two hash strings are identical.
     *
     * @param string $a First hash string.
     * @param string $b Second hash string.
     *
     * @return bool `true` if the hashes are identical, `false` otherwise.
     */
    public function equals(string $a, string $b): bool
    {
        return $a === $b;
    }

    /**
     * Returns a `sha256:<hex>` hash of the file at the given path.
     *
     * @param string $absolutePath Absolute path to the file.
     *
     * @throws RuntimeException when the file cannot be read.
     *
     * @return string Computed hash string in the format `sha256:<hex>`.
     */
    public function hash(string $absolutePath): string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException(
                sprintf('Could not hash file "%s": file is unreadable or does not exist.', $absolutePath),
            );
        }

        $result = hash_file('sha256', $absolutePath);

        if ($result === false) {
            throw new RuntimeException(
                sprintf('Could not hash file "%s": file is unreadable or does not exist.', $absolutePath),
            );
        }

        return "sha256:{$result}";
    }
}
