<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Commands\DiffController;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\DiffControllerSpy;

/**
 * Unit tests for {@see DiffController} diff computation via {@see DiffController::buildDiff()}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class DiffControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testActionIndexAnnouncesNoDifferencesWhenFilesAreIdentical(): void
    {
        $this->seedStubAndDestination(
            destination: 'config/params.php',
            content: "<?php return [];\n",
        );

        $this->writeLockEntry('config/params.php');
        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Identical stub and destination must result in a success exit code.',
        );
        self::assertStringContainsString(
            'No differences found.',
            $spy->stdoutBuffer,
            "Identical content must produce the explicit 'no differences' message.",
        );
    }

    public function testActionIndexEmitsDiffWhenFilesDiffer(): void
    {
        $this->seedStub('config/params.php', "stub\n");

        $this->writeDestination('config/params.php', "user\n");
        $this->writeLockEntry('config/params.php');
        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'A valid diff computation must exit with OK even when the diff is non-empty.',
        );
        self::assertStringContainsString(
            '- stub',
            $spy->stdoutBuffer,
            'Lines present only in the stub must be reported as removed in the diff output.',
        );
        self::assertStringContainsString(
            '+ user',
            $spy->stdoutBuffer,
            'Lines present only on disk must be reported as added in the diff output.',
        );
    }

    public function testActionIndexEmitsWarningWhenLockProviderPathEscapesVendor(): void
    {
        $this->seedStub('config/params.php', "stub\n");

        // override providers entry so PathResolver's containment check fails and the warning branch runs.
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
                        'hash' => 'sha256:unused',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/config/params.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $spy = $this->makeSpy();

        $spy->actionIndex('config/params.php');

        self::assertStringContainsString(
            'resolves outside vendor dir',
            $spy->stderrBuffer,
            'A lock-recorded provider path that escapes vendor must raise a visible warning before falling back to '
            . 'the default vendor-relative path.',
        );
    }

    public function testActionIndexReturnsErrorWhenCurrentFileReadFails(): void
    {
        $this->seedStubAndDestination('config/params.php', "content\n");

        $this->writeLockEntry('config/params.php');

        /**
         * `default: true` makes the mocker return `false` for every call to `file_get_contents` in this namespace,
         * regardless of the arguments. This keeps the test robust to path-separator differences between Linux and
         * Windows (mixed backslash/forward-slash paths would otherwise break argument matching).
         */
        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'file_get_contents',
            [],
            false,
            default: true,
        );

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'Inability to read the current on-disk file must surface as a non-OK exit code.',
        );
        self::assertStringContainsString(
            'Could not read current file',
            $spy->stderrBuffer,
            'User must see a clear error when the on-disk destination cannot be read.',
        );
    }

    public function testActionIndexReturnsErrorWhenFileNotTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('missing.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'Requesting a diff for a destination absent from the lock must return a non-OK exit code.',
        );
        self::assertStringContainsString(
            'is not tracked in scaffold-lock.json',
            $spy->stderrBuffer,
            'User must see a clear error when the requested file is not recorded in the lock.',
        );
    }

    public function testActionIndexReturnsErrorWhenLockEntryHasUnsafeDestination(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    '../escape.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('../escape.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'A lock entry whose destination escapes the project root must be rejected before any file I/O.',
        );
        self::assertStringContainsString(
            'Unsafe lock entry',
            $spy->stderrBuffer,
            'The diff command must reuse the PathValidator message so users recognize the failure category.',
        );
    }

    public function testActionIndexReturnsErrorWhenStubMissing(): void
    {
        /**
         * Provider path resolves inside vendor (required for the containment check to pass) but the stub file itself is
         * never created, forcing the "Stub not found" branch.
         */
        mkdir("{$this->tempDir}/vendor/pkg/name", 0777, recursive: true);

        $this->writeLockEntry('config/params.php');
        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'A missing stub must surface as an error so broken providers fail loudly.',
        );
        self::assertStringContainsString(
            'Stub not found',
            $spy->stderrBuffer,
            'User must see a "Stub not found" error pointing to the missing path.',
        );
    }

    public function testActionIndexShowsFullStubAsAddedWhenDestinationIsAbsent(): void
    {
        $this->seedStub('config/params.php', "line1\nline2\n");

        $this->writeLockEntry('config/params.php');
        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex('config/params.php');

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Diffing a tracked destination that has been deleted must still succeed.',
        );
        self::assertStringContainsString(
            '- line1',
            $spy->stdoutBuffer,
            'When the destination is absent, every stub line must appear as a removed line in the diff.',
        );
    }

    public function testBuildDiffExactOutputFormat(): void
    {
        // trailing newlines on every line force Differ to emit lines ending with `\n`, so rtrim is observable.
        $diff = $this->makeController()->buildDiff("a\nb\n", "a\nc\n");

        self::assertSame(
            '  a' . PHP_EOL . '- b' . PHP_EOL . '+ c' . PHP_EOL,
            $diff,
            "Diff output must strip per-line newlines via 'rtrim', join with PHP_EOL, and end with a single PHP_EOL.",
        );
    }

    public function testBuildDiffNormalizesCrlfAndLfAsIdentical(): void
    {
        self::assertSame(
            '',
            $this->makeController()->buildDiff("line1\r\nline2", "line1\nline2"),
            'CRLF and LF versions of the same content should be treated as identical.',
        );
    }

    public function testBuildDiffPreservesUnchangedLinesWithIndent(): void
    {
        $diff = $this->makeController()->buildDiff("same\nchanged", "same\ndifferent");

        self::assertStringContainsString(
            '  same',
            $diff,
            'Unchanged lines should be prefixed with two spaces to indicate no change.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalContent(): void
    {
        self::assertSame(
            '',
            $this->makeController()->buildDiff('line1', 'line1'),
            'Identical content should result in an empty diff.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalMultilineContent(): void
    {
        $content = "line1\nline2\nline3";

        self::assertSame(
            '',
            $this->makeController()->buildDiff($content, $content),
            'Identical multiline content should result in an empty diff.',
        );
    }

    public function testBuildDiffShowsAddedLines(): void
    {
        $diff = $this->makeController()->buildDiff('line1', "line1\nnewline");

        self::assertStringContainsString(
            '+ newline',
            $diff,
            'Added lines should be prefixed with a plus sign.',
        );
        self::assertStringNotContainsString(
            '- newline',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
    }

    public function testBuildDiffShowsBothSidesForModifiedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\noriginal", "line1\nmodified");

        self::assertStringContainsString(
            '- original',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
        self::assertStringContainsString(
            '+ modified',
            $diff,
            'Added lines should be prefixed with a plus sign.',
        );
    }

    public function testBuildDiffShowsRemovedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\nline2", 'line1');

        self::assertStringContainsString(
            '- line2',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
        self::assertStringNotContainsString(
            '+ line2',
            $diff,
            'Added lines should be prefixed with a plus sign.',
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

    private function makeController(): DiffController
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new DiffController('diff', $module);
    }

    private function makeSpy(): DiffControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new DiffControllerSpy('diff', $module);
    }

    private function seedStub(string $destination, string $content, string $providerName = 'pkg/name'): void
    {
        $stubPath = "{$this->tempDir}/vendor/{$providerName}/stubs/{$destination}";

        mkdir(dirname($stubPath), 0777, recursive: true);

        file_put_contents($stubPath, $content);
    }

    private function seedStubAndDestination(string $destination, string $content): void
    {
        $this->seedStub($destination, $content);
        $this->writeDestination($destination, $content);
    }

    private function writeDestination(string $destination, string $content): void
    {
        $path = "{$this->tempDir}/{$destination}";

        mkdir(dirname($path), 0777, recursive: true);

        file_put_contents($path, $content);
    }

    private function writeLockEntry(string $destination, string $providerName = 'pkg/name'): void
    {
        $lock = new LockFile($this->tempDir);

        $data = $lock->read();

        $data['providers'][$providerName] = [
            'version' => '1.0.0',
            'path' => "vendor/{$providerName}",
        ];
        $data['files'][$destination] = [
            'hash' => 'sha256:unused',
            'provider' => $providerName,
            'source' => "stubs/{$destination}",
            'mode' => 'replace',
        ];

        $lock->write($data);
    }
}
