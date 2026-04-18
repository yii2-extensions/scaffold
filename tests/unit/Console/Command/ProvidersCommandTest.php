<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use yii\scaffold\Console\Command\ProvidersCommand;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;

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
                    'a.txt' => ['hash' => 'sha256:a', 'provider' => 'pkg/a', 'source' => 'stubs/a.txt', 'mode' => 'replace'],
                    'b.txt' => ['hash' => 'sha256:b', 'provider' => 'pkg/a', 'source' => 'stubs/b.txt', 'mode' => 'replace'],
                    'c.txt' => ['hash' => 'sha256:c', 'provider' => 'pkg/b', 'source' => 'stubs/c.txt', 'mode' => 'preserve'],
                ],
            ],
        );

        $tester = new CommandTester(new ProvidersCommand());

        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('pkg/a', $display);
        self::assertStringContainsString('pkg/b', $display);
        self::assertStringContainsString(' 2', $display);
        self::assertStringContainsString(' 1', $display);
    }

    public function testExecutePrintsEmptyMessageWhenNoProvidersTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new ProvidersCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(
            'No providers tracked in scaffold-lock.json',
            $tester->getDisplay(),
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
}
