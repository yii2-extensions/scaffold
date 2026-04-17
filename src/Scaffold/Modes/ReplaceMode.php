<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

/**
 * Applies a scaffold file by overwriting the destination, unless the user has modified it.
 *
 * When a lock hash exists and the current file hash differs, the file is considered user-modified
 * and the write is skipped with a warning. When no lock hash exists, or hashes match, the source
 * is copied unconditionally.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ReplaceMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = $projectRoot . '/' . $mapping->destination;
        $source = $mapping->providerPath . '/' . $mapping->source;

        if (file_exists($destination) && $hashAtScaffold !== null) {
            $currentHash = $hasher->hash($destination);

            if (!$hasher->equals($currentHash, $hashAtScaffold)) {
                return new ApplyResult(
                    ApplyOutcome::Skipped,
                    '',
                    sprintf('User-modified file skipped: "%s".', $mapping->destination),
                );
            }
        }

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
