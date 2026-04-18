<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use yii\scaffold\Console\Command\StatusCommand;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;

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

        $hash = (new \yii\scaffold\Scaffold\Lock\Hasher())->hash($filePath);

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

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('File', $display);
        self::assertStringContainsString('Provider', $display);
        self::assertStringContainsString('Mode', $display);
        self::assertStringContainsString('Status', $display);
        self::assertStringContainsString('output.txt', $display);
        self::assertStringContainsString('synced', $display);
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();

        $this->originalCwd = (string) getcwd();

        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }

        $this->tearDownTempDirectory();
    }
}
