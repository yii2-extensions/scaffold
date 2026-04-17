<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function copy;
use function file_exists;
use function sprintf;

/**
 * Applies a scaffold file only when the destination does not already exist.
 *
 * If the destination exists it is never overwritten; the existing hash is returned so the lock file can record the
 * current state.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PreserveMode implements ModeInterface
{
    /**
     * @param string|null $hashAtScaffold Intentionally unused — preserve mode never overwrites an existing file
     * regardless of whether it drifted from the original stub.
     *
     * @throws RuntimeException if the source file cannot be read or the destination file cannot be written.
     *
     * @return ApplyResult Result of the apply operation, indicating whether the file was written or skipped, along with
     * the new hash and any warning message.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);

        if (file_exists($destination)) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        PathResolver::ensureDirectory($destination);

        if (copy($source, $destination) === false) {
            throw new RuntimeException(
                sprintf('Could not copy "%s" to "%s".', $source, $destination),
            );
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }
}
