<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Scaffold\PathResolver;
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

    public function testEmitsWarningWhenProviderPathEscapesVendor(): void
    {
        /**
         * Lock records a provider path that resolves outside vendor; PathResolver returns a warning that must reach
         * stderr before scaffold falls back to the default vendor-relative path.
         */
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '1.0.0',
                        'path' => '/etc',
                    ],
                ],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:' . hash('sha256', "stub\n"),
                        'provider' => 'pkg/name',
                        'source' => 'stubs/config/params.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $this->seedProviderStub('stubs/config/params.php', "stub\n");
        $controller = $this->makeController(force: false);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'resolves outside vendor dir',
            $controller->stderrBuffer,
            'A provider path that escapes vendor must emit a visible warning before the fallback resolution.',
        );
    }

    public function testEnsureDirectoryFailureIsReportedAsError(): void
    {
        $stub = "stub\n";

        $this->seedProviderStub('stubs/nested/deep.php', $stub);
        $this->writeLockEntry('nested/deep.php', $stub, 'replace');

        /**
         * Use `default: true` to intercept all `is_dir` and `mkdir` calls in the Scaffold namespace regardless of
         * platform path-separator differences; the test only cares that ensureDirectory explodes and the controller
         * surfaces a skip-with-reason.
         */
        MockerState::addCondition('yii\\scaffold\\Scaffold', 'is_dir', [], false, default: true);
        MockerState::addCondition('yii\\scaffold\\Scaffold', 'mkdir', [], false, default: true);

        $controller = $this->makeController(force: true);

        $controller->actionIndex('nested/deep.php');

        self::assertStringContainsString(
            'Could not create directory',
            $controller->stderrBuffer,
            'ensureDirectory exceptions must be surfaced as a skip-with-reason instead of crashing the reapply loop.',
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

    public function testHashFailureBeforeWriteIsReported(): void
    {
        $stub = "stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'replace');

        $destPath = PathResolver::destination(Yii::$app->basePath, 'config/params.php');

        mkdir(dirname($destPath), 0777, recursive: true);
        file_put_contents($destPath, 'existing');

        /**
         * default-mock `is_readable` so every lookup returns false and the pre-write hash throws, independent of how
         * the path is spelled on disk (matters on Windows where separators can differ).
         */
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'is_readable',
            [],
            false,
            default: true,
        );

        $controller = $this->makeController(force: false);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'Could not hash',
            $controller->stderrBuffer,
            'A failure to hash the destination before write must be surfaced as a skip-with-reason message.',
        );
    }

    public function testMissingStubIsReportedAsError(): void
    {
        // provider directory exists (containment check passes) but the stub file itself is never created.
        mkdir("{$this->tempDir}/vendor/pkg/name", 0777, recursive: true);

        $this->writeLockEntry('config/params.php', "stub\n", 'replace');

        $controller = $this->makeController(force: false);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'Stub not found',
            $controller->stderrBuffer,
            'A missing stub must emit a clear "Stub not found" error so broken providers fail loudly.',
        );
    }

    public function testOptionsExposesForceAndProviderFlags(): void
    {
        $options = $this->makeController(force: false)->options('index');

        self::assertContains(
            'force',
            $options,
            "'reapply' command must expose the '--force' flag in its options() list so Yii's console router accepts it.",
        );
        self::assertContains(
            'provider',
            $options,
            "'reapply' command must expose the '--provider' flag in its options() list so Yii's console router accepts "
            . 'it.',
        );
    }

    public function testPostWriteHashFailureSkipsLockUpdate(): void
    {
        $stub = "stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'replace');

        /**
         * default-mock `is_readable` to always report false; both the pre-write and post-write hash checks fail, and
         * the `--force` flag bypasses the pre-write one, so the test specifically exercises the post-write branch.
         */
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'is_readable',
            [],
            false,
            default: true,
        );

        $controller = $this->makeController(force: true);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'Could not hash written file',
            $controller->stderrBuffer,
            'A post-write hash failure must be reported without crashing the whole reapply loop.',
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

    public function testPreserveModeRewritesWhenDestinationIsMissing(): void
    {
        /**
         * Preserve mode only skips when the destination ALREADY exists. When the destination is gone (user deleted it),
         * reapply must re-create it from the stub this exercises the "preserve + file absent" branch.
         */
        $stub = "initial stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'preserve');

        $controller = $this->makeController(force: false);

        $controller->actionIndex('config/params.php');

        self::assertFileExists(
            "{$this->tempDir}/config/params.php",
            'Preserve mode must re-materialize the destination when the file is missing on disk.',
        );
    }

    public function testPreserveModeWithForceOverwritesDestination(): void
    {
        $stub = "fresh stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'preserve');

        $destination = "{$this->tempDir}/config/params.php";

        mkdir(dirname($destination), 0777, recursive: true);
        file_put_contents($destination, "user kept\n");

        $controller = $this->makeController(force: true);

        $controller->actionIndex('config/params.php');

        self::assertSame(
            $stub,
            file_get_contents($destination),
            "'--force' must override preserve-mode protection and rewrite the destination with the current stub.",
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

    public function testReappliesSpecificFileWhenFilterMatches(): void
    {
        $stub = "stub A\n";

        $this->seedProviderStub('stubs/a.txt', $stub);
        $this->seedProviderStub('stubs/b.txt', 'stub B');
        $this->writeLockEntry('a.txt', 'old A', 'replace');
        $this->writeLockEntry('b.txt', 'old B', 'replace');

        $controller = $this->makeController(force: true);

        // passing a specific file path narrows the reapply to that single destination.
        $controller->actionIndex('a.txt');

        self::assertFileExists(
            "{$this->tempDir}/a.txt",
            'The targeted file must be reapplied.',
        );
        self::assertFileDoesNotExist(
            "{$this->tempDir}/b.txt",
            'Files outside the file filter must not be touched.',
        );
    }

    public function testRejectsLockEntryWithUnsafeDestination(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    // path traversal in the recorded destination triggers the PathValidator reject.
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        mkdir("{$this->tempDir}/vendor/pkg/name/stubs", 0777, recursive: true);
        file_put_contents("{$this->tempDir}/vendor/pkg/name/stubs/escape.php", 'content');

        $controller = $this->makeController(force: false);

        $controller->actionIndex();

        self::assertStringContainsString(
            'Unsafe lock entry',
            $controller->stderrBuffer,
            'Unsafe destinations recorded in the lock must be rejected with a clear error message before any I/O.',
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

    public function testStubReadFailureIsReportedAsError(): void
    {
        $stub = "stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'replace');

        /**
         * default-mock `file_get_contents` to always return false the only call in `Commands` is the stub read, so
         * this deterministically forces the "Could not read stub" branch on any platform.
         */
        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'file_get_contents',
            [],
            false,
            default: true,
        );

        $controller = $this->makeController(force: true);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'Could not read stub',
            $controller->stderrBuffer,
            "An unreadable stub must emit a 'Could not read stub' skip message rather than crashing the command.",
        );
    }

    public function testWriteFailureIsReportedAsError(): void
    {
        $stub = "stub\n";

        $this->seedProviderStub('stubs/config/params.php', $stub);
        $this->writeLockEntry('config/params.php', $stub, 'replace');

        // default-mock `file_put_contents` to always return false; controller must surface "Could not write" error.
        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'file_put_contents',
            [],
            false,
            default: true,
        );

        $controller = $this->makeController(force: true);

        $controller->actionIndex('config/params.php');

        self::assertStringContainsString(
            'Could not write',
            $controller->stderrBuffer,
            'A failed destination write must be reported as a skip-with-reason without aborting subsequent entries.',
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
