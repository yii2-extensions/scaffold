<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

use function sprintf;

/**
 * Applies a scaffold file by overwriting the destination, unless the user has modified it.
 *
 * When a lock hash exists and the current file hash differs, the file is considered user-modified and the write is
 * skipped with a warning. When no lock hash exists, or hashes match, the source is copied unconditionally.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ReplaceMode implements ModeInterface
{
    /**
     * Applies the replace mode to a file mapping.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param Hasher $hasher Hash utility for computing and comparing file hashes.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at the last scaffold time, or `null` if
     * untracked.
     *
     * @throws RuntimeException if the source file cannot be read or the destination file cannot be written.
     * @return ApplyResult Result of the apply operation, indicating whether the file was written or skipped, along with
     * the new hash and any warning message.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($mapping->destination, '/\\');

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

    /**
     * Ensures that the directory for the given absolute file path exists, creating it if necessary.
     *
     * @param string $absoluteFilePath Absolute path to the file for which to ensure the directory exists.
     *
     * @throws RuntimeException if the directory cannot be created.
     */
    private function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
