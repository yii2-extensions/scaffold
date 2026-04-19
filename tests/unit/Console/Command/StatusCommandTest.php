<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Console\Command\StatusCommand;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Unit tests for the Symfony Console {@see StatusCommand} verifying the status listing against a real
 * `scaffold-lock.json` written to a temporary project root.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console-command')]
final class StatusCommandTest extends TestCase
{
    use TempDirectoryTrait;

    private string $originalCwd = '';

    public function testExecutePrintsEmptyMessageWhenNoFilesTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new StatusCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(
            0,
            $exitCode,
            'Empty-lock status must still return a success exit code.',
        );
        self::assertStringContainsString(
            'No files tracked in scaffold-lock.json',
            $tester->getDisplay(),
            'Empty-lock case must emit the user-facing no-files message instead of a blank table.',
        );
    }

    public function testExecutePrintsHeaderAndRowsForTrackedFiles(): void
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
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $tester = new CommandTester(new StatusCommand());

        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertSame(
            0,
            $exitCode,
            'Status command must return success exit code when files are tracked.',
        );
        self::assertStringContainsString(
            'File',
            $display,
            'Status output must include a header with a "File" column.',
        );
        self::assertStringContainsString(
            'Provider',
            $display,
            'Status output must include a header with a "Provider" column.',
        );
        self::assertStringContainsString(
            'Mode',
            $display,
            'Status output must include a header with a "Mode" column.',
        );
        self::assertStringContainsString(
            'Status',
            $display,
            'Status output must include a header with a "Status" column.',
        );
        self::assertStringContainsString(
            'output.txt',
            $display,
            'Status output must include the tracked file name.',
        );
        self::assertStringContainsString(
            'synced',
            $display,
            'Status output must indicate the file is synced.',
        );
    }

    public function testExecuteReturnsFailureWhenGetcwdFails(): void
    {
        MockerState::addCondition(
            'yii\\scaffold\\Console\\Command',
            'getcwd',
            [],
            false,
            default: true,
        );

        $tester = new CommandTester(new StatusCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(
            Command::FAILURE,
            $exitCode,
            "When 'getcwd()' returns 'false', the command must fail fast instead of propagating an empty "
            . 'project root.',
        );
        self::assertStringContainsString(
            'Unable to determine current working directory',
            $tester->getDisplay(),
            'A clear diagnostic must be written to output when the CWD cannot be determined.',
        );
    }

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();

        $this->setUpTempDirectory();

        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== '' && is_dir($this->originalCwd)) {
            chdir($this->originalCwd);
        }

        $this->tearDownTempDirectory();
    }
}
