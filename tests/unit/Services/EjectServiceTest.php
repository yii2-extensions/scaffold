<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Services\EjectService;
use yii\scaffold\tests\support\{BufferedOutputWriter, TempDirectoryTrait};

/**
 * Unit tests for {@see EjectService} covering dry-run, confirmed removal, and untracked-file error paths.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('services')]
final class EjectServiceTest extends TestCase
{
    use TempDirectoryTrait;

    public function testConfirmedEjectSuccessMessageEndsWithSinglePhpEolSuffix(): void
    {
        $this->seedTracked('config/params.php');

        file_put_contents("{$this->tempDir}/config/params.php", "return [];\n");

        $out = new BufferedOutputWriter();
        (new EjectService())->run($this->tempDir, 'config/params.php', confirmed: true, out: $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'Removed' confirmation must end with PHP_EOL so shells render it on its own line.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'Removed' confirmation must not be prefixed with PHP_EOL.",
        );
    }

    public function testDryRunLeavesLockUntouchedWithoutConfirmation(): void
    {
        $this->seedTracked('config/params.php');

        $out = new BufferedOutputWriter();
        $exitCode = (new EjectService())->run($this->tempDir, 'config/params.php', confirmed: false, out: $out);

        self::assertSame(
            0,
            $exitCode,
            "Dry-run should exit with code '0' to indicate no error.",
        );
        self::assertStringContainsString(
            'Would remove',
            $out->stdoutBuffer,
            'Dry-run must indicate which files would be removed.',
        );

        $data = (new LockFile($this->tempDir))->read();

        self::assertArrayHasKey(
            'config/params.php',
            $data['files'],
            'File must remain tracked in the lock file during a dry-run.',
        );
    }

    public function testDryRunMessageEndsWithSinglePhpEolSuffix(): void
    {
        $this->seedTracked('config/params.php');

        $out = new BufferedOutputWriter();
        (new EjectService())->run($this->tempDir, 'config/params.php', confirmed: false, out: $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The dry-run 'Would remove' message must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The dry-run 'Would remove' message must not be prefixed with PHP_EOL.",
        );
    }

    public function testEjectRemovesEntryAndPreservesFileOnDiskWhenConfirmed(): void
    {
        $this->seedTracked('config/params.php');

        file_put_contents("{$this->tempDir}/config/params.php", "return [];\n");

        $out = new BufferedOutputWriter();
        $exitCode = (new EjectService())->run($this->tempDir, 'config/params.php', confirmed: true, out: $out);

        self::assertSame(
            0,
            $exitCode,
            "When the eject is confirmed, the exit code must be '0'.",
        );
        self::assertStringContainsString(
            'Removed',
            $out->stdoutBuffer,
            'When the eject is confirmed, the output must indicate the removal.',
        );

        $data = (new LockFile($this->tempDir))->read();

        self::assertArrayNotHasKey(
            'config/params.php',
            $data['files'],
            'File must no longer be tracked in the lock file after confirmed eject.',
        );
        self::assertFileExists(
            "{$this->tempDir}/config/params.php",
            'Eject must never delete the on-disk file.',
        );
    }

    public function testReturnsErrorWhenFileIsNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new EjectService())->run($this->tempDir, 'missing.php', confirmed: true, out: $out);

        self::assertSame(
            1,
            $exitCode,
            "When the file is not tracked, the exit code must be '1'.",
        );
        self::assertStringContainsString(
            'not tracked',
            $out->stderrBuffer,
            'When the file is not tracked, the output must indicate the error.',
        );
        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'not tracked' stderr message must end with PHP_EOL so subsequent stderr writes start on a fresh line.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'not tracked' stderr message must not begin with PHP_EOL.",
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
     * Seeds the lock file with a tracked file and ensures the test directory exists.
     *
     * @param string $destination Path of the file to track, relative to the project root.
     */
    private function seedTracked(string $destination): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    $destination => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => "stubs/{$destination}",
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $this->ensureTestDirectory(dirname("{$this->tempDir}/{$destination}"));
    }
}
