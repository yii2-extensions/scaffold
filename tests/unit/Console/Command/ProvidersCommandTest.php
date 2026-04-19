<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Console\Command\ProvidersCommand;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;
use function is_dir;

/**
 * Unit tests for the Symfony Console {@see ProvidersCommand} verifying the provider summary output.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console-command')]
final class ProvidersCommandTest extends TestCase
{
    use TempDirectoryTrait;

    private string $originalCwd = '';

    public function testExecuteListsProvidersWithFileCounts(): void
    {
        (new LockFile($this->tempDir))->write(
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
                        'provider' => 'pkg/a',
                        'source' => 'stubs/b.txt',
                        'mode' => 'replace',
                    ],
                    'c.txt' => [
                        'hash' => 'sha256:c',
                        'provider' => 'pkg/b',
                        'source' => 'stubs/c.txt',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        $tester = new CommandTester(new ProvidersCommand());

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(
            0,
            $exitCode,
            'Providers command must return a success exit code when providers are tracked.',
        );
        self::assertMatchesRegularExpression(
            '#pkg/a\s+2\b#',
            $display,
            "Provider 'pkg/a' must be rendered with its file count of '2' on the same row.",
        );
        self::assertMatchesRegularExpression(
            '#pkg/b\s+1\b#',
            $display,
            "Provider 'pkg/b' must be rendered with its file count of '1' on the same row.",
        );
    }

    public function testExecutePrintsEmptyMessageWhenNoProvidersTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new ProvidersCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(
            0,
            $exitCode,
            'Providers command must return success exit code when no providers are tracked.',
        );
        self::assertStringContainsString(
            'No providers tracked in scaffold-lock.json',
            $tester->getDisplay(),
            'Providers command must indicate when no providers are tracked.',
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

        $tester = new CommandTester(new ProvidersCommand());

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
            'Providers command must return failure exit code when getcwd fails.',
        );
        self::assertStringContainsString(
            'Unable to determine current working directory',
            $tester->getDisplay(),
            'Providers command must indicate when it cannot determine the current working directory.',
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
