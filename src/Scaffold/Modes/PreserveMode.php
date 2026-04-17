<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

/**
 * Applies a scaffold file only when the destination does not already exist.
 *
 * If the destination exists it is never overwritten; the existing hash is returned so the lock
 * file can record the current state.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class PreserveMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = $projectRoot . '/' . $mapping->destination;

        if (file_exists($destination)) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $source = $mapping->providerPath . '/' . $mapping->source;

        $this->ensureDirectory($destination);

        if (copy($source, $destination) === false) {
            throw new RuntimeException(
                sprintf('Could not copy "%s" to "%s".', $source, $destination),
            );
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    private function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
