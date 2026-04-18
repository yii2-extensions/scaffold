<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use RuntimeException;
use yii\helpers\FileHelper;

/**
 * Provides a temporary directory that is created before each test and removed after.
 *
 * Delegates recursive cleanup to {@see FileHelper::removeDirectory()} so Windows directory symlinks are handled
 * correctly regardless of whether the symlink target has already been removed earlier in the walk.
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
            FileHelper::removeDirectory($this->tempDir);
        }
    }
}
