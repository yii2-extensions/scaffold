<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Services\StatusService;
use yii\scaffold\tests\support\{BufferedOutputWriter, TempDirectoryTrait};

/**
 * Unit tests for {@see StatusService} covering the status-computation branches and rendered output.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('services')]
final class StatusServiceTest extends TestCase
{
    use TempDirectoryTrait;

    public function testGetStatusesMarksEntryAsErrorWhenDestinationIsUnsafe(): void
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

        $statuses = (new StatusService())->getStatuses($this->tempDir);

        self::assertSame(
            'error',
            $statuses['../escape.php']['status'] ?? null,
            'Lock entries whose destination fails path validation must surface status "error".',
        );
    }

    public function testGetStatusesMarksEntryAsErrorWhenHashThrows(): void
    {
        $filePath = "{$this->tempDir}/unreadable.txt";

        file_put_contents($filePath, 'content');

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'unreadable.txt' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/unreadable.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'is_readable',
            [],
            false,
            default: true,
        );
        $statuses = (new StatusService())->getStatuses($this->tempDir);

        self::assertSame(
            'error',
            $statuses['unreadable.txt']['status'] ?? null,
            "When 'hash' throws because the file is unreadable, the destination must surface with status 'error'.",
        );
    }

    public function testGetStatusesReturnsEmptyArrayWhenNoLockFile(): void
    {
        self::assertSame(
            [],
            (new StatusService())->getStatuses($this->tempDir),
            'Expected empty array when no lock file is present.',
        );
    }

    public function testGetStatusesReturnsMissingWhenFileAbsentFromDisk(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/config/params.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        self::assertSame(
            'missing',
            (new StatusService())->getStatuses($this->tempDir)['config/params.php']['status'] ?? null,
            "Expected status 'missing' when the destination file is absent from disk.",
        );
    }

    public function testGetStatusesReturnsModifiedWhenHashDiffers(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'user-modified content');

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'output.txt' => [
                        'hash' => 'sha256:' . hash('sha256', 'original stub content'),
                        'provider' => 'pkg/name',
                        'source' => 'stubs/output.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        self::assertSame(
            'modified',
            (new StatusService())->getStatuses($this->tempDir)['output.txt']['status'] ?? null,
            "Expected status 'modified' when the on-disk hash differs from the recorded hash.",
        );
    }

    public function testGetStatusesReturnsSyncedWhenHashMatches(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'stub content');

        $hash = (new Hasher())->hash($filePath);
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'output.txt' => [
                        'hash' => $hash,
                        'provider' => 'pkg/name',
                        'source' => 'stubs/output.txt',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        self::assertSame(
            'synced',
            (new StatusService())->getStatuses($this->tempDir)['output.txt']['status'] ?? null,
            "Expected status 'synced' when the on-disk hash matches the recorded hash.",
        );
    }

    public function testRunPrintsEmptyMessageWhenNoFilesTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new StatusService())->run($this->tempDir, $out);

        self::assertSame(
            0,
            $exitCode,
            "Expected exit code '0' when no files are tracked, indicating successful execution.",
        );
        self::assertStringContainsString(
            'No files tracked in scaffold-lock.json',
            $out->stdoutBuffer,
            "Expected message indicating no files are tracked when 'scaffold-lock.json' is empty.",
        );
    }

    public function testRunPrintsHeaderAndRowsForTrackedFiles(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'synced content');

        $hash = (new Hasher())->hash($filePath);
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'output.txt' => [
                        'hash' => $hash,
                        'provider' => 'pkg/name',
                        'source' => 'stubs/output.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new StatusService())->run($this->tempDir, $out);

        self::assertSame(
            0,
            $exitCode,
            "Expected exit code '0' when files are tracked, indicating successful execution.",
        );
        self::assertStringContainsString(
            'File',
            $out->stdoutBuffer,
            "Expected header 'File' to be printed for tracked files.",
        );
        self::assertStringContainsString(
            'Provider',
            $out->stdoutBuffer,
            "Expected header 'Provider' to be printed for tracked files.",
        );
        self::assertStringContainsString(
            'Mode',
            $out->stdoutBuffer,
            "Expected header 'Mode' to be printed for tracked files.",
        );
        self::assertStringContainsString(
            'Status',
            $out->stdoutBuffer,
            "Expected header 'Status' to be printed for tracked files.",
        );
        self::assertStringContainsString(
            'output.txt',
            $out->stdoutBuffer,
            "Expected tracked file 'output.txt' to be listed.",
        );
        self::assertStringContainsString(
            'synced',
            $out->stdoutBuffer,
            "Expected status 'synced' to be printed for tracked file 'output.txt'.",
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
}
