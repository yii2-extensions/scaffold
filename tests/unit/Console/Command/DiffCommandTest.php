<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Console\Command\DiffCommand;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Unit tests for the Symfony Console {@see DiffCommand} covering identical-content, divergent-content, and
 * untracked-file scenarios.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console-command')]
final class DiffCommandTest extends TestCase
{
    use TempDirectoryTrait;

    private string $originalCwd = '';

    public function testExecutePrintsDiffWhenContentsDiverge(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return ['a' => 1];\n",
            currentContent: "return ['a' => 2];\n",
        );

        $tester = new CommandTester(new DiffCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php']);

        self::assertSame(
            0,
            $exitCode,
            'Diff command must return success exit code even when differences are found.',
        );

        $display = $tester->getDisplay();

        self::assertStringContainsString(
            "- return ['a' => 1];",
            $display,
            "Diff output must contain removed line prefixed with '-''.",
        );
        self::assertStringContainsString(
            "+ return ['a' => 2];",
            $display,
            "Diff output must contain added line prefixed with '+'.",
        );
    }

    public function testExecuteReportsNoDifferencesWhenStubAndCurrentMatch(): void
    {
        $this->seedProviderAndFile(
            destination: 'config/params.php',
            sourceContent: "return [];\n",
            currentContent: "return [];\n",
        );

        $tester = new CommandTester(new DiffCommand());

        $exitCode = $tester->execute(['file' => 'config/params.php']);

        self::assertSame(
            0,
            $exitCode,
            'Diff command must return success exit code when no differences are found.',
        );
        self::assertStringContainsString(
            'No differences found',
            $tester->getDisplay(),
            'Diff command must indicate when no differences are found.',
        );
    }

    public function testExecuteReturnsErrorWhenFileIsNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new DiffCommand());

        $exitCode = $tester->execute(
            ['file' => 'missing.php'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(
            1,
            $exitCode,
            'Diff command must return error exit code when file is not tracked.',
        );
        self::assertStringContainsString(
            'not tracked',
            $tester->getErrorOutput(),
            'Diff command must indicate when file is not tracked.',
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

        $tester = new CommandTester(new DiffCommand());

        self::assertSame(
            Command::FAILURE,
            $tester->execute(['file' => 'config/params.php']),
            'Diff command must return failure exit code when getcwd fails.',
        );
        self::assertStringContainsString(
            'Unable to determine current working directory',
            $tester->getDisplay(),
            'Diff command must indicate when the current working directory cannot be determined.',
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

    private function seedProviderAndFile(string $destination, string $sourceContent, string $currentContent): void
    {
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";
        $stubRelative = "stubs/{$destination}";
        $stubPath = "{$providerRoot}/{$stubRelative}";

        $this->ensureTestDirectory(dirname($stubPath));

        file_put_contents($stubPath, $sourceContent);

        $destAbsolute = "{$this->tempDir}/{$destination}";

        $this->ensureTestDirectory(dirname($destAbsolute));

        file_put_contents($destAbsolute, $currentContent);

        $hash = (new Hasher())->hash($destAbsolute);

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
                        'hash' => $hash,
                        'provider' => 'pkg/name',
                        'source' => $stubRelative,
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
    }
}
