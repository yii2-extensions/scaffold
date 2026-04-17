<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Modes;

use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\ApplyOutcome;
use yii\scaffold\Scaffold\Modes\PrependMode;
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
            $this->tempDir . '/project',
            new Hasher(),
            null,
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertFileExists($this->tempDir . '/project/a/b/c/deep.txt');
    }

    public function testOutcomeIsAlwaysWritten(): void
    {
        $this->makeSourceFile();

        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            $this->tempDir . '/project',
            new Hasher(),
            'sha256:ignored-hash',
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
    }

    public function testPrependContentComesBeforeExistingContent(): void
    {
        $projectDir = $this->tempDir . '/project';
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

        self::assertNotFalse($content);
        self::assertStringStartsWith('PREPENDED', $content);
        self::assertStringEndsWith('EXISTING', $content);
    }

    public function testPrependsToExistingFile(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('prepended ');

        (new PrependMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame('prepended existing', file_get_contents($projectDir . '/output.txt'));
    }

    public function testResultHashMatchesActualFileHash(): void
    {
        $this->makeSourceFile('stub');

        $hasher = new Hasher();

        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            $this->tempDir . '/project',
            $hasher,
            null,
        );

        self::assertSame($hasher->hash($this->tempDir . '/project/output.txt'), $result->newHash);
    }

    public function testWritesFileWhenDestinationDoesNotExist(): void
    {
        $this->makeSourceFile('hello');

        $result = (new PrependMode())->apply(
            $this->makeMapping(),
            $this->tempDir . '/project',
            new Hasher(),
            null,
        );

        self::assertSame(ApplyOutcome::Written, $result->outcome);
        self::assertSame('hello', file_get_contents($this->tempDir . '/project/output.txt'));
        self::assertNull($result->warning);
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
            mode: 'prepend',
            providerName: 'test/provider',
            providerPath: $this->tempDir . '/provider',
        );
    }

    private function makeSourceFile(string $content = 'prepended content', string $relative = 'stubs/source.txt'): void
    {
        $path = $this->tempDir . '/provider/' . $relative;
        mkdir(dirname($path), 0777, recursive: true);
        file_put_contents($path, $content);
    }
}
