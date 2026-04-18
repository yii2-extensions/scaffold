<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\ReapplyControllerSpy;

/**
 * Unit tests for {@see \yii\scaffold\Commands\ReapplyController} scaffold re-application behavior.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class ReapplyControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testAppendAndPrependModesCannotBeReapplied(): void
    {
        $this->seedProviderStub('stubs/.gitignore', "/runtime/\n");
        $this->writeLockEntry('.gitignore', "/runtime/\n", 'append');
        $this->seedProviderStub('stubs/.env', "APP_ENV=dev\n");
        $this->writeLockEntry('.env', "APP_ENV=dev\n", 'prepend');

        $controller = $this->makeController(force: false);

        $exitCode = $controller->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            "Reapply must return 'ExitCode::OK' even when append/prepend entries are present; they are simply skipped.",
        );
        self::assertStringContainsString(
            'uses mode "append" and cannot be safely reapplied',
            $controller->stdoutBuffer,
            'Append mode must emit a clear skip message rather than silently continuing.',
        );
        self::assertStringContainsString(
            'uses mode "prepend" and cannot be safely reapplied',
            $controller->stdoutBuffer,
            'Prepend mode must emit a clear skip message rather than silently continuing.',
        );
    }

    public function testForceOverwritesUserModifiedFileAndUpdatesLockHash(): void
    {
        $stubContent = "stub v2\n";
        $userContent = "user-edited\n";

        $this->seedProviderStub('stubs/config/params.php', $stubContent);
        $this->writeLockEntry('config/params.php', "stub v1\n", 'replace');

        $destination = "{$this->tempDir}/config/params.php";
        mkdir(dirname($destination), 0777, recursive: true);
        file_put_contents($destination, $userContent);

        $controller = $this->makeController(force: true);

        $controller->actionIndex();

        self::assertSame(
            $stubContent,
            file_get_contents($destination),
            "With '--force', a user-modified replace-mode destination must be overwritten with the current stub "
            . 'content.',
        );

        $lockHash = (new LockFile($this->tempDir))->getHashAtScaffold('config/params.php');

        self::assertSame(
            (new Hasher())->hash($destination),
            $lockHash,
            'Lock hash must be updated to reflect the content actually written during reapply.',
        );
    }

    public function testPreserveModeIsSkippedWithoutForce(): void
    {
        $this->seedProviderStub('stubs/config/params.php', "stub\n");
        $this->writeLockEntry('config/params.php', "stub\n", 'preserve');

        $destination = "{$this->tempDir}/config/params.php";

        mkdir(dirname($destination), 0777, recursive: true);
        file_put_contents($destination, "kept by user\n");

        $controller = $this->makeController(force: false);

        $controller->actionIndex();

        self::assertSame(
            "kept by user\n",
            file_get_contents($destination),
            "Preserve mode must leave the on-disk file untouched unless '--force' is explicitly requested.",
        );
        self::assertStringContainsString(
            'uses mode "preserve". Use --force to overwrite',
            $controller->stdoutBuffer,
            "Preserve-mode skip must guide the user toward the '--force' escape hatch.",
        );
    }

    public function testProviderFilterOnlyProcessesMatchingEntries(): void
    {
        $this->seedProviderStub('stubs/a.txt', 'content a', providerName: 'pkg/a');
        $this->seedProviderStub('stubs/b.txt', 'content b', providerName: 'pkg/b');

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/a' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/a',
                    ],
                    'pkg/b' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/b',
                    ],
                ],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:' . hash('sha256', 'content a'),
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                    'b.txt' => [
                        'hash' => 'sha256:' . hash('sha256', 'content b'),
                        'provider' => 'pkg/b',
                        'source' => 'stubs/b.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $controller = $this->makeController(force: false, provider: 'pkg/a');

        $controller->actionIndex();

        self::assertFileExists(
            "{$this->tempDir}/a.txt",
            'Files from the filtered provider must be reapplied.',
        );
        self::assertFileDoesNotExist(
            "{$this->tempDir}/b.txt",
            "Files from non-matching providers must be skipped entirely when '--provider' filter is active.",
        );
    }

    public function testReturnsErrorWhenFilterMatchesNothing(): void
    {
        $this->writeLockEntry('config/params.php', "stub\n", 'replace');

        $controller = $this->makeController(force: false);

        $exitCode = $controller->actionIndex('does/not/exist.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'A filter that matches zero tracked files must surface an error so CI fails loudly on typos.',
        );
        self::assertStringContainsString(
            'No tracked files matched the given filter',
            $controller->stderrBuffer,
            'User must see a clear error when the filter matches no entries.',
        );
    }

    public function testSkipsUserModifiedFileWithoutForce(): void
    {
        $this->seedProviderStub('stubs/config/params.php', "stub v1\n");
        $this->writeLockEntry('config/params.php', "stub v1\n", 'replace');

        $destination = "{$this->tempDir}/config/params.php";

        mkdir(dirname($destination), 0777, recursive: true);
        file_put_contents($destination, "user edit\n");

        $controller = $this->makeController(force: false);

        $controller->actionIndex();

        self::assertSame(
            "user edit\n",
            file_get_contents($destination),
            "Without '--force', a user-modified replace-mode destination must not be overwritten by reapply.",
        );
        self::assertStringContainsString(
            'is user-modified. Use --force to overwrite',
            $controller->stdoutBuffer,
            "User-modified skip must name the '--force' flag as the escape hatch.",
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

    private function makeController(bool $force, string $provider = ''): ReapplyControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        $controller = new ReapplyControllerSpy('reapply', $module);

        $controller->force = $force;
        $controller->provider = $provider;

        return $controller;
    }

    private function seedProviderStub(
        string $relPath,
        string $content,
        string $providerName = 'pkg/name',
    ): void {
        $stubPath = "{$this->tempDir}/vendor/{$providerName}/{$relPath}";

        mkdir(dirname($stubPath), 0777, recursive: true);

        file_put_contents($stubPath, $content);
    }

    private function writeLockEntry(
        string $destination,
        string $hashedContent,
        string $mode,
        string $providerName = 'pkg/name',
    ): void {
        $lock = new LockFile($this->tempDir);

        $existing = $lock->read();

        $existing['providers'][$providerName] = [
            'version' => '1.0.0',
            'path' => "vendor/{$providerName}",
        ];
        $existing['files'][$destination] = [
            'hash' => 'sha256:' . hash('sha256', $hashedContent),
            'provider' => $providerName,
            'source' => 'stubs/' . ltrim($destination, '/'),
            'mode' => $mode,
        ];

        $lock->write($existing);
    }
}
