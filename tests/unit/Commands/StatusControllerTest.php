<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\scaffold\Commands\StatusController;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\ConsoleApplicationTrait;

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

    public function testGetStatusesReturnsEmptyArrayWhenNoLockFile(): void
    {
        $controller = $this->makeController();

        self::assertSame([], $controller->getStatuses($this->tempDir));
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
            self::fail('Expected "config/params.php" key in statuses.');
        }

        self::assertSame(
            'missing',
            $entry['status'],
            'Expected status to be "missing" when file is absent from disk.',
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
            self::fail('Expected "output.txt" key in statuses.');
        }

        self::assertSame(
            'MODIFIED',
            $entry['status'],
            "Expected status to be 'MODIFIED' when file hash differs from lock file.",
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
            self::fail('Expected "output.txt" key in statuses.');
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
}
