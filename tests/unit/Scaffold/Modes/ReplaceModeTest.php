<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\ApplyOutcome;
use yii\scaffold\Scaffold\Modes\ReplaceMode;
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
            $this->tempDir . '/project',
            new Hasher(),
            null,
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertFileExists($this->tempDir . '/project/a/b/c/deep.txt');
    }

    public function testOverwritesFileWhenHashMatchesLockHash(): void
    {
        $projectDir = $this->tempDir . '/project';
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

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertSame('updated stub content', file_get_contents($projectDir . '/output.txt'));
    }

    public function testResultHashMatchesActualFileHash(): void
    {
        $this->makeSourceFile('known content');

        $hasher = new Hasher();

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $this->tempDir . '/project',
            $hasher,
            null,
        );

        $actualHash = $hasher->hash($this->tempDir . '/project/output.txt');

        self::assertSame($actualHash, $result->newHash);
    }

    public function testSkipsFileWhenUserHasModifiedIt(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'original scaffold content');

        $this->makeSourceFile('updated stub content');

        $hasher = new Hasher();
        // Lock hash recorded for original; user then modified the file to different content.
        $lockHash = 'sha256:' . hash('sha256', 'the-original-hash-that-no-longer-matches');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $projectDir,
            $hasher,
            $lockHash,
        );

        self::assertSame(ApplyOutcome::Skipped, $result->outcome);
        self::assertSame('', $result->newHash);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('output.txt', $result->warning);
        // File must NOT be overwritten.
        self::assertSame('original scaffold content', file_get_contents($projectDir . '/output.txt'));
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

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertStringStartsWith('sha256:', $result->newHash);
        self::assertNull($result->warning);
        self::assertFileExists($this->tempDir . '/project/output.txt');
    }

    public function testWritesFileWhenNoLockHashExists(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'any existing content');

        $this->makeSourceFile('fresh stub');

        $result = (new ReplaceMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null, // no lock hash — treat as untracked, overwrite unconditionally
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertSame('fresh stub', file_get_contents($projectDir . '/output.txt'));
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function makeMapping(string $destination = 'output.txt', string $source = 'stubs/source.txt'): FileMapping
    {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: 'replace',
            providerName: 'test/provider',
            providerPath: $this->tempDir . '/provider',
        );
    }

    private function makeSourceFile(string $content = 'stub content', string $relative = 'stubs/source.txt'): void
    {
        $path = $this->tempDir . '/provider/' . $relative;
        mkdir(dirname($path), 0777, recursive: true);
        file_put_contents($path, $content);
    }
}
