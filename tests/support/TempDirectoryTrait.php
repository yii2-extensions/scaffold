<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function is_link;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Provides a temporary directory that is created before each test and removed after.
 *
 * Pure-PHP recursive cleanup: walks the tree leaf-first, unlinks files and symlinks, then `rmdir`s directories.
 * On Windows `rmdir` handles directory symlinks cleanly when their target has already been removed; plain file
 * symlinks are `unlink`ed like regular files.
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
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir() && !is_link($path)) {
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($this->tempDir);
    }
}
