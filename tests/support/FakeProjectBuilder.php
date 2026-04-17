<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use RuntimeException;

/**
 * Builds a temporary fake project and vendor directory for functional scaffold tests.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class FakeProjectBuilder
{
    private readonly string $projectRoot;
    private readonly string $vendorDir;

    public function __construct(string $tempBase)
    {
        $this->projectRoot = $tempBase . '/project';
        $this->vendorDir = $tempBase . '/vendor';

        if (!is_dir($this->projectRoot)
            && mkdir($this->projectRoot, 0777, recursive: true) === false
            && !is_dir($this->projectRoot)
        ) {
            throw new RuntimeException(sprintf('Could not create project root "%s".', $this->projectRoot));
        }

        if (!is_dir($this->vendorDir)
            && mkdir($this->vendorDir, 0777, recursive: true) === false
            && !is_dir($this->vendorDir)
        ) {
            throw new RuntimeException(sprintf('Could not create vendor dir "%s".', $this->vendorDir));
        }
    }

    /**
     * Creates a file in the project root (simulates a pre-existing project file).
     *
     * @param string $relPath Path relative to the project root.
     * @param string $content File content to write.
     */
    public function createProjectFile(string $relPath, string $content): void
    {
        $full = $this->projectRoot . '/' . $relPath;
        $dir = dirname($full);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }

        file_put_contents($full, $content);
    }

    /**
     * Creates a stub file for a provider package inside the fake vendor directory.
     *
     * @param string $providerName Composer package name (for example, `yii2-extensions/app-base-scaffold`).
     * @param string $relPath Path relative to the provider root (for example, `stubs/config/params.php`).
     * @param string $content File content to write.
     */
    public function createStubFile(string $providerName, string $relPath, string $content): void
    {
        $full = $this->vendorDir . '/' . $providerName . '/' . $relPath;
        $dir = dirname($full);

        if (!is_dir($dir) && mkdir($dir, 0777, recursive: true) === false && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }

        file_put_contents($full, $content);
    }

    /**
     * Returns the absolute path to the fake project root.
     *
     * @return string Absolute path to the fake project root.
     */
    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Returns the absolute path to the fake vendor directory.
     *
     * @return string Absolute path to the fake vendor directory.
     */
    public function getVendorDir(): string
    {
        return $this->vendorDir;
    }
}
