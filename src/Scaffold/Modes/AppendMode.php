<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

/**
 * Applies a scaffold file by appending its content to an existing destination, or writing it fresh.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class AppendMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = $projectRoot . '/' . $mapping->destination;
        $source = $mapping->providerPath . '/' . $mapping->source;

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

    private function ensureDirectory(string $absoluteFilePath): void
    {
        $dir = dirname($absoluteFilePath);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
    }
}
