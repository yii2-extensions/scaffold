<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Services\DiffService;
use yii\scaffold\tests\support\{BufferedOutputWriter, TempDirectoryTrait};

/**
 * Unit tests for {@see DiffService} covering diff computation and error handling for unsafe / missing inputs.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('services')]
final class DiffServiceTest extends TestCase
{
    use TempDirectoryTrait;

    public function testBuildDiffDoesNotStartOrEndWithNewline(): void
    {
        $diff = (new DiffService())->buildDiff("a\n", "a\nb\n", 'config/params.php');

        self::assertStringStartsNotWith(
            "\n",
            $diff,
            'Diff must start with the first header line, not a newline.',
        );
        self::assertStringEndsNotWith(
            "\n",
            $diff,
            "The builder's trailing newline must be stripped; 'writeStdout' appends the final one.",
        );
    }

    public function testBuildDiffEmitsGitStyleFileHeaders(): void
    {
        $diff = (new DiffService())->buildDiff("a\n", "a\nb\n", 'config/params.php');

        self::assertStringStartsWith(
            "--- a/config/params.php\n+++ b/config/params.php\n",
            $diff,
            "Headers must label the stub as 'a/' and the current file as 'b/'.",
        );
    }

    public function testBuildDiffEmitsHunkHeaderWithLineRanges(): void
    {
        $diff = (new DiffService())->buildDiff(
            "one\ntwo\nthree\nfour\nfive\n",
            "one\ntwo\nchanged\nfour\nfive\n",
            'config/params.php',
        );

        self::assertStringContainsString(
            '@@ -1,5 +1,5 @@',
            $diff,
            'Hunk header must carry the from/to line ranges.',
        );
    }

    public function testBuildDiffLimitsContextToThreeLines(): void
    {
        $diff = (new DiffService())->buildDiff(
            "one\ntwo\nthree\nfour\nfive\nsix\nseven\neight\nnine\nten\n",
            "one\ntwo\nthree\nfour\nfive\nchanged\nseven\neight\nnine\nten\n",
            'config/params.php',
        );

        self::assertStringContainsString(
            ' three',
            $diff,
            'The third line before the change belongs to the context window.',
        );
        self::assertStringNotContainsString(
            ' two',
            $diff,
            'Lines beyond three of context must be omitted.',
        );
        self::assertStringNotContainsString(
            ' ten',
            $diff,
            'Trailing lines beyond three of context must be omitted.',
        );
    }

    public function testBuildDiffMarksMissingTrailingNewlineWithBackslashMarker(): void
    {
        $diff = (new DiffService())->buildDiff("a\n", "a\nb", 'config/params.php');

        self::assertStringContainsString(
            '\\ No newline at end of file',
            $diff,
            'A file without a final newline must carry the backslash marker.',
        );
    }

    public function testBuildDiffNormalizesCrlfAndLfAsIdentical(): void
    {
        self::assertSame(
            '',
            (new DiffService())->buildDiff("line\r\nline2\r\n", "line\nline2\n", 'config/params.php'),
            'Mixed CRLF / LF line endings with identical text content must collapse to an empty diff.',
        );
    }

    public function testBuildDiffPrefixesContextLinesWithSingleSpace(): void
    {
        $diff = (new DiffService())->buildDiff("shared\nold\n", "shared\nnew\n", 'config/params.php');

        self::assertStringContainsString(
            "\n shared\n",
            $diff,
            'Context lines must be prefixed with a single space.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalContent(): void
    {
        self::assertSame(
            '',
            (new DiffService())->buildDiff("line\n", "line\n", 'config/params.php'),
            'Identical contents must produce an empty diff.',
        );
    }

    public function testBuildDiffShowsAddedLinesWithPlusPrefix(): void
    {
        $diff = (new DiffService())->buildDiff("a\n", "a\nadded\n", 'config/params.php');

        self::assertStringContainsString(
            "\n+added",
            $diff,
            "Added lines must be prefixed with a single '+'.",
        );
    }

    public function testBuildDiffShowsRemovedLinesWithMinusPrefix(): void
    {
        $diff = (new DiffService())->buildDiff("a\nremoved\n", "a\n", 'config/params.php');

        self::assertStringContainsString(
            "\n-removed",
            $diff,
            "Removed lines must be prefixed with a single '-'.",
        );
    }

    public function testBuildDiffSplitsDistantChangesIntoSeparateHunks(): void
    {
        $stub = "one\ntwo\nthree\nfour\nfive\nsix\nseven\neight\nnine\nten\neleven\ntwelve\nthirteen\nfourteen\nfifteen\n";
        $current = "one\nchanged\nthree\nfour\nfive\nsix\nseven\neight\nnine\nten\neleven\ntwelve\nthirteen\naltered\nfifteen\n";

        $diff = (new DiffService())->buildDiff($stub, $current, 'config/params.php');

        self::assertSame(
            2,
            substr_count($diff, '@@ -'),
            'Changes separated by more than the common-line threshold must produce two hunks.',
        );
    }

    public function testRunAnnouncesNoDifferencesWhenFilesAreIdentical(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            0,
            $exitCode,
            "When files are identical, the exit code must be '0'.",
        );
        self::assertStringContainsString(
            'No differences found',
            $out->stdoutBuffer,
            'When files are identical, the output must indicate no differences.',
        );
    }

    public function testRunColorizesDiffWhenDecorated(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return ['x' => 1];\n",
            currentContent: "return ['x' => 2];\n",
        );

        $out = new BufferedOutputWriter();
        (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
            decorated: true,
        );

        self::assertStringContainsString(
            "\e[31m",
            $out->stdoutBuffer,
            'Removed lines must carry the red ANSI escape code.',
        );
        self::assertStringContainsString(
            "\e[32m",
            $out->stdoutBuffer,
            'Added lines must carry the green ANSI escape code.',
        );
    }

    public function testRunEmitsWarningWhenLockProviderPathEscapesVendor(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        $lock = new LockFile($this->tempDir);

        $data = $lock->read();

        $data['providers'] = [
            'pkg/name' => [
                'version' => '0.1.x-dev',
                'path' => '../outside-vendor',
            ],
        ];

        $lock->write($data);

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            0,
            $exitCode,
            'A provider-path warning must be non-fatal: the command falls back to the default root and still succeeds.',
        );
        self::assertStringContainsString(
            'resolves outside vendor dir',
            $out->stderrBuffer,
            'A lock-recorded provider path outside the vendor directory must emit a warning.',
        );
    }

    public function testRunEndsCurrentFileReadErrorWithSinglePhpEolSuffixOnStderr(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        MockerState::addCondition(
            'yii\\scaffold\\Services',
            'file_get_contents',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'config/params.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Could not read current file' error must terminate with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Could not read current file' error must not begin with PHP_EOL.",
        );
    }

    public function testRunEndsNoDifferencesMessageWithSinglePhpEolSuffixOnStdout(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'config/params.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No differences found' message must end with PHP_EOL so terminals render it on its own line.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No differences found' message must not be prefixed with PHP_EOL; the newline belongs after it.",
        );
    }

    public function testRunEndsNotTrackedErrorWithSinglePhpEolSuffixOnStderr(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'missing.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'not tracked' error must terminate with PHP_EOL so subsequent stderr writes start on a fresh line.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'not tracked' error must not begin with PHP_EOL.",
        );
    }

    public function testRunEndsProviderEscapesVendorWarningWithSinglePhpEolSuffixOnStderr(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        $lock = new LockFile($this->tempDir);

        $data = $lock->read();

        $data['providers'] = [
            'pkg/name' => [
                'version' => '0.1.x-dev',
                'path' => '../outside-vendor',
            ],
        ];

        $lock->write($data);

        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'config/params.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            'The provider-escapes-vendor warning must terminate with PHP_EOL.',
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            'The provider-escapes-vendor warning must not begin with PHP_EOL.',
        );
    }

    public function testRunEndsStubNotFoundErrorWithSinglePhpEolSuffixOnStderr(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        unlink($this->tempDir . '/vendor/pkg/name/stubs/config/params.php');

        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'config/params.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Stub not found' error must terminate with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Stub not found' error must not begin with PHP_EOL.",
        );
    }

    public function testRunEndsUnsafeLockEntryErrorWithSinglePhpEolSuffixOnStderr(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", '../escape.php', $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Unsafe lock entry' error must terminate with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Unsafe lock entry' error must not begin with PHP_EOL.",
        );
    }

    public function testRunReturnsErrorWhenCurrentFileReadFails(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        MockerState::addCondition(
            'yii\\scaffold\\Services',
            'file_get_contents',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            1,
            $exitCode,
            "When the current file cannot be read, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'Could not read current file',
            $out->stderrBuffer,
            'When the current file cannot be read, the output must indicate an error.',
        );
    }

    public function testRunReturnsErrorWhenFileNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run($this->tempDir, "{$this->tempDir}/vendor", 'missing.php', $out);

        self::assertSame(
            1,
            $exitCode,
            "When the file is not tracked, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'not tracked',
            $out->stderrBuffer,
            'When the file is not tracked, the output must indicate an error.',
        );
    }

    public function testRunReturnsErrorWhenLockEntryHasUnsafeDestination(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            '../escape.php',
            $out,
        );

        self::assertSame(
            1,
            $exitCode,
            "When the lock entry is unsafe, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'Unsafe lock entry',
            $out->stderrBuffer,
            'When the lock entry is unsafe, the output must indicate an error.',
        );
    }

    public function testRunReturnsErrorWhenStubMissing(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        unlink($this->tempDir . '/vendor/pkg/name/stubs/config/params.php');

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            1,
            $exitCode,
            "When the stub is missing, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'Stub not found',
            $out->stderrBuffer,
            'When the stub is missing, the output must indicate an error.',
        );
    }

    public function testRunReturnsUnsafeDestinationErrorEvenWhenProviderRootAndSourceAreSafe(): void
    {
        // Valid provider tree so 'validateSource' passes unconditionally; isolates the 'validateDestination' guard.
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";

        $this->ensureTestDirectory("{$providerRoot}/stubs");

        file_put_contents("{$providerRoot}/stubs/safe.php", "return [];\n");

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/safe.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            '../escape.php',
            $out,
        );

        self::assertSame(
            1,
            $exitCode,
            "When 'validateDestination' flags an unsafe destination, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'Unsafe lock entry',
            $out->stderrBuffer,
            "'validateDestination' must catch the traversal destination and emit the 'Unsafe lock entry' diagnostic.",
        );
    }

    public function testRunReturnsUnsafeSourceErrorEvenWhenDestinationIsSafe(): void
    {
        // Safe destination + unsafe source so 'validateDestination' passes; isolates the 'validateSource' guard.
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";

        $this->ensureTestDirectory($providerRoot);
        $this->ensureTestDirectory("{$this->tempDir}/config");

        file_put_contents("{$this->tempDir}/config/params.php", "return [];\n");

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => '../escape.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            1,
            $exitCode,
            "When 'validateSource' flags an unsafe source, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'Unsafe lock entry',
            $out->stderrBuffer,
            "'validateSource' must catch the traversal source and emit the 'Unsafe lock entry' diagnostic.",
        );
    }

    public function testRunShowsFullStubAsRemovedWhenDestinationIsAbsent(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return ['x' => 1];\n",
            currentContent: "return ['x' => 1];\n",
        );

        unlink($this->tempDir . '/config/params.php');

        $out = new BufferedOutputWriter();
        $exitCode = (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertSame(
            0,
            $exitCode,
            "When the destination is absent, the exit code must be '0'.",
        );
        self::assertStringContainsString(
            "-return ['x' => 1];",
            $out->stdoutBuffer,
            'When the destination is absent, the output must indicate the full stub as removed.',
        );
        self::assertStringContainsString(
            '@@ -1 +1,0 @@',
            $out->stdoutBuffer,
            'An absent destination must render as an empty-target hunk.',
        );
    }

    public function testRunWritesPlainDiffWhenNotDecorated(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return ['x' => 1];\n",
            currentContent: "return ['x' => 2];\n",
        );

        $out = new BufferedOutputWriter();
        (new DiffService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            'config/params.php',
            $out,
        );

        self::assertStringNotContainsString(
            "\e[",
            $out->stdoutBuffer,
            'Undecorated output must contain no ANSI escape codes.',
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
     * Helper method to seed a provider and file for testing.
     *
     * @param string $destination Relative destination path within the project.
     * @param string $sourceContent Content to write to the source file.
     * @param string $currentContent Content to write to the current file.
     */
    private function seedProviderAndFile(string $destination, string $sourceContent, string $currentContent): void
    {
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";

        $stubRelative = "stubs/{$destination}";
        $stubPath = "{$providerRoot}/{$stubRelative}";

        $this->ensureTestDirectory(dirname($stubPath));

        file_put_contents($stubPath, $sourceContent);

        $destAbsolute = "{$this->tempDir}/{$destination}";

        $this->ensureTestDirectory(dirname($destAbsolute));

        file_put_contents($destAbsolute, $currentContent);

        $hash = (new Hasher())->hash($destAbsolute);
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    $destination => [
                        'hash' => $hash,
                        'provider' => 'pkg/name',
                        'source' => $stubRelative,
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }
}
