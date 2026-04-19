<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\{FileMapping, FileMode};
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{ApplyOutcome, ReplaceMode};
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see ReplaceMode} scaffold file application strategy.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class ReplaceModeTest extends TestCase
{
    use TempDirectoryTrait;

    public function testCreatesIntermediateDirectories(): void
    {
        $this->makeSourceFile(relative: 'stubs/nested/deep.txt');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping('a/b/c/deep.txt', 'stubs/nested/deep.txt'),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'Expected file to be written successfully, even if intermediate directories do not exist.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/a/b/c/deep.txt",
            'Expected file to exist after creating intermediate directories.',
        );
    }

    public function testOverwritesFileWhenHashMatchesLockHash(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'original scaffold content');

        $this->makeSourceFile('updated stub content');

        $hasher = new Hasher();

        $lockHash = $hasher->hash($projectDir . '/output.txt');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $projectDir,
            $hasher,
            $lockHash,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'Expected file to be overwritten when hash matches lock hash.',
        );
        self::assertSame(
            'updated stub content',
            file_get_contents($projectDir . '/output.txt'),
            'Expected file content to be updated when hash matches lock hash.',
        );
    }

    public function testResultHashMatchesActualFileHash(): void
    {
        $this->makeSourceFile('known content');

        $hasher = new Hasher();
        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            $hasher,
            null,
        );

        $actualHash = $hasher->hash("{$this->tempDir}/project/output.txt");

        self::assertSame(
            $actualHash,
            $result->newHash,
            'Expected result hash to match the actual file hash.',
        );
    }

    public function testSkipsFileWhenUserHasModifiedIt(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'original scaffold content');

        $this->makeSourceFile('updated stub content');

        $hasher = new Hasher();

        // lock hash recorded for original; user then modified the file to different content.
        $lockHash = 'sha256:' . hash('sha256', 'the-original-hash-that-no-longer-matches');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $projectDir,
            $hasher,
            $lockHash,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'Expected file to be skipped when user has modified it.',
        );
        self::assertSame(
            '',
            $result->newHash,
            'Expected new hash to be empty when file is skipped.',
        );
        self::assertNotNull(
            $result->warning,
            'Expected warning to be set when file is skipped.',
        );
        self::assertStringContainsString(
            'output.txt',
            $result->warning,
            'Expected warning to mention the file name when file is skipped.',
        );
        // file must NOT be overwritten.
        self::assertSame(
            'original scaffold content',
            file_get_contents($projectDir . '/output.txt'),
            'Expected file content to remain unchanged when file is skipped.',
        );
    }

    public function testSyncsSourceExecutableBitOntoDestinationAfterCopy(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $source = "{$this->tempDir}/provider/stubs/source.txt";

        $this->makeSourceFile('#!/bin/sh');

        chmod($source, 0755);

        $oldUmask = umask(0022);

        try {
            (new ReplaceMode())->apply(
                $this->makeMapping(),
                "{$this->tempDir}/project",
                new Hasher(),
                null,
            );

            self::assertSame(
                0755,
                fileperms("{$this->tempDir}/project/output.txt") & 0777,
                "After 'ReplaceMode::apply' copies the stub, it must invoke 'syncPermissions' so the destination "
                . "inherits the source executable bit (0755 here); without 'syncPermissions' the file would keep the "
                . "umask-derived 0644 that 'copy()' produces, breaking scaffolded CLI stubs.",
            );
        } finally {
            umask($oldUmask);
        }
    }

    public function testThrowsWhenCopyFails(): void
    {
        $this->makeSourceFile('content');

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'copy',
            [],
            false,
            default: true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not copy');

        (new ReplaceMode())->apply($this->makeMapping(), "{$this->tempDir}/project", new Hasher(), null);
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile();

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $this->tempDir . '/project',
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'Expected file to be written when destination does not exist.',
        );
        self::assertStringStartsWith(
            'sha256:',
            $result->newHash,
            "Expected new hash to start with 'sha256:'",
        );
        self::assertNull(
            $result->warning,
            'Expected no warning when file is written.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/output.txt",
            'Expected file to exist after being written.',
        );
    }

    public function testWritesFileWhenNoLockHashExists(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'any existing content');

        $this->makeSourceFile('fresh stub');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null, // no lock hash treat as untracked, overwrite unconditionally
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'Expected file to be written when no lock hash exists.',
        );
        self::assertSame(
            'fresh stub',
            file_get_contents($projectDir . '/output.txt'),
            'Expected file content to match the source when no lock hash exists.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    /**
     * Helper to create a FileMapping for testing.
     *
     * @param string $destination Relative destination path within the project, defaulting to 'output.txt'.
     * @param string $source Relative source path within the provider, defaulting to 'stubs/source.txt'.
     *
     * @return FileMapping Created FileMapping instance.
     */
    private function makeMapping(string $destination = 'output.txt', string $source = 'stubs/source.txt'): FileMapping
    {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: FileMode::Replace,
            providerName: 'test/provider',
            providerPath: "{$this->tempDir}/provider",
        );
    }

    /**
     * Helper to create a source file for testing.
     *
     * @param string $content Content to write to the source file, defaulting to 'stub content'.
     * @param string $relative Relative path of the source file within the provider directory, defaulting to 'stubs/source.txt'.
     */
    private function makeSourceFile(string $content = 'stub content', string $relative = 'stubs/source.txt'): void
    {
        $path = "{$this->tempDir}/provider/{$relative}";

        $this->ensureTestDirectory(dirname($path));

        file_put_contents($path, $content);
    }
}
