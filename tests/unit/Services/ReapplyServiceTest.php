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
        $modes = ['append', 'prepend'];

        foreach ($modes as $mode) {
            $file = ".env.{$mode}.dist";

            $this->seedTracked($file, "A=1\n", "A=1\n", mode: $mode);

            $out = new BufferedOutputWriter();
            (new ReapplyService())->run(
                $this->tempDir,
                "{$this->tempDir}/vendor",
                file: $file,
                provider: '',
                force: false,
                out: $out,
            );

            self::assertStringContainsString(
                'cannot be safely reapplied',
                $out->stdoutBuffer,
                sprintf(
                    "When a file is in '%s' mode, it cannot be safely reapplied (the message must be emitted for "
                    . 'both append and prepend, not just one).',
                    $mode,
                ),
            );
            self::assertStringContainsString(
                sprintf('mode "%s"', $mode),
                $out->stdoutBuffer,
                sprintf(
                    "The diagnostic must name the actual mode ('%s') so users know which file-mapping entry "
                    . 'triggered the skip.',
                    $mode,
                ),
            );
        }
    }

    public function testAppendModeOutputEndsWithSinglePhpEolSuffix(): void
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

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'cannot be safely reapplied' notice must end with PHP_EOL so shells render it on its own line.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'cannot be safely reapplied' notice must not be prefixed with PHP_EOL.",
        );
    }

    public function testAppendPrependModeSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Seed an append entry first (cannot be reapplied) followed by a replace entry (reapplicable). The first
         * triggers the 'cannot be safely reapplied' continue-branch; the second must still be reapplied to verify the
         * loop continues rather than breaks.
         */
        $this->seedTracked('.env.dist', "A=1\n", "A=1\n", mode: 'append');
        $this->seedTracked('config/params.php', "stub\n", "stub\n", mode: 'replace');

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
            'cannot be safely reapplied',
            $out->stdoutBuffer,
            'The append entry must produce the non-reapplicable diagnostic.',
        );
        self::assertStringContainsString(
            'Reapplied "config/params.php"',
            $out->stdoutBuffer,
            "After the append skip, the foreach must 'continue' to the next entry; replacing 'continue' with 'break' "
            . "would leave 'config/params.php' unprocessed.",
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
        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'resolves outside vendor dir' warning must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'resolves outside vendor dir' warning must not start with PHP_EOL.",
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

    public function testEnsureDirectoryFailureSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Entry 1 lives under a deep nested destination where `mkdir` is mocked to fail. Entry 2 at the top level
         * already exists so 'ensureDirectory' is a no-op and the entry is reapplied, proving the loop continues.
         */
        $this->seedTracked('deep/config/params.php', "stub\n", "stub\n");
        $this->seedTracked('top.txt', "stub\n", "stub\n");

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
            ["{$this->tempDir}/deep/config"],
            false,
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
            'Could not create directory',
            $out->stderrBuffer,
            "The 'ensureDirectory' failure diagnostic must be emitted for the deep entry.",
        );
        self::assertStringContainsString(
            'Reapplied "top.txt"',
            $out->stdoutBuffer,
            "After the 'ensureDirectory' skip, the foreach must 'continue' to the next entry; replacing 'continue' "
            . "with 'break' leaves 'top.txt' unreapplied.",
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

    public function testHashFailureSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Seed two entries with existing destinations; mock 'hash_file' to fail only for the first destination's
         * path so the 'Could not hash "first..."' continue-branch fires. The second entry's hash succeeds and the
         * file (unchanged) gets reapplied, proving the loop kept iterating after the first skip.
         */
        $this->seedTracked('first.txt', "stub\n", "stub\n");
        $this->seedTracked('second.txt', "stub\n", "stub\n");

        // target the first destination exclusively so the mock does not leak into the second iteration's hash calls.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'hash_file',
            ['sha256', "{$this->tempDir}/first.txt", false, []],
            false,
        );

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'Could not hash "first.txt"',
            $out->stderrBuffer,
            "The hash-failure diagnostic must be emitted for 'first.txt'.",
        );
        self::assertStringContainsString(
            'Reapplied "second.txt"',
            $out->stdoutBuffer,
            "After the 'Could not hash' skip, the foreach must 'continue' so 'second.txt' is still reapplied; "
            . "replacing 'continue' with 'break' aborts the scan prematurely.",
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

    public function testMissingStubSkipContinuesIterationToNextReapplicableEntry(): void
    {
        $this->seedTracked('first.txt', "stub\n", "stub\n");
        unlink("{$this->tempDir}/vendor/pkg/name/stubs/first.txt");

        $this->seedTracked('second.txt', "stub\n", "stub\n");

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
            'Stub not found',
            $out->stderrBuffer,
            'The missing stub must be reported as an error.',
        );
        self::assertStringContainsString(
            'Reapplied "second.txt"',
            $out->stdoutBuffer,
            "After 'Stub not found' the foreach must 'continue' to the next entry; replacing 'continue' with 'break' "
            . "stops iteration before 'second.txt' can be reapplied.",
        );
    }

    public function testNoMatchErrorEndsWithSinglePhpEolSuffix(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'missing.php',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'No tracked files matched' error must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'No tracked files matched' error must not be prefixed with PHP_EOL.",
        );
    }

    public function testPostWriteHashFailureSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Mock 'hash_file' to fail for both entries. Each reapply writes the stub but then fails on the
         * post-write hash; each iteration emits its own 'Could not hash written file' diagnostic naming the
         * specific destination. That proves the loop kept iterating after the first post-write hash failure.
         */
        $this->seedTracked('first.txt', "new-stub\n", "user-edited\n", lockHashOf: "user-edited\n");
        $this->seedTracked('second.txt', "new-stub\n", "user-edited\n", lockHashOf: "user-edited\n");

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
            file: '',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'first.txt',
            $out->stderrBuffer,
            "The first entry must emit its own 'Could not hash written file' diagnostic naming 'first.txt'.",
        );
        self::assertStringContainsString(
            'second.txt',
            $out->stderrBuffer,
            "After the first post-write hash failure, the foreach must 'continue' so the second entry is processed "
            . "and emits its own diagnostic naming 'second.txt'; replacing 'continue' with 'break' would silence it.",
        );
    }

    public function testPostWriteHashFailureSkipsLockUpdate(): void
    {
        /*+
         * Seed divergent stub vs current content with a known lock hash so that a successful run would update the hash
         * to 'sha256(stub)'; the failure path must leave the original hash untouched.
         */
        $this->seedTracked(
            'config/params.php',
            stubContent: "new-stub\n",
            currentContent: "user-edited\n",
            lockHashOf: "user-edited\n",
        );

        $originalLockHash = 'sha256:' . hash('sha256', "user-edited\n");

        self::assertSame(
            $originalLockHash,
            (new LockFile($this->tempDir))->read()['files']['config/params.php']['hash'] ?? null,
            'Pre-condition: lock hash must reflect the seeded current-content hash before the reapply runs.',
        );

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
            "Post-write 'hash_file' failure must surface via the 'Could not hash' diagnostic on stderr.",
        );
        self::assertSame(
            $originalLockHash,
            (new LockFile($this->tempDir))->read()['files']['config/params.php']['hash'] ?? null,
            'Post-condition: when the post-write hash throws, the lock entry must stay frozen at the pre-run hash '
            . '(skip-the-lock-update branch exercised).',
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
        self::assertSame(
            "stub\n",
            (string) file_get_contents("{$this->tempDir}/config/web.php"),
            'Preserve mode must restore the exact stub content (not an empty file) when the destination is missing.',
        );
    }

    public function testPreserveModeSkipNoticeEndsWithSinglePhpEolSuffix(): void
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

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'preserve' skip notice must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'preserve' skip notice must not be prefixed with PHP_EOL.",
        );
    }

    public function testPreserveSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Entry 1: preserve-mode file already on disk triggers the 'use --force to overwrite' skip-continue.
         * Entry 2: replace-mode file that must still be reapplied.
         */
        $this->seedTracked('config/web.php', "stub\n", "current\n", mode: 'preserve', lockHashOf: "stub\n");
        $this->seedTracked('config/params.php', "stub\n", "stub\n", mode: 'replace');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'uses mode "preserve"',
            $out->stdoutBuffer,
            'The preserve-mode entry must produce the skip diagnostic.',
        );
        self::assertStringContainsString(
            'Reapplied "config/params.php"',
            $out->stdoutBuffer,
            "After the preserve skip, the foreach must 'continue' so the next replace-mode entry is reapplied; "
            . "replacing 'continue' with 'break' aborts the scan prematurely.",
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

    public function testProviderFilterSkipOnFirstEntryContinuesIterationToNextMatchingEntry(): void
    {
        /*
         * Seed non-matching entry first so the provider-filter guard triggers 'continue'; the second entry matches
         * and must still be processed. Replacing 'continue' with 'break' would abort the loop before the matching
         * entry is reached.
         */
        $this->seedTracked('non-match.txt', "x\n", "x\n", provider: 'pkg/other');
        $this->seedTracked('match.txt', "y\n", "y\n", provider: 'pkg/target');

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: 'pkg/target',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'Reapplied "match.txt"',
            $out->stdoutBuffer,
            "After the provider-filter guard rejects a non-matching entry, the foreach must 'continue' to the next "
            . "entry; replacing 'continue' with 'break' would stop iteration and fail to process 'match.txt'.",
        );
    }

    public function testReappliedMessageEndsWithSinglePhpEolSuffix(): void
    {
        $this->seedTracked('config/params.php', "stub\n", "stub\n");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'config/params.php',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'Reapplied' confirmation must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'Reapplied' confirmation must not be prefixed with PHP_EOL.",
        );
    }

    public function testReapplySyncsSourceExecutableBitOntoDestination(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $this->seedTracked('yii', "#!/usr/bin/env php\n", "#!/usr/bin/env php\n");

        $stubPath = "{$this->tempDir}/vendor/pkg/name/stubs/yii";

        chmod($stubPath, 0755);

        $destination = "{$this->tempDir}/yii";

        chmod($destination, 0644);

        $oldUmask = umask(0022);

        try {
            $out = new BufferedOutputWriter();
            (new ReapplyService())->run(
                $this->tempDir,
                "{$this->tempDir}/vendor",
                file: 'yii',
                provider: '',
                force: true,
                out: $out,
            );

            self::assertSame(
                0755,
                fileperms($destination) & 0777,
                "After 'ReapplyService' rewrites the stub content, it must invoke 'syncPermissions' so the destination "
                . "inherits the source executable bit (0755 here); without 'syncPermissions' the file would keep the "
                . "umask-derived 0644 that 'file_put_contents' produces, breaking re-scaffolded CLI stubs like 'yii'.",
            );
        } finally {
            umask($oldUmask);
        }
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

    public function testRejectsLockEntryWithUnsafeSourceEvenWhenDestinationIsSafe(): void
    {
        /*
         * Seed two lock entries where destination is safe but source carries path traversal; removing the
         * 'validateSource' call in the try block would let the unsafe source through.
         */
        $providerRoot = "{$this->tempDir}/vendor/pkg/name";

        $this->ensureTestDirectory($providerRoot);

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => '../escape.php',
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
            "'validateSource' must catch the traversal source and emit the 'Unsafe lock entry' diagnostic; removing "
            . "the 'validateSource' call would let the unsafe source through since 'validateDestination' has nothing "
            . 'to reject for a safe destination.',
        );
    }

    public function testReturnsErrorWhenBothFileAndProviderFiltersAreSetButNothingMatches(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: 'missing.php',
            provider: 'pkg/missing',
            force: false,
            out: $out,
        );

        self::assertSame(
            1,
            $exitCode,
            'When both filters are set and nothing matches, the service must emit an error exit code; the guard is '
            . "'(file !== '' || provider !== '') && !anyMatched' and must short-circuit correctly even when both "
            . 'filters are non-empty.',
        );
        self::assertStringContainsString(
            'No tracked files matched',
            $out->stderrBuffer,
            'The no-match diagnostic must be emitted when both filters are present and the lock is empty.',
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

    public function testReturnsOkWhenNoFiltersAndLockIsEmpty(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertSame(
            0,
            $exitCode,
            "When no filter is supplied, running against an empty lock must be a silent no-op; the 'no match' error is "
            . "gated by 'file !== '' || provider !== ''' and must not fire when both filters are empty.",
        );
        self::assertStringNotContainsString(
            'No tracked files matched',
            $out->stderrBuffer,
            'Without any filter, the no-match diagnostic must stay silent; emitting it would confuse users who ran '
            . "'reapply' on a fresh project.",
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

    public function testStubNotFoundErrorEndsWithSinglePhpEolSuffix(): void
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

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Stub not found' error must end with PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stderrBuffer,
            "The 'Stub not found' error must not be prefixed with PHP_EOL.",
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

    public function testStubReadFailureSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Seed two entries; mock 'file_get_contents' to fail so both attempts return `false`. The first triggers
         * the 'Could not read stub' continue-branch; the second would also fail, but the test asserts that the
         * second diagnostic IS emitted, proving the loop kept iterating after the first skip.
         */
        $this->seedTracked('first.txt', "stub\n", "stub\n");
        $this->seedTracked('second.txt', "stub\n", "stub\n");

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
            file: '',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'first.txt',
            $out->stderrBuffer,
            "The first entry must emit its own 'Could not read stub' diagnostic naming 'first.txt'.",
        );
        self::assertStringContainsString(
            'second.txt',
            $out->stderrBuffer,
            "After the first stub-read failure, the foreach must 'continue' so the second entry is processed and "
            . "emits its own diagnostic naming 'second.txt'; replacing 'continue' with 'break' would silence it.",
        );
    }

    public function testSuccessfulReapplyUpdatesLockHashAndPreservesProvidersKey(): void
    {
        /*
         * Seed divergent stub vs current content with a lock hash pinned to the current content so a successful
         * force-reapply must flip the lock hash to 'sha256(stub)' and the write call must also carry the 'providers'
         * key untouched.
         */
        $this->seedTracked(
            'config/params.php',
            stubContent: "new-stub\n",
            currentContent: "user-edited\n",
            lockHashOf: "user-edited\n",
        );

        $originalHash = 'sha256:' . hash('sha256', "user-edited\n");
        $expectedHash = 'sha256:' . hash('sha256', "new-stub\n");

        self::assertSame(
            $originalHash,
            (new LockFile($this->tempDir))->read()['files']['config/params.php']['hash'] ?? null,
            'Pre-condition: lock hash must match the seeded current-content hash before the reapply runs.',
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

        $data = (new LockFile($this->tempDir))->read();

        self::assertArrayHasKey(
            'config/params.php',
            $data['files'],
            "The reapplied destination must remain tracked in 'files' after the lock rewrite.",
        );
        self::assertSame(
            $expectedHash,
            $data['files']['config/params.php']['hash'],
            'After a successful reapply the lock hash must reflect the newly written stub contents; the write '
            . 'branch must run (not be negated) and must carry the new hash.',
        );
        self::assertArrayHasKey(
            'pkg/name',
            $data['providers'],
            "The 'providers' key must survive the lock rewrite; dropping it on write would lose top-level provider "
            . 'metadata that downstream commands rely on for path resolution.',
        );

        $providerEntry = $data['providers']['pkg/name'] ?? null;

        self::assertIsArray(
            $providerEntry,
            "The 'pkg/name' provider entry must be a structured array carrying 'version' and 'path' fields.",
        );
        self::assertSame(
            'vendor/pkg/name',
            $providerEntry['path'] ?? null,
            'The preserved provider entry must retain its recorded install path.',
        );
    }

    public function testUnsafeLockEntrySkipContinuesIterationToNextFile(): void
    {
        /*
         * Seed two entries: the first has an unsafe destination ('../escape.php') that triggers the validator's
         * skip-and-continue branch; the second must still be reapplied, proving the loop 'continues' rather than
         * 'breaks'.
         */
        $unsafeDestination = '../escape.php';
        $validDestination = 'config/params.php';

        $providerRoot = "{$this->tempDir}/vendor/pkg/name";

        $this->ensureTestDirectory("{$providerRoot}/stubs/config");

        file_put_contents("{$providerRoot}/stubs/config/params.php", "stub\n");

        $this->ensureTestDirectory("{$this->tempDir}/config");

        file_put_contents("{$this->tempDir}/config/params.php", "stub\n");

        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/name' => [
                        'version' => '0.1.x-dev',
                        'path' => 'vendor/pkg/name',
                    ],
                ],
                'files' => [
                    $unsafeDestination => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'source' => 'stubs/escape.php',
                        'mode' => 'replace',
                    ],
                    $validDestination => [
                        'hash' => 'sha256:' . hash('sha256', "stub\n"),
                        'provider' => 'pkg/name',
                        'source' => 'stubs/config/params.php',
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
            'The unsafe entry must be surfaced via stderr as an error.',
        );
        self::assertStringContainsString(
            'Reapplied "config/params.php"',
            $out->stdoutBuffer,
            'After the unsafe entry triggers the skip branch, the foreach must keep iterating so the second (valid) '
            . "entry is still reapplied; replacing 'continue' with 'break' would stop the loop and fail this check.",
        );
    }

    public function testUserModifiedSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Entry 1: non-force, user-modified file triggers the 'is user-modified' skip-continue.
         * Entry 2: replace-mode in sync file that must still be reapplied.
         */
        $this->seedTracked('config/params.php', "stub\n", "user-edited\n", lockHashOf: "stub\n");
        $this->seedTracked('config/web.php', "stub\n", "stub\n");

        $out = new BufferedOutputWriter();
        (new ReapplyService())->run(
            $this->tempDir,
            "{$this->tempDir}/vendor",
            file: '',
            provider: '',
            force: false,
            out: $out,
        );

        self::assertStringContainsString(
            'user-modified',
            $out->stdoutBuffer,
            'The user-modified entry must produce the skip diagnostic.',
        );
        self::assertStringContainsString(
            'Reapplied "config/web.php"',
            $out->stdoutBuffer,
            "After 'is user-modified' the foreach must 'continue' so the next in-sync entry is reapplied; replacing "
            . "'continue' with 'break' aborts the scan prematurely.",
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

    public function testWriteFailureSkipContinuesIterationToNextReapplicableEntry(): void
    {
        /*
         * Mock 'file_put_contents' to fail for both entries. Each iteration must emit its own 'Could not write'
         * diagnostic naming the specific path; that proves the loop kept iterating after the first write failure.
         */
        $this->seedTracked('first.txt', "stub\n", "stub\n");
        $this->seedTracked('second.txt', "stub\n", "stub\n");

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
            file: '',
            provider: '',
            force: true,
            out: $out,
        );

        self::assertStringContainsString(
            'first.txt',
            $out->stderrBuffer,
            "The first entry must emit its own 'Could not write' diagnostic naming 'first.txt'.",
        );
        self::assertStringContainsString(
            'second.txt',
            $out->stderrBuffer,
            "After the first write failure, the foreach must 'continue' so the second entry is processed; replacing "
            . "'continue' with 'break' would silence the second diagnostic.",
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
