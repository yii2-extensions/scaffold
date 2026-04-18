<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use yii\scaffold\Console\Command\DiffCommand;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chdir;
use function getcwd;

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

        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();

        self::assertStringContainsString("- return ['a' => 1];", $display);
        self::assertStringContainsString("+ return ['a' => 2];", $display);
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

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No differences found', $tester->getDisplay());
    }

    public function testExecuteReturnsErrorWhenFileIsNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $tester = new CommandTester(new DiffCommand());

        $exitCode = $tester->execute(
            ['file' => 'missing.php'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not tracked', $tester->getErrorOutput());
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

    private function seedProviderAndFile(string $destination, string $sourceContent, string $currentContent): void
    {
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";
        $stubRelative = 'stubs/' . $destination;
        $stubPath = $providerRoot . '/' . $stubRelative;

        mkdir(dirname($stubPath), 0777, recursive: true);
        file_put_contents($stubPath, $sourceContent);

        $destAbsolute = $this->tempDir . '/' . $destination;

        mkdir(dirname($destAbsolute), 0777, recursive: true);
        file_put_contents($destAbsolute, $currentContent);

        $hash = (new Hasher())->hash($destAbsolute);

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => ['version' => '0.1.x-dev', 'path' => 'vendor/pkg/name'],
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
