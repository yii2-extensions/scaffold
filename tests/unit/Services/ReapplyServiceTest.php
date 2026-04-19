<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Services\ReapplyService;
use yii\scaffold\tests\support\{BufferedOutputWriter, TempDirectoryTrait};

/**
 * Unit tests for {@see ReapplyService} covering single-file, provider-filter, force, and error-path branches.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('services')]
final class ReapplyServiceTest extends TestCase
{
    use TempDirectoryTrait;

    public function testAppendAndPrependModesCannotBeReapplied(): void
    {
        $this->seedTracked('.env.dist', "A=1\n", "A=1\n", mode: 'append');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '.env.dist',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'cannot be safely reapplied',
            $out->stdoutBuffer,
            "When a file is in 'append' or 'prepend' mode, it cannot be safely reapplied.",
        );
    }

    public function testEmitsWarningWhenProviderPathEscapesVendor(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        $lock = new LockFile($this->tempDir);

        $data = $lock->read();

        $data['providers'] = [
            'pkg/name' => [
                'version' => '0.1.x-dev',
                'path' => '../outside-vendor',
            ],
        ];

        $lock->write($data);

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'resolves outside vendor dir',
            $out->stderrBuffer,
            'When a provider path escapes the vendor directory, a warning must be emitted.',
        );
    }

    public function testEnsureDirectoryFailureIsReportedAsError(): void
    {
        $this->seedTracked('deep/config/params.php', "stub\n", "current\n", lockHashOf: "stub\n");

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'mkdir',
            [],
            false,
            default: true,
        );
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'is_dir',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'deep/config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not create directory',
            $out->stderrBuffer,
            'When directory creation fails, an error must be reported.',
        );
    }

    public function testFileFilterSkipsNonMatchingEntriesWhileProcessingTheTarget(): void
    {
        $this->seedTracked('a.txt', "a\n", "a\n", provider: 'pkg/a');
        $this->seedTracked('b.txt', "b\n", "b\n", provider: 'pkg/a');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'b.txt',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Reapplied "b.txt"',
            $out->stdoutBuffer,
            'When a specific file filter is supplied, only matching lock entries must be reapplied.',
        );
        self::assertStringNotContainsString(
            'Reapplied "a.txt"',
            $out->stdoutBuffer,
            'When a specific file filter is supplied, non-matching lock entries must be skipped.',
        );
    }

    public function testForceOverwritesUserModifiedFileAndUpdatesLockHash(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "user-edited\n", lockHashOf: "stub\n");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Reapplied',
            $out->stdoutBuffer,
            "When 'force=true', the stub content must overwrite the user's content.",
        );
        self::assertSame(
            "stub\n",
            (string) file_get_contents($this->tempDir . '/config/params.php'),
            "With 'force=true' the stub content must overwrite the user's content.",
        );

        $data = (new LockFile($this->tempDir))->read();

        $entry = $data['files']['config/params.php'] ?? null;

        self::assertNotNull(
            $entry,
            "The entry for 'config/params.php' must survive the reapply.",
        );
        self::assertSame(
            'sha256:' . hash('sha256', "stub\n"),
            $entry['hash'],
            'Lock hash must be updated to reflect the newly written content.',
        );
    }

    public function testHashFailureBeforeWriteIsReported(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "user-edited\n", lockHashOf: "stub\n");

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'hash_file',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not hash',
            $out->stderrBuffer,
            'When hashing fails before writing, an error must be reported.',
        );
    }

    public function testMissingStubIsReportedAsError(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        unlink("{$this->tempDir}/vendor/pkg/name/stubs/config/params.php");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Stub not found',
            $out->stderrBuffer,
            'When the stub is missing, an error must be reported.',
        );
    }

    public function testPostWriteHashFailureSkipsLockUpdate(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'hash_file',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not hash',
            $out->stderrBuffer,
            'When hashing fails after writing, an error must be reported.',
        );
    }

    public function testPreserveModeIsSkippedWithoutForce(): void
    {
        $this->seedTracked('config/web.php', "stub\n", "current\n", mode: 'preserve', lockHashOf: "stub\n");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/web.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'mode "preserve"',
            $out->stdoutBuffer,
            'When preserve mode is active, a message must be displayed.',
        );
        self::assertSame(
            "current\n",
            (string) file_get_contents($this->tempDir . '/config/web.php'),
            'Preserve mode must leave the on-disk content untouched without force.',
        );
    }

    public function testPreserveModeRewritesWhenDestinationIsMissing(): void
    {
        $this->seedTracked('config/web.php', "stub\n", "stub\n", mode: 'preserve');

        unlink($this->tempDir . '/config/web.php');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/web.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertFileExists(
            "{$this->tempDir}/config/web.php",
            'Preserve mode must rewrite when the destination is missing, even without force.',
        );
    }

    public function testProviderFilterOnlyProcessesMatchingEntries(): void
    {
        $this->seedTracked('a.txt', "a\n", "a\n", provider: 'pkg/a');
        $this->seedTracked('b.txt', "b\n", "b\n", provider: 'pkg/b');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: 'pkg/a',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Reapplied "a.txt"',
            $out->stdoutBuffer,
            'Only the matching provider entries must be processed.',
        );
        self::assertStringNotContainsString(
            'Reapplied "b.txt"',
            $out->stdoutBuffer,
            'Non-matching provider entries must be skipped.',
        );
    }

    public function testRejectsLockEntryWithUnsafeDestination(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
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

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Unsafe lock entry',
            $out->stderrBuffer,
            'Unsafe lock entries must be reported as errors.',
        );
    }

    public function testReturnsErrorWhenFilterMatchesNothing(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'missing.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertSame(
            1,
            $exitCode,
            'When no tracked files match the filter, the service must return a non-zero exit code.',
        );
        self::assertStringContainsString(
            'No tracked files matched',
            $out->stderrBuffer,
            'An error must be reported when no files match the filter.',
        );
    }

    public function testSkipsUserModifiedFileWithoutForce(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "user-edited\n", lockHashOf: "stub\n");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'user-modified',
            $out->stdoutBuffer,
            'When a file is user-modified and force is false, a message must be displayed.',
        );
        self::assertSame(
            "user-edited\n",
            (string) file_get_contents($this->tempDir . '/config/params.php'),
            'Without force the on-disk file must be preserved.',
        );
    }

    public function testStubReadFailureIsReportedAsError(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        MockerState::addCondition(
            'yii\\scaffold\\Services',
            'file_get_contents',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not read stub',
            $out->stderrBuffer,
            'An error must be reported when a stub cannot be read.',
        );
    }

    public function testWriteFailureIsReportedAsError(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        MockerState::addCondition(
            'yii\\scaffold\\Services',
            'file_put_contents',
            [],
            false,
            default: true,
        );
        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not write',
            $out->stderrBuffer,
            'An error must be reported when a file cannot be written.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function seedTracked(
        string $destination,
        string $stubContent,
        string $currentContent,
        string $mode = 'replace',
        string $provider = 'pkg/name',
        string|null $lockHashOf = null,
    ): void {
        $providerRoot = "{$this->tempDir}/vendor/{$provider}";

        $stubRelative = "stubs/$destination";
        $stubPath = "$providerRoot/$stubRelative";

        $this->ensureTestDirectory(dirname($stubPath));

        file_put_contents($stubPath, $stubContent);

        $destAbsolute = $this->tempDir . '/' . $destination;

        $this->ensureTestDirectory(dirname($destAbsolute));

        file_put_contents($destAbsolute, $currentContent);

        $hasher = new Hasher();

        $lockHash = $lockHashOf !== null
            ? 'sha256:' . hash('sha256', $lockHashOf)
            : $hasher->hash($destAbsolute);

        $lock = new LockFile($this->tempDir);

        $data = is_file("{$this->tempDir}/scaffold-lock.json") ? $lock->read() : ['providers' => [], 'files' => []];

        $data['providers'][$provider] = [
            'version' => '0.1.x-dev',
            'path' => "vendor/{$provider}",
        ];
        $data['files'][$destination] = [
            'hash' => $lockHash,
            'provider' => $provider,
            'source' => $stubRelative,
            'mode' => $mode,
        ];

        $lock->write($data);
    }
}
