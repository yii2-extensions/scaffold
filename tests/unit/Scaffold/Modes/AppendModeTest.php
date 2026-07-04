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

    public function testAppendsOnlyMissingLines(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\nbeta\n");

        $this->makeSourceFile("alpha\nbeta\ngamma\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\nbeta\ngamma\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must append only the lines not already present in the destination.',
        );
    }

    public function testAppendsRepeatedProviderLinesWhenDestinationHasFewer(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Destination has one 'alpha'; stub has two. The second occurrence must be appended (multiplicity-preserving).
        file_put_contents($projectDir . '/output.txt', "alpha\n");

        $this->makeSourceFile("alpha\nalpha\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\nalpha\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must preserve provider line multiplicity instead of treating destination lines as a set.',
        );
    }

    public function testAppendsTrailingNewlineWhenProviderLinesLandAtEnd(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Destination lacks a trailing newline; provider lines merged at the end must terminate the file with one.
        file_put_contents($projectDir . '/output.txt', 'alpha');

        $this->makeSourceFile("alpha\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\nbeta\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must newline-terminate the file when provider lines land at the end.',
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

    public function testEmitsAppendedLinesUsingCREolWhenDestinationUsesCR(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Legacy Mac-style CR-only destination.
        file_put_contents($projectDir . '/output.txt', "alpha\r");

        $this->makeSourceFile("alpha\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\rbeta\r",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must emit appended lines using CR when the destination uses CR-only EOL.',
        );
    }

    public function testEmitsAppendedLinesUsingCRLFEolWhenDestinationUsesCRLF(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Windows-style CRLF destination.
        file_put_contents($projectDir . '/output.txt', "alpha\r\n");

        $this->makeSourceFile("alpha\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\r\nbeta\r\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must emit appended lines using CRLF when the destination uses CRLF EOL.',
        );
    }

    public function testHandlesEmptyStub(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\n");

        // Empty stub yields zero provider lines; the destination must remain untouched.
        $this->makeSourceFile('');

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            "AppendMode must return 'ApplyOutcome::Skipped' when the stub is empty.",
        );
        self::assertSame(
            "alpha\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must leave the destination untouched when the stub is empty.',
        );
    }

    public function testInsertsConsecutiveMissingProviderLinesBeforeSharedAnchor(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "head\ntail\n");

        // Two consecutive missing provider lines must both be flushed before the shared anchor, in stub order.
        $this->makeSourceFile("head\nmid1\nmid2\ntail\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "head\nmid1\nmid2\ntail\n",
            file_get_contents($projectDir . '/output.txt'),
            'Every buffered provider line must be flushed, in order.',
        );
    }

    public function testInsertsMissingProviderLineAtContextualPosition(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\nbeta\n");

        // Provider declares an explicit empty line between 'alpha' and 'beta'. The LCS alignment must insert it
        // between the shared anchors instead of dumping it at the end of the file.
        $this->makeSourceFile("alpha\n\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\n\nbeta\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must insert the missing empty line between its shared anchors.',
        );
    }

    public function testInsertsMissingProviderLinesBeforeSharedAnchor(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Consumer replaced the middle section; the missing provider line must land after the consumer block and
        // before the next shared anchor, never at the end of the file.
        file_put_contents($projectDir . '/output.txt', "head\ncustom\ntail\n");

        $this->makeSourceFile("head\nmiddle\ntail\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "head\ncustom\nmiddle\ntail\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must keep consumer lines first and flush provider lines before the shared anchor.',
        );
    }

    public function testInsertsSeparatorWhenDestinationDoesNotEndWithNewline(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('appended');

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "existing\nappended\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must insert a newline separator when the destination does not end with one.',
        );
    }

    public function testIsIdempotentAfterContextualInsertion(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "head\ncustom\ntail\n");

        $this->makeSourceFile("head\nmiddle\ntail\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            "Second apply must return 'ApplyOutcome::Skipped'.",
        );
        self::assertSame(
            "head\ncustom\nmiddle\ntail\n",
            file_get_contents($projectDir . '/output.txt'),
            'Content must stay stable across repeated applies.',
        );
    }

    public function testIsIdempotentWhenConsumerHasNoTrailingNewlineAndStubDoes(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Destination has no trailing newline; stub ends with one. The trailing-EOL stripping must treat both as
        // equivalent so no phantom empty line gets appended.
        file_put_contents($projectDir . '/output.txt', 'alpha');

        $this->makeSourceFile("alpha\n");

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            "AppendMode must treat 'alpha' and 'alpha\\n' as equivalent for diffing purposes.",
        );
        self::assertSame(
            'alpha',
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must not modify a destination that differs from the stub only by the final EOL.',
        );
    }

    public function testIsIdempotentWhenStubEndsWithBlankLine(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Stub ends with an intentional trailing blank line.
        $this->makeSourceFile("alpha\n\n");

        // Two passes: the first must write the trailing blank; the second must detect it as already present.
        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        $afterFirst = file_get_contents($projectDir . '/output.txt');

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'AppendMode must remain idempotent when the stub ends with a blank line.',
        );
        self::assertSame(
            $afterFirst,
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must not re-append the trailing blank line on subsequent runs.',
        );
    }

    public function testNormalizesLineEndingsBeforeDiffing(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Destination uses CRLF (typical for files checked out on Windows or repos with mixed EOL).
        file_put_contents($projectDir . '/output.txt', "alpha\r\nbeta\r\n");

        // Provider ships LF.
        $this->makeSourceFile("alpha\nbeta\n");

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'AppendMode must normalise line endings so CRLF destinations match LF stubs.',
        );
        self::assertSame(
            "alpha\r\nbeta\r\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must leave the destination untouched when EOL-normalised content matches.',
        );
    }

    public function testPreservesConsumerAdditionsOnRepeatedApplies(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\nproject-specific-line\n");

        $this->makeSourceFile("alpha\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        // Apply again must remain idempotent.
        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\nproject-specific-line\nbeta\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must remain idempotent and never duplicate provider lines or remove consumer additions.',
        );
    }

    public function testPreservesCrlfEolForContextualInsertion(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Windows-style CRLF destination; the mid-file insertion must be emitted in the same EOL style.
        file_put_contents($projectDir . '/output.txt', "alpha\r\nbeta\r\n");

        $this->makeSourceFile("alpha\ngamma\nbeta\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "alpha\r\ngamma\r\nbeta\r\n",
            file_get_contents($projectDir . '/output.txt'),
            'Mid-file insertion must use the destination CRLF EOL style.',
        );
    }

    public function testPreservesMissingTrailingNewlineWhenInsertionIsNotAtEnd(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);

        // Destination intentionally lacks a trailing newline and the insertion lands mid-file, so the consumer's
        // trailing-newline choice must survive the rewrite.
        file_put_contents($projectDir . '/output.txt', "alpha\nomega");

        $this->makeSourceFile("gamma\nalpha\n");

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            "gamma\nalpha\nomega",
            file_get_contents($projectDir . '/output.txt'),
            'Consumer trailing-newline choice must survive a mid-file insertion.',
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

    public function testReturnsSkippedWhenAllProviderLinesAlreadyPresent(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\nbeta\ngamma\n");

        $this->makeSourceFile("alpha\nbeta\n");

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            "AppendMode must return 'ApplyOutcome::Skipped' when every provider line is already present.",
        );
        self::assertSame(
            "alpha\nbeta\ngamma\n",
            file_get_contents($projectDir . '/output.txt'),
            'AppendMode must leave the destination untouched when every provider line is already present.',
        );
    }

    public function testReturnsWrittenWhenAtLeastOneLineIsAppended(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\n");

        $this->makeSourceFile("alpha\nbeta\n");

        $result = (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            'sha256:some-recorded-hash',
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            "AppendMode must return 'ApplyOutcome::Written' when at least one missing line is appended.",
        );
    }

    public function testRewritesDestinationWithMergedContentWhenLinesAreMissing(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "alpha\n");

        $this->makeSourceFile("alpha\nbeta\n");

        $capturedFlag = null;

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_put_contents',
            [
                $projectDir . DIRECTORY_SEPARATOR . 'output.txt',
                "alpha\nbeta\n",
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
            "Full merged content must be written with no flags, never 'FILE_APPEND'.",
        );
    }

    public function testThrowsWhenAppendWriteFails(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', "existing\n");

        // Source has a line not present in the destination, so the merge-write path is reached.
        $this->makeSourceFile("missing\n");

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
            $projectDir,
            new Hasher(),
            null,
        );
    }

    public function testThrowsWhenDestinationReadFails(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0o777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('extra');

        // Mock the destination read by exact path to fail; the source read (different path) is unaffected.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Modes',
            'file_get_contents',
            [$projectDir . DIRECTORY_SEPARATOR . 'output.txt', false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read destination file');

        (new AppendMode())->apply(
            $this->makeMapping(),
            $projectDir,
            new Hasher(),
            null,
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
