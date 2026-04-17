<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use RuntimeException;

/**
 * Provides a temporary directory that is created before each test and removed after.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
trait TempDirectoryTrait
{
    private string $tempDir = '';

    protected function setUpTempDirectory(): void
    {
        $path = sys_get_temp_dir() . '/scaffold-test-' . uniqid('', true);

        if (mkdir($path, 0777, recursive: true) === false) {
            throw new RuntimeException(sprintf('Could not create temp directory "%s".', $path));
        }

        $this->tempDir = $path;
    }

    protected function tearDownTempDirectory(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $path): void
    {
        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = "{$path}/{$entry}";

            if (is_link($full)) {
                unlink($full);
            } elseif (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
