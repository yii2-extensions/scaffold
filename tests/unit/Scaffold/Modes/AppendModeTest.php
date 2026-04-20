<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\{FileMapping, FileMode};
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{AppendMode, ApplyOutcome};
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see AppendMode} scaffold file application strategy.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class AppendModeTest extends TestCase
{
    use TempDirectoryTrait;

    public function testAppendsToExistingFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile(' appended');

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            'existing appended',
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must append to the existing file content rather than overwriting it.',
        );
    }

    public function testCreatesIntermediateDirectories(): void
    {
        $this->makeSourceFile(relative: 'stubs/nested/deep.txt');

        $result = (new AppendMode())->apply(
            $this->makeMapping('a/b/c/deep.txt', 'stubs/nested/deep.txt'),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'AppendMode must create intermediate directories when they do not exist.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/a/b/c/deep.txt",
            'AppendMode must create the file in the expected location.',
        );
    }

    public function testOutcomeIsAlwaysWritten(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('extra');

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            'sha256:some-recorded-hash',
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            "AppendMode must always return 'ApplyOutcome::Written' regardless of the previous file state.",
        );
    }

    public function testResultHashMatchesActualFileHash(): void
    {
        $this->makeSourceFile('content');

        $hasher = new Hasher();
        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            $hasher,
            null,
        );

        self::assertSame(
            $hasher->hash("{$this->tempDir}/project/output.txt"),
            $result->newHash,
            'AppendMode must return a new hash that matches the actual content of the written file.',
        );
    }

    public function testThrowsWhenSourceReadFails(): void
    {
        $this->makeSourceFile('x');

        // 'default: true' fails every 'file_get_contents' in the Modes namespace, independent of how paths are assembled.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_get_contents',
            [],
            false,
            default: true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read source file');

        (new AppendMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );
    }

    public function testThrowsWhenWriteFails(): void
    {
        $this->makeSourceFile('data');

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_put_contents',
            [],
            false,
            default: true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write to');

        (new AppendMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );
    }

    public function testUsesFileAppendFlagWhenDestinationExists(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('extra');

        $capturedFlag = null;

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_put_contents',
            [
                $projectDir . DIRECTORY_SEPARATOR . 'output.txt',
                'extra',
                FILE_APPEND,
                null,
            ],
            static function (string $file, string $data, int $flag) use (&$capturedFlag): int {
                $capturedFlag = $flag;

                return (int) \file_put_contents($file, $data, $flag);
            },
        );

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            FILE_APPEND,
            $capturedFlag,
            'AppendMode must pass FILE_APPEND when the destination already exists.',
        );
    }

    public function testUsesZeroFlagWhenDestinationDoesNotExist(): void
    {
        $projectDir = "{$this->tempDir}/project";

        $this->makeSourceFile('hello');

        $capturedFlag = null;

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_put_contents',
            [
                $projectDir . DIRECTORY_SEPARATOR . 'output.txt',
                'hello',
                0,
                null,
            ],
            static function (string $file, string $data, int $flag) use (&$capturedFlag): int {
                $capturedFlag = $flag;

                return (int) \file_put_contents($file, $data, $flag);
            },
        );

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            0,
            $capturedFlag,
            "AppendMode must pass exactly '0' (no flags) when the destination does not exist yet.",
        );
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile('hello');

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            "AppendMode must write the file when the destination does not exist, resulting in 'ApplyOutcome::Written'.",
        );
        self::assertSame(
            'hello',
            file_get_contents("{$this->tempDir}/project/output.txt"),
            'AppendMode must write the correct content to the new file when it does not exist.',
        );
        self::assertNull(
            $result->warning,
            'AppendMode must not produce any warnings when writing a new file.',
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
     * Helper method to create a FileMapping with default values for testing.
     *
     * @param string $destination Destination path for the file mapping, defaulting to 'output.txt'.
     * @param string $source Source path for the file mapping, defaulting to 'stubs/source.txt'.
     *
     * @return FileMapping A FileMapping instance with the specified or default values.
     */
    private function makeMapping(string $destination = 'output.txt', string $source = 'stubs/source.txt'): FileMapping
    {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: FileMode::Append,
            providerName: 'test/provider',
            providerPath: "{$this->tempDir}/provider",
        );
    }

    /**
     * Creates a source file with the specified content and relative path within the provider directory.
     *
     * @param string $content Content to write to the source file.
     * @param string $relative Relative path to the source file within the provider directory, defaulting to
     * 'stubs/source.txt'.
     */
    private function makeSourceFile(string $content = 'appended content', string $relative = 'stubs/source.txt'): void
    {
        $path = $this->tempDir . '/provider/' . $relative;

        $this->ensureTestDirectory(dirname($path));

        file_put_contents($path, $content);
    }
}
