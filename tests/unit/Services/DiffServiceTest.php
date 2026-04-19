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

    public function testBuildDiffNormalizesCrlfAndLfAsIdentical(): void
    {
        self::assertSame(
            '',
            (new DiffService())->buildDiff("line\r\nline2\r\n", "line\nline2\n"),
            'Mixed CRLF / LF line endings with identical text content must collapse to an empty diff.',
        );
    }

    public function testBuildDiffPreservesUnchangedLinesWithIndent(): void
    {
        $diff = (new DiffService())->buildDiff("a\nc\n", "a\nc\n\n");

        self::assertStringContainsString(
            '  a',
            $diff,
            'Unchanged lines must be prefixed with two spaces.',
        );
        self::assertStringContainsString(
            '  c',
            $diff,
            'Unchanged lines must be prefixed with two spaces.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalContent(): void
    {
        self::assertSame(
            '',
            (new DiffService())->buildDiff("line\n", "line\n"),
            'Identical contents must produce an empty diff.',
        );
    }

    public function testBuildDiffShowsAddedLinesWithPlusPrefix(): void
    {
        $diff = (new DiffService())->buildDiff("a\n", "a\nb\n");

        self::assertStringContainsString(
            '+ b',
            $diff,
            "Added lines must be prefixed with '+ '.",
        );
    }

    public function testBuildDiffShowsRemovedLinesWithMinusPrefix(): void
    {
        $diff = (new DiffService())->buildDiff("a\nb\n", "a\n");

        self::assertStringContainsString(
            '- b',
            $diff,
            "Removed lines must be prefixed with '- '.",
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
            'A provider-path warning must be non-fatal — the diff command must fall back to the default provider '
            . 'root and still return a success exit code.',
        );
        self::assertStringContainsString(
            'resolves outside vendor dir',
            $out->stderrBuffer,
            'A lock-recorded provider path outside the vendor directory must emit a warning.',
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
            "- return ['x' => 1];",
            $out->stdoutBuffer,
            'When the destination is absent, the output must indicate the full stub as removed.',
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
