<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\{FileMapping, FileMode, ManifestExpander};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function file_put_contents;

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
            providerName: 'pkg/example'
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
}
