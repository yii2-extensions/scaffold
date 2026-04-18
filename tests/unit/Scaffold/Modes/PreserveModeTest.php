<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{ApplyOutcome, PreserveMode};
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
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'Expected file to be written when intermediate directories are created.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/a/b/c/deep.txt",
            'Expected file to exist after creating intermediate directories.',
        );
    }

    public function testLockHashIsIgnored(): void
    {
        // preserveMode never overwrites the lock hash is irrelevant.
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user content');

        $this->makeSourceFile('different stub');

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            'sha256:some-recorded-hash',
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'PreserveMode must skip the file when it already exists.',
        );
        self::assertSame(
            'user content',
            file_get_contents($projectDir . '/output.txt'),
            'Existing file content must not be modified.',
        );
    }

    public function testSkippedResultHashIsHashOfExistingFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

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

        self::assertSame(
            $expected,
            $result->newHash,
            'PreserveMode must return the correct hash for the existing file.',
        );
    }

    public function testSkipsFileWhenDestinationAlreadyExists(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user content');

        $this->makeSourceFile('stub content');

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'PreserveMode must skip the file when it already exists.',
        );
        // existing file must not be modified.
        self::assertSame(
            'user content',
            file_get_contents($projectDir . '/output.txt'),
            'Existing file content must not be modified.',
        );
        self::assertNull(
            $result->warning,
            'PreserveMode must not produce a warning when skipping a file.',
        );
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

        (new PreserveMode())->apply($this->makeMapping(), "{$this->tempDir}/project", new Hasher(), null);
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile();

        $result = (new PreserveMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'PreserveMode must write the file when it does not already exist.',
        );
        self::assertStringStartsWith(
            'sha256:',
            $result->newHash,
            "PreserveMode must return a hash starting with 'sha256'.",
        );
        self::assertNull(
            $result->warning,
            'PreserveMode must not produce a warning when writing a file.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/output.txt",
            'PreserveMode must create the output file.',
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

    private function makeMapping(string $destination = 'output.txt', string $source = 'stubs/source.txt'): FileMapping
    {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: 'preserve',
            providerName: 'test/provider',
            providerPath: "{$this->tempDir}/provider",
        );
    }

    private function makeSourceFile(string $content = 'stub content', string $relative = 'stubs/source.txt'): void
    {
        $path = "{$this->tempDir}/provider/{$relative}";

        mkdir(dirname($path), 0777, recursive: true);
        file_put_contents($path, $content);
    }
}
