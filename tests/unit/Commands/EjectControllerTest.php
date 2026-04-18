<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\EjectControllerSpy;

/**
 * Unit tests for {@see \yii\scaffold\Commands\EjectController} lock-entry removal behavior.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class EjectControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testDryRunLeavesLockUntouchedWithoutYes(): void
    {
        $lock = new LockFile($this->tempDir);

        $initial = [
            'providers' => [],
            'files' => [
                'config/params.php' => [
                    'hash' => 'sha256:abc',
                    'provider' => 'pkg/name',
                    'source' => 'stubs/config/params.php',
                    'mode' => 'replace',
                ],
            ],
        ];

        $lock->write($initial);

        $controller = $this->makeController(yes: false);

        $exitCode = $controller->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Dry-run must succeed so users can preview the intended eject.',
        );
        self::assertStringContainsString(
            'Would remove "config/params.php"',
            $controller->stdoutBuffer,
            'Dry-run output must advertise that the lock is not actually being modified.',
        );
        self::assertArrayHasKey(
            'config/params.php',
            (new LockFile($this->tempDir))->read()['files'],
            'Dry-run must not persist any change to the lock file on disk.',
        );
    }

    public function testEjectRemovesEntryAndPreservesFileOnDiskWithYes(): void
    {
        $diskFile = "{$this->tempDir}/config/params.php";

        mkdir(dirname($diskFile), 0777, recursive: true);
        file_put_contents($diskFile, 'user content');

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
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

        $controller = $this->makeController(yes: true);

        $exitCode = $controller->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            "Confirmed ejection of a tracked file must return 'ExitCode::OK'.",
        );
        self::assertArrayNotHasKey(
            'config/params.php',
            (new LockFile($this->tempDir))->read()['files'],
            "Tracked file entry must be removed from 'scaffold-lock.json' after a confirmed eject.",
        );
        self::assertFileExists(
            $diskFile,
            'The on-disk file must remain after ejection; only the lock entry is removed.',
        );
        self::assertStringEqualsFile(
            $diskFile,
            'user content',
            'The on-disk file content must remain byte-for-byte unchanged after ejection.',
        );
        self::assertStringContainsString(
            'was not deleted from disk',
            $controller->stdoutBuffer,
            'Success message must explicitly reassure the user that the file is untouched on disk.',
        );
    }

    public function testReturnsErrorWhenFileNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $controller = $this->makeController(yes: true);

        $exitCode = $controller->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'Attempting to eject a destination that is not tracked must return a non-OK exit code.',
        );
        self::assertStringContainsString(
            'is not tracked in scaffold-lock.json',
            $controller->stderrBuffer,
            'User must see a clear error when the file is not in the lock file.',
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

    private function makeController(bool $yes): EjectControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        $controller = new EjectControllerSpy('eject', $module);

        $controller->yes = $yes;

        return $controller;
    }
}
