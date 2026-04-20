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

    public function testGetStatusesContinuesPastErrorEntryToReportSubsequentValidEntries(): void
    {
        $filePath = "{$this->tempDir}/valid.txt";

        file_put_contents($filePath, 'stub content');

        $hash = (new Hasher())->hash($filePath);
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    // first entry fails validation (path traversal) and must emit 'error' while the loop continues.
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                    // second entry must still be reported after the first hit the error-continue path.
                    'valid.txt' => [
                        'hash' => $hash,
                        'provider' => 'pkg/name',
                        'source' => 'stubs/valid.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $statuses = (new StatusService())->getStatuses($this->tempDir);

        self::assertArrayHasKey(
            '../escape.php',
            $statuses,
            "Invalid entries must still appear in the status map (with status 'error') so the CLI can surface them.",
        );
        self::assertArrayHasKey(
            'valid.txt',
            $statuses,
            "An earlier unsafe-destination entry must not 'break' the foreach; the next valid entry must still report.",
        );
        self::assertSame(
            'synced',
            $statuses['valid.txt']['status'] ?? null,
            "The second entry's status must be computed (here 'synced'), confirming the loop kept iterating.",
        );
    }

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

    public function testGetStatusesReturnsAllEntriesUnchangedWithoutSlicingToTheFirst(): void
    {
        $first = "{$this->tempDir}/first.txt";
        $second = "{$this->tempDir}/second.txt";

        file_put_contents($first, 'first content');
        file_put_contents($second, 'second content');

        $hasher = new Hasher();

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'first.txt' => [
                        'hash' => $hasher->hash($first),
                        'provider' => 'pkg/name',
                        'source' => 'stubs/first.txt',
                        'mode' => 'replace',
                    ],
                    'second.txt' => [
                        'hash' => $hasher->hash($second),
                        'provider' => 'pkg/name',
                        'source' => 'stubs/second.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $statuses = (new StatusService())->getStatuses($this->tempDir);

        self::assertCount(
            2,
            $statuses,
            'The status map must contain every tracked entry; dropping rows would mislead the CLI.',
        );
        self::assertArrayHasKey(
            'second.txt',
            $statuses,
            'The second entry must be present; keeping only the first would mask out subsequent rows.',
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

    public function testRunEmptyStatusMessageEndsWithSinglePhpEolSuffix(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        (new StatusService())->run($this->tempDir, $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No files tracked' empty-state message must end with PHP_EOL.",
        );
        self::assertStringEndsNotWith(
            PHP_EOL . PHP_EOL,
            $out->stdoutBuffer,
            "The 'No files tracked' message must end with exactly one PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No files tracked' empty-state message must not be prefixed with PHP_EOL.",
        );
    }

    public function testRunHeaderEndsWithSinglePhpEolSuffix(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'content');

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
        (new StatusService())->run($this->tempDir, $out);

        self::assertStringStartsWith(
            'File',
            $out->stdoutBuffer,
            "The rendered status table must start with the 'File' header, not a stray leading PHP_EOL.",
        );
        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            'The final row must be terminated by PHP_EOL so shells render it on its own line.',
        );
        self::assertStringEndsNotWith(
            PHP_EOL . PHP_EOL,
            $out->stdoutBuffer,
            'The final row must end with exactly one PHP_EOL.',
        );
    }

    public function testRunPinsColStatusToLiteralSixWhenObservedStatusIsShorter(): void
    {
        // 'getStatuses' returns status='error' (length 5); separator width therefore depends on the 'max(6,...)' literal.
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    '../x' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'p',
                        'source' => 'stubs/x',
                        'mode' => 'x',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        (new StatusService())->run($this->tempDir, $out);

        // colFile=max(4, len('../x')=4)=4, colProvider=max(8,1)=8, colMode=max(4,1)=4, colStatus=max(6, 5)=6
        // separator width = 4 + 8 + 4 + 6 + 6 = 28 dashes.
        self::assertStringContainsString(
            PHP_EOL . str_repeat('-', 28) . PHP_EOL,
            $out->stdoutBuffer,
            "The separator row must be exactly 28 dashes on its own line when status='error' pins colStatus to 6.",
        );
        self::assertStringNotContainsString(
            PHP_EOL . str_repeat('-', 27) . PHP_EOL,
            $out->stdoutBuffer,
            'The separator must not collapse to 27 dashes.',
        );
        self::assertStringNotContainsString(
            PHP_EOL . str_repeat('-', 29) . PHP_EOL,
            $out->stdoutBuffer,
            'The separator must not widen to 29 dashes.',
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

    public function testRunRendersSeparatorRowWithWidthExactlyMatchingColumnLiteralsPlusSixPaddingForShortData(): void
    {
        $filePath = "{$this->tempDir}/a";

        file_put_contents($filePath, 'c');

        $hash = (new Hasher())->hash($filePath);

        // All fields shorter than the column literals (4/8/4/6) so the separator width depends solely on those literals.
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'a' => [
                        'hash' => $hash,
                        'provider' => 'p',
                        'source' => 'stubs/a',
                        'mode' => 'x',
                        // ^ artificial short mode; status is computed from disk state (synced/missing/modified/error).
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        (new StatusService())->run($this->tempDir, $out);

        // colFile=max(4,1)=4, colProvider=max(8,1)=8, colMode=max(4,1)=4, colStatus=max(6,6 for 'synced')=6
        // separator width = 4 + 8 + 4 + 6 + 6 = 28 dashes.
        self::assertStringContainsString(
            PHP_EOL . str_repeat('-', 28) . PHP_EOL,
            $out->stdoutBuffer,
            'The separator row must be exactly 28 dashes on its own line when every column is short.',
        );
        self::assertStringNotContainsString(
            str_repeat('-', 29),
            $out->stdoutBuffer,
            'The separator must not contain 29 consecutive dashes.',
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
