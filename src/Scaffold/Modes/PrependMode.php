<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

use function sprintf;

/**
 * Applies a scaffold file by prepending its content before an existing destination, or writing it fresh.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PrependMode implements ModeInterface
{
    /**
     * @param string|null $hashAtScaffold Intentionally unused — prepend mode is content-agnostic and relies on the
     * Scaffolder to skip already-locked entries on partial runs.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($mapping->destination, '/\\');

        $source = $mapping->providerPath . '/' . $mapping->source;

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

        $this->ensureDirectory($destination);

        if (file_put_contents($destination, $combined) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    /**
     * Ensures the directory for the given file path exists, creating it if necessary.
     *
     * @param string $absoluteFilePath Absolute path to the file whose directory should be ensured.
     *
     * @throws RuntimeException If the directory cannot be created.
     */
    private function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
