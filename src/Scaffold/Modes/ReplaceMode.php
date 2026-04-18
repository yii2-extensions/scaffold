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
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

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

        PathResolver::ensureDirectory($destination);

        if (copy($source, $destination) === false) {
            throw new RuntimeException(
                sprintf('Could not copy "%s" to "%s".', $source, $destination),
            );
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }
}
