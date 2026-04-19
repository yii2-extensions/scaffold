<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Console\Command\ReapplyCommand;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Unit tests for the Symfony Console {@see ReapplyCommand} covering single-file reapply, filter-mismatch error, and
 * the `--force` flag.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console-command')]
final class ReapplyCommandTest extends TestCase
{
    use TempDirectoryTrait;

    private string $originalCwd = '';

    public function testExecuteOverwritesUserModifiedFileWithForce(): void
    {
        $this->seedTracked(
            destination: 'config/params.php',
            stubContent: "original\n",
            currentContent: "user-edited\n",
            lockHashOf: "original\n",
        );

        $tester = new CommandTester(new ReapplyCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php', '--force' => true]);

        self::assertSame(
            0,
            $exitCode,
            "Reapply command must return success exit code when file is reapplied with '--force'.",
        );
        self::assertStringContainsString(
            'Reapplied',
            $tester->getDisplay(),
            'Reapply command must indicate when a file is reapplied.',
        );
        self::assertSame(
            "original\n",
            (string) file_get_contents($this->tempDir . '/config/params.php'),
            "'--force' must overwrite the user's content with the stub.",
        );
    }

    public function testExecuteReappliesUnmodifiedFileAndReportsSuccess(): void
    {
        $stub = "return ['key' => 'value'];\n";

        $this->seedTracked(destination: 'config/params.php', stubContent: $stub, currentContent: $stub);

        $tester = new CommandTester(new ReapplyCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php']);

        self::assertSame(
            0,
            $exitCode,
            'Reapply command must return success exit code when file is reapplied.',
        );
        self::assertStringContainsString(
            'Reapplied',
            $tester->getDisplay(),
            'Reapply command must indicate when a file is reapplied.',
        );
    }

    public function testExecuteReturnsErrorWhenFileFilterMatchesNothing(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new ReapplyCommand());

        $exitCode = $tester->execute(
            ['file' => 'nonexistent.php'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(
            1,
            $exitCode,
            'Reapply command must return error exit code when file filter matches nothing.',
        );
        self::assertStringContainsString(
            'No tracked files matched',
            $tester->getErrorOutput(),
            'Reapply command must indicate when no tracked files match the filter.',
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

        $tester = new CommandTester(new ReapplyCommand());

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
            'Reapply command must return failure exit code when getcwd fails.',
        );
        self::assertStringContainsString(
            'Unable to determine current working directory',
            $tester->getDisplay(),
            'Reapply command must indicate when it cannot determine the current working directory.',
        );
    }

    public function testExecuteSkipsUserModifiedFileWithoutForce(): void
    {
        $this->seedTracked(
            destination: 'config/params.php',
            stubContent: "original\n",
            currentContent: "user-edited\n",
            lockHashOf: "original\n",
        );

        $tester = new CommandTester(new ReapplyCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php']);

        self::assertSame(
            0,
            $exitCode,
            'Reapply command must return success exit code when user-modified file is skipped without force.',
        );
        self::assertStringContainsString(
            'user-modified',
            $tester->getDisplay(),
            'Reapply command must indicate when a user-modified file is skipped without force.',
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

    private function seedTracked(
        string $destination,
        string $stubContent,
        string $currentContent,
        string|null $lockHashOf = null,
    ): void {
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";
        $stubRelative = "stubs/{$destination}";
        $stubPath = "{$providerRoot}/{$stubRelative}";

        $this->ensureTestDirectory(dirname($stubPath));

        file_put_contents($stubPath, $stubContent);

        $destAbsolute = "{$this->tempDir}/{$destination}";

        $this->ensureTestDirectory(dirname($destAbsolute));

        file_put_contents($destAbsolute, $currentContent);

        $hasher = new Hasher();

        $lockHash = $lockHashOf !== null
            ? 'sha256:' . hash('sha256', $lockHashOf)
            : $hasher->hash($destAbsolute);

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
                        'hash' => $lockHash,
                        'provider' => 'pkg/name',
                        'source' => $stubRelative,
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }
}
