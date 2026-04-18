<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function sprintf;

/**
 * Applies a scaffold file by prepending its content before an existing destination, or writing it fresh.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PrependMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        $sourceContent = file_get_contents($source);

        if ($sourceContent === false) {
            throw new RuntimeException(sprintf('Could not read source file "%s".', $source));
        }

        if (file_exists($destination)) {
            $existing = file_get_contents($destination);

            if ($existing === false) {
                throw new RuntimeException(sprintf('Could not read destination file "%s".', $destination));
            }

            $combined = $sourceContent . $existing;
        } else {
            $combined = $sourceContent;
        }

        PathResolver::ensureDirectory($destination);

        if (file_put_contents($destination, $combined) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }
}
