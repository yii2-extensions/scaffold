<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\{FileMapping, FileMode, ManifestExpander};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function chmod;
use function file_put_contents;
use function function_exists;
use function posix_geteuid;

/**
 * Unit tests for {@see ManifestExpander} covering directory walking, exclusions, and mode resolution.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class ManifestExpanderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testExpandAppliesDefaultExcludesDuringDirectoryWalk(): void
    {
        $this->seedFile('src/controllers/SiteController.php');
        $this->seedFile('src/tests/SomeTest.php');
        $this->seedFile('composer.json');
        $this->seedFile('runtime/cache/x.log');

        $mappings = $this->expand(
            [
                'copy' => ['.'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertContains(
            'src/controllers/SiteController.php',
            $this->destinations($mappings),
            "Walking '.' must emit the controller file as a destination.",
        );
        self::assertNotContains(
            'composer.json',
            $this->destinations($mappings),
            "Default excludes must hide 'composer.json' when '.' is walked.",
        );
        self::assertNotContains(
            'runtime/cache/x.log',
            $this->destinations($mappings),
            "Default excludes must hide 'runtime/**' contents when the directory is walked.",
        );
    }

    public function testExpandAppliesUserExcludesOnTopOfDefaults(): void
    {
        $this->seedFile('src/controllers/SiteController.php');
        $this->seedFile('src/controllers/Disabled.php');

        $mappings = $this->expand(
            [
                'copy' => ['src'],
                'exclude' => ['src/controllers/Disabled.php'],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['src/controllers/SiteController.php'],
            $this->destinations($mappings),
            "User-declared 'exclude[]' patterns must filter walked paths.",
        );
    }

    public function testExpandAttributesEveryMappingToTheProviderName(): void
    {
        $this->seedFile('src/Foo.php');

        $mappings = $this->expand(
            [
                'copy' => ['src'],
                'exclude' => [],
                'modes' => [],
            ],
            providerName: 'pkg/example',
        );

        self::assertNotEmpty(
            $mappings,
            'Seed fixture must produce at least one mapping.',
        );

        foreach ($mappings as $mapping) {
            self::assertSame(
                'pkg/example',
                $mapping->providerName,
                'Every FileMapping must carry the provider name for lock-file provenance.',
            );
        }
    }

    public function testExpandContinuesToNextCopyEntryAfterDeduplicatingRepeatedFile(): void
    {
        // Listing the same file twice seeds the dedup map on iter 1 and routes iter 2 through the 'isset' branch.
        // A third entry after the duplicate proves the outer loop resumes: replacing 'continue' with 'break' on the
        // dedup branch would exit the foreach and strip 'other.txt' from the output.
        $this->seedFile('yii');
        $this->seedFile('other.txt');

        $mappings = $this->expand(
            [
                'copy' => ['yii', 'yii', 'other.txt'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertContains(
            'other.txt',
            $this->destinations($mappings),
            "After a duplicate file entry hits the dedup branch, the outer loop must 'continue' so subsequent copy "
            . "entries still process; replacing the 'continue' with 'break' would drop 'other.txt' from the output.",
        );
    }

    public function testExpandContinuesToNextCopyEntryAfterWalkingDirectory(): void
    {
        // Two sibling directories in 'copy[]' prove the outer loop resumes after a successful dir walk: replacing the
        // trailing 'continue' with 'break' would exit the foreach after walking 'dir1' and strip 'dir2/b.txt' out.
        $this->seedFile('dir1/a.txt');
        $this->seedFile('dir2/b.txt');

        $mappings = $this->expand(
            [
                'copy' => ['dir1', 'dir2'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertContains(
            'dir2/b.txt',
            $this->destinations($mappings),
            "After walking the first directory, the outer loop must 'continue' so the next entry is walked too; "
            . "replacing the 'continue' with 'break' would drop 'dir2/b.txt' from the output.",
        );
    }

    public function testExpandDedupContinueDoesNotBreakDirectoryWalk(): void
    {
        /*
         * Alphabetic iteration guarantees 'a.php' is processed first by the walk. Pre-adding it as an explicit file
         * entry in 'copy[]' seeds the dedup map for that key; the walk then hits 'a.php', finds it already seen,
         * and must 'continue' to iterate 'b.php' / 'c.php'. Replacing 'continue' with 'break' would halt the walk
         * and strip both follow-up destinations from the output.
         */
        $this->seedFile('a.php');
        $this->seedFile('b.php');
        $this->seedFile('c.php');

        $mappings = $this->expand(
            [
                'copy' => ['a.php', '.'],
                'exclude' => [],
                'modes' => [],
            ],
        );
        $destinations = $this->destinations($mappings);

        self::assertContains(
            'b.php',
            $destinations,
            "After 'a.php' hits the dedup branch, the walk must 'continue' so 'b.php' still lands in the output.",
        );
        self::assertContains(
            'c.php',
            $destinations,
            "After 'a.php' hits the dedup branch, the walk must 'continue' so 'c.php' still lands in the output.",
        );
    }

    public function testExpandDeduplicatesWhenFileIsReachableByBothFileAndDirectoryEntries(): void
    {
        $this->seedFile('yii');

        $mappings = $this->expand(
            [
                'copy' => ['yii', '.'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        $destinations = $this->destinations($mappings);

        self::assertSame(
            1,
            array_count_values($destinations)['yii'] ?? 0,
            'A destination reachable from both an explicit file entry and a directory walk must appear only once.',
        );
    }

    public function testExpandEmitsEntriesForEachFileInsideCopyDirectory(): void
    {
        $this->seedFile('src/controllers/SiteController.php');
        $this->seedFile('src/models/User.php');

        $mappings = $this->expand(
            [
                'copy' => ['src'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['src/controllers/SiteController.php', 'src/models/User.php'],
            $this->destinations($mappings),
            "Walking 'src' must emit every file beneath it as a destination.",
        );
    }

    public function testExpandNormalisesTrailingSeparatorInCopyDirectoryEntry(): void
    {
        // A trailing '/' on a dir entry used to make the walk skip one character from each filename and emit destinations
        // like 'src//ile.php'. The 'rtrim' in both the dir-prefix and the absolute-prefix derivation keep the output
        // identical whether the provider writes 'src' or 'src/'.
        $this->seedFile('src/controllers/SiteController.php');

        $mappings = $this->expand(
            [
                'copy' => ['src/'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['src/controllers/SiteController.php'],
            $this->destinations($mappings),
            "A trailing '/' on a 'copy' directory entry must produce the same destinations as the un-slashed form; the "
            . 'expander rtrims trailing separators before deriving paths so no character is dropped and no double slash '
            . 'appears.',
        );
    }

    public function testExpandPrunesDefaultExcludedDirectoriesWithoutDescendingIntoThem(): void
    {
        // Behavioral proof of descent pruning on POSIX non-root: 'chmod 0' on 'vendor' makes 'opendir()' throw
        // 'UnexpectedValueException', so the walk only completes when the recursive filter rejects descent BEFORE
        // the iterator attempts to read the subtree. Windows ignores POSIX mode bits and root bypasses them, so on
        // those environments the chmod is a no-op; the assertion still holds there via file-level exclude matching,
        // but the descent-prevention proof is only meaningful when the platform and user honour mode bits.
        $this->skipUnlessChmodDeniesDirectoryReads();
        $this->seedFile('src/main.php');
        $this->seedFile('vendor/pkg/bogus.php');

        chmod("{$this->tempDir}/vendor", 0);

        try {
            $mappings = $this->expand(
                [
                    'copy' => ['.'],
                    'exclude' => [],
                    'modes' => [],
                ],
            );
        } finally {
            chmod("{$this->tempDir}/vendor", 0755);
        }

        self::assertSame(
            ['src/main.php'],
            $this->destinations($mappings),
            "Default-excluded directories must be pruned BEFORE descent; otherwise an unreadable 'vendor/' would "
            . 'derail the walk instead of being silently skipped.',
        );
    }

    public function testExpandPrunesUserExcludedDirectoriesWithoutDescendingIntoThem(): void
    {
        // Same behavioral proof as the default-exclude companion: requires a platform and user that honour POSIX
        // mode bits so 'chmod 0' actually denies reads. On Windows or root the chmod is a no-op and this test
        // degrades to a file-level exclusion check; the guard below skips it explicitly in those cases.
        $this->skipUnlessChmodDeniesDirectoryReads();
        $this->seedFile('src/main.php');
        $this->seedFile('secrets/keys.txt');

        chmod("{$this->tempDir}/secrets", 0);

        try {
            $mappings = $this->expand(
                [
                    'copy' => ['.'],
                    'exclude' => ['secrets/**'],
                    'modes' => [],
                ],
            );
        } finally {
            chmod("{$this->tempDir}/secrets", 0755);
        }

        self::assertSame(
            ['src/main.php'],
            $this->destinations($mappings),
            "User-exclude patterns of form 'X/**' must prune the directory BEFORE descent; otherwise an unreadable "
            . "'secrets/' subtree would derail the walk instead of being silently skipped.",
        );
    }

    public function testExpandResolvesExactMatchInModesAndShortCircuitsGlobLoop(): void
    {
        // Declaration order matters: the broader glob 'config/*.php' is declared BEFORE the exact path; the default
        // code path short-circuits via 'isset($modes[$relative])' returning Replace. Removing that early return
        // would fall through to the foreach and let the first-declared glob win with Preserve, a distinct mode.
        $this->seedFile('config/params.php');

        $mappings = $this->expand(
            [
                'copy' => ['config/params.php'],
                'exclude' => [],
                'modes' => [
                    'config/*.php' => FileMode::Preserve,
                    'config/params.php' => FileMode::Replace,
                ],
            ],
        );

        self::assertSame(
            FileMode::Replace,
            $mappings[0]->mode ?? null,
            'Exact path match must win over a preceding-but-also-matching glob; removing the early return from the '
            . "'isset' check flips the result to the glob's mode.",
        );
    }

    public function testExpandResolvesModesByExactPathThenByGlobThenDefaultsToReplace(): void
    {
        $this->seedFile('config/web.php');
        $this->seedFile('config/params.php');
        $this->seedFile('src/controllers/SiteController.php');

        $mappings = $this->expand(
            [
                'copy' => [
                    'config',
                    'src',
                ],
                'exclude' => [],
                'modes' => [
                    'config/params.php' => FileMode::Preserve,
                    'config/*.php' => FileMode::Append,
                ],
            ],
        );

        $modesByDestination = [];

        foreach ($mappings as $mapping) {
            $modesByDestination[$mapping->destination] = $mapping->mode;
        }

        self::assertSame(
            FileMode::Preserve,
            $modesByDestination['config/params.php'] ?? null,
            'Exact path match must win over a glob pattern that would also match.',
        );
        self::assertSame(
            FileMode::Append,
            $modesByDestination['config/web.php'] ?? null,
            "Glob 'config/*.php' must resolve 'config/web.php' after the exact-path lookup misses.",
        );
        self::assertSame(
            FileMode::Replace,
            $modesByDestination['src/controllers/SiteController.php'] ?? null,
            "A destination with no match in 'modes{}' must default to 'FileMode::Replace'.",
        );
    }

    public function testExpandReturnsWalkResultsInAlphabeticallySortedOrder(): void
    {
        // Seed files in reverse-alphabetic creation order so the directory's dirent order likely reverses the
        // desired sequence; the walk must still produce alphabetic ordering, which only holds when 'sort' runs.
        $this->seedFile('zzz.php');
        $this->seedFile('mmm.php');
        $this->seedFile('aaa.php');

        $mappings = $this->expand(
            [
                'copy' => ['.'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        $rawOrder = array_map(static fn(FileMapping $mapping): string => $mapping->destination, $mappings);

        self::assertSame(
            ['aaa.php', 'mmm.php', 'zzz.php'],
            $rawOrder,
            "Walk results must be sorted alphabetically so 'scaffold-lock.json' stays deterministic; removing the "
            . "'sort()' call would leak filesystem-dependent ordering into the lock and make git diffs noisy.",
        );
    }

    public function testExpandSkipsExcludedFilesAndContinuesToRemainingEntries(): void
    {
        // An exclude pattern that drops one file while keeping its sibling proves the walk resumes after the skip:
        // replacing the 'continue' in the exclude branch with 'break' would exit the foreach on the first excluded
        // entry and strip the surviving sibling from the output.
        $this->seedFile('src/drop.txt');
        $this->seedFile('src/keep.txt');

        $mappings = $this->expand(
            [
                'copy' => ['src'],
                'exclude' => ['src/drop.txt'],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['src/keep.txt'],
            $this->destinations($mappings),
            "After an excluded file hits the skip branch, the walk must 'continue' so remaining files still process; "
            . "replacing the 'continue' with 'break' would drop 'src/keep.txt' from the output.",
        );
    }

    public function testExpandThrowsWhenCopyEntryDoesNotExistOnDisk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->expand(
            [
                'copy' => ['missing-dir'],
                'exclude' => [],
                'modes' => [],
            ],
        );
    }

    public function testExpandTreatsExplicitFileInCopyAsPassthroughBypassingDefaultExcludes(): void
    {
        $this->seedFile('runtime/.gitignore');

        $mappings = $this->expand(
            [
                'copy' => ['runtime/.gitignore'],
                'exclude' => [],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['runtime/.gitignore'],
            $this->destinations($mappings),
            'An explicit file entry under a default-excluded directory must still ship because explicit entries bypass '
            . 'the default excludes.',
        );
    }

    public function testExpandUserExcludeGlobMatchesMultipleFiles(): void
    {
        $this->seedFile('src/a.txt');
        $this->seedFile('src/b.txt');
        $this->seedFile('src/c.php');

        $mappings = $this->expand(
            [
                'copy' => ['src'],
                'exclude' => ['src/*.txt'],
                'modes' => [],
            ],
        );

        self::assertSame(
            ['src/c.php'],
            $this->destinations($mappings),
            'User exclude globs must filter by pattern, not just literal path.',
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

    /**
     * Extracts the destination paths from a list of file mappings.
     *
     * @param list<FileMapping> $mappings
     *
     * @return list<string>
     */
    private function destinations(array $mappings): array
    {
        $destinations = array_map(static fn(FileMapping $mapping): string => $mapping->destination, $mappings);

        sort($destinations);

        return $destinations;
    }

    /**
     * Invokes {@see ManifestExpander::expand()} against the test's temp directory.
     *
     * @param array{copy: list<string>, exclude: list<string>, modes: array<string, FileMode>} $manifest
     *
     * @return list<FileMapping>
     */
    private function expand(array $manifest, string $providerName = 'pkg/test'): array
    {
        return (new ManifestExpander())->expand($manifest, $this->tempDir, $providerName);
    }

    /**
     * Writes an empty file at `$relative` under the temp directory, creating intermediate directories as needed.
     */
    private function seedFile(string $relative): void
    {
        $absolute = "{$this->tempDir}/{$relative}";

        $this->ensureTestDirectory(dirname($absolute));

        file_put_contents($absolute, '');
    }

    /**
     * Skips the current test when the platform or user cannot make `chmod(dir, 0)` deny directory reads.
     *
     * Windows ignores POSIX mode bits entirely, and the root user bypasses them, so a `chmod 0` guard degrades to a
     * no-op in either environment; the tests that rely on it for descent-prevention proof cannot produce a signal
     * there and must be skipped rather than silently pass.
     */
    private function skipUnlessChmodDeniesDirectoryReads(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('chmod mode bits are not enforced on Windows.');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root bypasses chmod mode bits.');
        }
    }
}
