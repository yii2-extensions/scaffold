<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

use function sprintf;

/**
 * Applies a scaffold file by appending its content to an existing destination, or writing it fresh.
 *
 * **Callers are responsible for consulting `scaffold-lock.json`** before invoking this mode. Invoking it without a
 * prior lock check will duplicate content on every call. `Scaffolder` enforces this contract by skipping already-locked
 * append entries on partial runs (`$fullScaffold = false`).
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class AppendMode implements ModeInterface
{
    /**
     * @param string|null $hashAtScaffold Intentionally unused — append mode is content-agnostic and relies on the
     * Scaffolder to skip already-locked entries on partial runs.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($mapping->destination, '/\\');

        $source = "{$mapping->providerPath}/{$mapping->source}";

        $content = file_get_contents($source);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read source file "%s".', $source));
        }

        $this->ensureDirectory($destination);

        $flags = file_exists($destination) ? FILE_APPEND : 0;

        if (file_put_contents($destination, $content, $flags) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    /**
     * Ensures that the directory for the given file path exists, creating it if necessary.
     *
     * @param string $absoluteFilePath Absolute path to the file for which to ensure the directory exists.
     *
     * @throws RuntimeException if the directory cannot be created and does not already exist.
     */
    private function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
