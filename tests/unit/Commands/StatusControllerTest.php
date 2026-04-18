<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Commands\StatusController;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\StatusControllerSpy;

/**
 * Unit tests for {@see StatusController} status computation via {@see StatusController::getStatuses()}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class StatusControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testActionIndexPrintsEmptyMessageWhenNoFilesTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Empty-lock status must still return a success exit code.',
        );
        self::assertStringContainsString(
            'No files tracked in scaffold-lock.json',
            $spy->stdoutBuffer,
            'Empty-lock case must emit the user-facing no-files message instead of a blank table.',
        );
    }

    public function testActionIndexPrintsHeaderAndRowsForTrackedFiles(): void
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

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            "Status command with tracked entries must return 'ExitCode::OK'.",
        );
        self::assertStringContainsString(
            'File',
            $spy->stdoutBuffer,
            "Status output must include a 'File' column header.",
        );
        self::assertStringContainsString(
            'Provider',
            $spy->stdoutBuffer,
            "Status output must include a 'Provider' column header.",
        );
        self::assertStringContainsString(
            'Mode',
            $spy->stdoutBuffer,
            "Status output must include a 'Mode' column header.",
        );
        self::assertStringContainsString(
            'Status',
            $spy->stdoutBuffer,
            "Status output must include a 'Status' column header.",
        );
        self::assertStringContainsString(
            'output.txt',
            $spy->stdoutBuffer,
            'Tracked destination must appear in the output row.',
        );
        self::assertStringContainsString(
            'synced',
            $spy->stdoutBuffer,
            "Matching-hash entries must appear with status 'synced'.",
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

        $statuses = $this->makeController()->getStatuses($this->tempDir);

        self::assertSame(
            'error',
            $statuses['../escape.php']['status'] ?? null,
            "Lock entries whose destination fails path validation must surface status 'error' rather than masking the "
            . 'problem.',
        );
    }

    public function testGetStatusesMarksEntryAsErrorWhenHashThrows(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX chmod is required to make a file unreadable; Windows lacks an equivalent.');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped("Test cannot run as root because 'chmod 0000' does not block root reads.");
        }

        $filePath = "{$this->tempDir}/unreadable.txt";

        file_put_contents($filePath, 'content');
        chmod($filePath, 0000);

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

        try {
            $statuses = $this->makeController()->getStatuses($this->tempDir);

            self::assertSame(
                'error',
                $statuses['unreadable.txt']['status'] ?? null,
                "When hash throws because the file is unreadable, the destination must surface with status 'error'.",
            );
        } finally {
            chmod($filePath, 0644);
        }
    }

    public function testGetStatusesReturnsAllEntriesPreservingOrder(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                    'b.txt' => [
                        'hash' => 'sha256:b',
                        'provider' => 'pkg/b',
                        'source' => 'stubs/b.txt',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        $statuses = $this->makeController()->getStatuses($this->tempDir);

        self::assertCount(
            2,
            $statuses,
            'All tracked destinations must be returned without truncation.',
        );
        self::assertArrayHasKey(
            'a.txt',
            $statuses,
            "Expected 'a.txt' key in statuses.",
        );
        self::assertArrayHasKey(
            'b.txt',
            $statuses,
            "Expected 'b.txt' key in statuses.",
        );
        self::assertSame(
            ['a.txt', 'b.txt'],
            array_keys($statuses),
            'Entries must be returned in the same order as declared in the lock file.',
        );
    }

    public function testGetStatusesReturnsEmptyArrayWhenNoLockFile(): void
    {
        $controller = $this->makeController();

        self::assertSame(
            [],
            $controller->getStatuses($this->tempDir),
            'Expected empty array when no lock file is present.',
        );
    }

    public function testGetStatusesReturnsMissingWhenFileAbsentFromDisk(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
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

        $statuses = $this->makeController()->getStatuses($this->tempDir);

        $entry = $statuses['config/params.php'] ?? null;

        if ($entry === null) {
            self::fail("Expected 'config/params.php' key in statuses.");
        }

        self::assertSame(
            'missing',
            $entry['status'],
            "Expected status to be 'missing' when file is absent from disk.",
        );
        self::assertSame(
            'pkg/name',
            $entry['provider'],
            "Expected provider to be 'pkg/name'.",
        );
        self::assertSame(
            'replace',
            $entry['mode'],
            "Expected mode to be 'replace'.",
        );
    }

    public function testGetStatusesReturnsModifiedWhenHashDiffers(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'user-modified content');

        $lock = new LockFile($this->tempDir);

        $lock->write(
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

        $statuses = $this->makeController()->getStatuses($this->tempDir);

        $entry = $statuses['output.txt'] ?? null;

        if ($entry === null) {
            self::fail("Expected 'output.txt' key in statuses.");
        }

        self::assertSame(
            'modified',
            $entry['status'],
            "Expected status to be 'modified' when file hash differs from lock file.",
        );
    }

    public function testGetStatusesReturnsSyncedWhenHashMatches(): void
    {
        $filePath = "{$this->tempDir}/output.txt";

        file_put_contents($filePath, 'stub content');

        $hasher = new Hasher();

        $hash = $hasher->hash($filePath);

        $lock = new LockFile($this->tempDir);

        $lock->write(
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

        $statuses = $this->makeController()->getStatuses($this->tempDir);

        $entry = $statuses['output.txt'] ?? null;

        if ($entry === null) {
            self::fail("Expected 'output.txt' key in statuses.");
        }

        self::assertSame(
            'synced',
            $entry['status'],
            "Expected status to be 'synced' when file hash matches lock file.",
        );
    }

    protected function setUp(): void
    {
        $this->setUpConsoleApplication();
    }

    protected function tearDown(): void
    {
        $this->tearDownConsoleApplication();
    }

    private function makeController(): StatusController
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new StatusController('status', $module);
    }

    private function makeSpy(): StatusControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new StatusControllerSpy('status', $module);
    }
}
