<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\ApplyOutcome;
use yii\scaffold\Scaffold\Modes\PreserveMode;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see PreserveMode} scaffold file application strategy.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class PreserveModeTest extends TestCase
{
    use TempDirectoryTrait;

    public function testCreatesIntermediateDirectories(): void
    {
        $this->makeSourceFile(relative: 'stubs/nested/deep.txt');

        $result = (new PreserveMode())->apply(
            $this->makeMapping('a/b/c/deep.txt', 'stubs/nested/deep.txt'),
            $this->tempDir . '/project',
            new Hasher(),
            null,
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertFileExists($this->tempDir . '/project/a/b/c/deep.txt');
    }

    public function testLockHashIsIgnored(): void
    {
        // PreserveMode never overwrites — the lock hash is irrelevant.
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user content');

        $this->makeSourceFile('different stub');

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            'sha256:some-recorded-hash',
        );

        self::assertSame(ApplyOutcome::Skipped, $result->outcome);
        self::assertSame('user content', file_get_contents($projectDir . '/output.txt'));
    }

    public function testSkippedResultHashIsHashOfExistingFile(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user content');

        $this->makeSourceFile();

        $hasher = new Hasher();
        $expected = $hasher->hash($projectDir . '/output.txt');

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            $projectDir,
            $hasher,
            null,
        );

        self::assertSame($expected, $result->newHash);
    }

    public function testSkipsFileWhenDestinationAlreadyExists(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user content');

        $this->makeSourceFile('stub content');

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(ApplyOutcome::Skipped, $result->outcome);
        // Existing file must not be modified.
        self::assertSame('user content', file_get_contents($projectDir . '/output.txt'));
        self::assertNull($result->warning);
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile();

        $result = (new PreserveMode())->apply(
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
            mode: 'preserve',
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
