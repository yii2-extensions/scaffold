<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Lock;

use RuntimeException;

/**
 * Computes and compares SHA-256 hashes for scaffold file tracking.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Hasher
{
    /**
     * Returns `true` when two hash strings are identical.
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
     */
    public function hash(string $absolutePath): string
    {
        $result = @hash_file('sha256', $absolutePath);

        if ($result === false) {
            throw new RuntimeException(
                sprintf('Could not hash file "%s": file is unreadable or does not exist.', $absolutePath),
            );
        }

        return 'sha256:' . $result;
    }
}
