<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function array_diff;
use function explode;
use function implode;
use function rtrim;
use function sprintf;
use function str_ends_with;

/**
 * Applies a scaffold file by line-merging its content into the destination, or writing it fresh.
 *
 * Idempotent by design: when the destination already exists, only provider lines that are not already present in the
 * destination are appended. Repeated applications produce no duplication, and consumer-specific additions are never
 * removed.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class AppendMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        $providerContent = file_get_contents($source);

        if ($providerContent === false) {
            throw new RuntimeException(sprintf('Could not read source file "%s".', $source));
        }

        PathResolver::ensureDirectory($destination);

        if (!file_exists($destination)) {
            if (file_put_contents($destination, $providerContent) === false) {
                throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
            }

            return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
        }

        $consumerContent = file_get_contents($destination);

        if ($consumerContent === false) {
            throw new RuntimeException(sprintf('Could not read destination file "%s".', $destination));
        }

        $consumerLines = $consumerContent === '' ? [] : explode("\n", rtrim($consumerContent, "\n"));
        $providerLines = $providerContent === '' ? [] : explode("\n", rtrim($providerContent, "\n"));
        $missing = array_diff($providerLines, $consumerLines);

        if ($missing === []) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $separator = $consumerContent === '' || str_ends_with($consumerContent, "\n") ? '' : "\n";
        $appendData = $separator . implode("\n", $missing) . "\n";

        if (file_put_contents($destination, $appendData, FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }
}
