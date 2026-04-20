<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\{FileMapping, FileMode};
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{ApplyOutcome, PrependMode};
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see PrependMode} scaffold file application strategy.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class PrependModeTest extends TestCase
{
    use TempDirectoryTrait;

    public function testCreatesIntermediateDirectories(): void
    {
        $this->makeSourceFile(relative: 'stubs/nested/deep.txt');

        $result = (new PrependMode())->apply(
            $this->makeMapping('a/b/c/deep.txt', 'stubs/nested/deep.txt'),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'PrependMode must create intermediate directories when they do not exist.',
        );
        self::assertFileExists(
            "{$this->tempDir}/project/a/b/c/deep.txt",
            'PrependMode must create the file in the expected location.',
        );
    }

    public function testOutcomeIsAlwaysWritten(): void
    {
        $this->makeSourceFile();

        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            'sha256:ignored-hash',
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            "PrependMode must always return 'ApplyOutcome::Written' regardless of the previous file state.",
        );
    }

    public function testPrependContentComesBeforeExistingContent(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'EXISTING');

        $this->makeSourceFile('PREPENDED');

        (new PrependMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        $content = file_get_contents($projectDir . '/output.txt');

        self::assertNotFalse(
            $content,
            'Failed to read the content of the destination file after applying PrependMode.',
        );
        self::assertStringStartsWith(
            'PREPENDED',
            $content,
            'PrependMode must prepend the content correctly.',
        );
        self::assertStringEndsWith(
            'EXISTING',
            $content,
            'PrependMode must preserve the existing content.',
        );
    }

    public function testPrependsToExistingFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('prepended ');

        (new PrependMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            'prepended existing',
            file_get_contents($projectDir . '/output.txt'),
            'PrependMode must prepend the content correctly.',
        );
    }

    public function testResultHashMatchesActualFileHash(): void
    {
        $this->makeSourceFile('stub');

        $hasher = new Hasher();
        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            $hasher,
            null,
        );

        self::assertSame(
            $hasher->hash("{$this->tempDir}/project/output.txt"),
            $result->newHash,
            'PrependMode must return the correct hash for the new file content.',
        );
    }

    public function testThrowsWhenDestinationReadFails(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents("{$projectDir}/output.txt", 'existing');

        $sourcePath = "{$this->tempDir}/provider/stubs/source.txt";

        $this->ensureTestDirectory(dirname($sourcePath));

        file_put_contents($sourcePath, 'source');

        // 'PrependMode' reads source AND destination; strict arg-matching is required so only the destination branch fails.
        $destBasename = 'output.txt';

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_get_contents',
            [
                /**
                 * match the destination argument by absolute path. `PrependMode` uses `PathResolver::destination`,
                 * which concatenates `rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $mapping->destination` we
                 * reproduce that exactly here.
                 */
                rtrim($projectDir, '/\\') . DIRECTORY_SEPARATOR . $destBasename,
                false,
                null,
                0,
                null,
            ],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read destination file');

        (new PrependMode())->apply($this->makeMapping(), $projectDir, new Hasher(), null);
    }

    public function testThrowsWhenSourceReadFails(): void
    {
        $this->makeSourceFile('x');

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_get_contents',
            [],
            false,
            default: true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read source file');

        (new PrependMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );
    }

    public function testThrowsWhenWriteFails(): void
    {
        $this->makeSourceFile('source');

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_put_contents',
            [],
            false,
            default: true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write to');

        (new PrependMode())->apply($this->makeMapping(), "{$this->tempDir}/project", new Hasher(), null);
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile('hello');

        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            "{$this->tempDir}/project",
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'PrependMode must return the correct outcome when writing a new file.',
        );
        self::assertSame(
            'hello',
            file_get_contents("{$this->tempDir}/project/output.txt"),
            'PrependMode must write the correct content when the destination file does not exist.',
        );
        self::assertNull(
            $result->warning,
            'PrependMode must not return a warning when writing a new file.',
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
     * Helper to create a file mapping for testing.
     *
     * @param string $destination Destination path relative to the project root.
     * @param string $source Source path relative to the provider path.
     *
     * @return FileMapping Created file mapping instance.
     */
    private function makeMapping(string $destination = 'output.txt', string $source = 'stubs/source.txt'): FileMapping
    {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: FileMode::Prepend,
            providerName: 'test/provider',
            providerPath: "{$this->tempDir}/provider",
        );
    }

    /**
     * Helper to create a source file with specified content for testing.
     *
     * @param string $content Content to write to the source file.
     * @param string $relative Relative path from the provider path where the source file should be created.
     */
    private function makeSourceFile(string $content = 'prepended content', string $relative = 'stubs/source.txt'): void
    {
        $path = "{$this->tempDir}/provider/{$relative}";

        $this->ensureTestDirectory(dirname($path));

        file_put_contents($path, $content);
    }
}
