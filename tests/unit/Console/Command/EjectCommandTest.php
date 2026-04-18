<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use yii\scaffold\Console\Command\EjectCommand;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;

/**
 * Unit tests for the Symfony Console {@see EjectCommand} verifying dry-run, confirmed-removal, and not-tracked paths.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console-command')]
final class EjectCommandTest extends TestCase
{
    use TempDirectoryTrait;

    private string $originalCwd = '';

    public function testExecuteOnlyPreviewsWithoutYesFlag(): void
    {
        $this->seedTrackedFile('config/params.php');

        $tester = new CommandTester(new EjectCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Would remove', $tester->getDisplay());

        $data = (new LockFile($this->tempDir))->read();

        self::assertArrayHasKey(
            'config/params.php',
            $data['files'],
            "Without '--yes' the lock file must remain untouched (dry-run).",
        );
    }

    public function testExecuteRemovesEntryWhenConfirmedWithYesFlag(): void
    {
        $this->seedTrackedFile('config/params.php');

        $tester = new CommandTester(new EjectCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php', '--yes' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Removed', $tester->getDisplay());

        $data = (new LockFile($this->tempDir))->read();

        self::assertArrayNotHasKey(
            'config/params.php',
            $data['files'],
            "With '--yes' the tracked entry must be removed from 'scaffold-lock.json'.",
        );
    }

    public function testExecuteReturnsErrorWhenFileIsNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new EjectCommand());

        $exitCode = $tester->execute(
            ['file' => 'missing.php', '--yes' => true],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'not tracked',
            $tester->getErrorOutput(),
            'Eject of an untracked file must surface an error diagnostic on stderr.',
        );
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

    private function seedTrackedFile(string $destination): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    $destination => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/' . $destination,
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }
}
