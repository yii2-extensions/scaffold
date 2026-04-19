<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see PathResolver} path joining, directory creation, and provider-root resolution.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class PathResolverTest extends TestCase
{
    use TempDirectoryTrait;

    public function testDestinationStripsLeadingSeparatorFromDestination(): void
    {
        self::assertSame(
            '/tmp/foo' . DIRECTORY_SEPARATOR . 'bar',
            PathResolver::destination('/tmp/foo', '/bar'),
            'Leading separator in destination must not produce a double separator.',
        );
    }

    public function testDestinationStripsTrailingSeparatorFromProjectRoot(): void
    {
        self::assertSame(
            '/tmp/foo' . DIRECTORY_SEPARATOR . 'bar',
            PathResolver::destination('/tmp/foo/', 'bar'),
            'Trailing separator in project root must not produce a double separator.',
        );
    }

    public function testEnsureDirectoryCreatesUsing0777Mode(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $oldUmask = umask(0022);

        try {
            $dir = $this->tempDir . '/fresh';

            PathResolver::ensureDirectory($dir . '/file.txt');

            self::assertDirectoryExists(
                $dir,
                'Directory must be created if it does not exist.',
            );
            self::assertSame(
                0755,
                fileperms($dir) & 0777,
                "Directory must be created with '0777' mode so umask '0022' yields '0755' effective permissions.",
            );
        } finally {
            umask($oldUmask);
        }
    }

    public function testEnsureDirectoryIsNoopWhenDirectoryAlreadyExists(): void
    {
        $dir = "{$this->tempDir}/existing";

        mkdir($dir, 0777, recursive: true);

        PathResolver::ensureDirectory($dir . '/file.txt');

        self::assertDirectoryExists(
            $dir,
            'Directory must exist if it already exists.',
        );
    }

    public function testEnsureDirectoryIsNoopWhenRaceCreatesDirectoryAfterMkdirFails(): void
    {
        $dir = "{$this->tempDir}/race";

        $isDirCalls = 0;

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'is_dir',
            [$dir],
            static function () use (&$isDirCalls): bool {
                $isDirCalls++;

                // first call (guard): directory does not exist yet, so attempt mkdir.
                // second call (recheck after mkdir failure): another process created the directory in between.
                return $isDirCalls >= 2;
            },
        );
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'mkdir',
            [$dir, 0777, true, null],
            false,
        );
        PathResolver::ensureDirectory($dir . '/file.txt');

        self::assertGreaterThanOrEqual(
            2,
            $isDirCalls,
            "'is_dir' must be consulted again after 'mkdir' returns 'false' so a concurrent creation is not treated "
            . 'as failure.',
        );
    }

    public function testEnsureDirectoryThrowsWhenMkdirFailsAndDirectoryStillAbsent(): void
    {
        $dir = "{$this->tempDir}/unwritable";

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'is_dir',
            [$dir],
            false,
        );
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold',
            'mkdir',
            [$dir, 0777, true, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create directory');

        PathResolver::ensureDirectory($dir . '/file.txt');
    }

    public function testRealpathOrFallbackReturnsCanonicalPathWhenExists(): void
    {
        self::assertSame(
            realpath($this->tempDir),
            PathResolver::realpathOrFallback($this->tempDir),
            "realpathOrFallback must return the canonical path when 'realpath' succeeds.",
        );
    }

    public function testRealpathOrFallbackTrimsWhenResolutionFails(): void
    {
        $unique = '/nonexistent/path/' . uniqid();

        self::assertSame(
            $unique,
            PathResolver::realpathOrFallback($unique . '/'),
            "realpathOrFallback must 'trim' trailing separators when 'realpath' fails.",
        );
    }

    public function testResolveProviderRootAcceptsLockPathEqualToVendorDir(): void
    {
        $vendor = $this->tempDir;

        // Some edge deployments (e.g. a single-package vendor) can register the vendor dir itself as a provider.
        $result = PathResolver::resolveProviderRoot($vendor, 'pkg/name', ['path' => $vendor]);

        self::assertNull(
            $result['warning'],
            "A lock path equal to the vendor 'dir' must be accepted without emitting a warning.",
        );
    }

    public function testResolveProviderRootDetectsUncLikePathAsAbsoluteViaBackslashPrefix(): void
    {
        /*
         * recordedPath starts with `\` (UNC-style). Default isAbsolute is true via the second 'str_starts_with' in
         * the OR chain; mutating the '||' between the first two operands to '&&' with the third operand folds the
         * detection into '(starts_with('\\') && preg_match drive-letter)', which is false for pure UNC paths — the
         * path is then misclassified as relative and joined under 'projectRoot', landing outside vendor.
         */
        $vendor = PathResolver::realpathOrFallback($this->tempDir);

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => '\\\\server\\share'],
            $vendor,
        );

        self::assertNotNull(
            $result['warning'],
            "A UNC-like recordedPath starting with '\\' must stay absolute and land outside vendor, emitting a "
            . "warning; the '||' disjunction on the 'str_starts_with' operands is what keeps it classified correctly.",
        );
    }

    public function testResolveProviderRootDoesNotClassifyRelativePathContainingDriveLetterInMiddleAsAbsolute(): void
    {
        /*
         * recordedPath 'foo/C:/bar' is relative but carries a drive-letter substring; only the regex '^' anchor
         * keeps it classified as relative. Default: joined under projectRoot and stays inside vendor → no warning.
         * Without '^' the mutant flips isAbsolute to true, '$expanded' becomes 'foo/C:/bar' verbatim (relative), the
         * realpath fallback leaves it with no vendor prefix, and containment fails → warning.
         */
        $vendor = PathResolver::realpathOrFallback($this->tempDir);

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => 'foo/C:/bar'],
            $vendor,
        );

        self::assertNull(
            $result['warning'],
            "A relative recordedPath with a drive-letter substring must stay classified as relative; the regex '^' "
            . 'anchor keeps it joined under projectRoot so it lands inside vendor without warning.',
        );
    }

    public function testResolveProviderRootFallsBackToDefaultWhenLockPathEscapesVendor(): void
    {
        $vendor = $this->tempDir;

        mkdir($vendor . '/pkg/name', 0777, recursive: true);

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => '/etc'],
        );

        self::assertSame(
            realpath($vendor) . DIRECTORY_SEPARATOR . 'pkg/name',
            $result['root'],
            'When the lock-recorded path escapes vendor, resolveProviderRoot must fall back to the vendor-derived '
            . 'path.',
        );
        self::assertNotNull(
            $result['warning'],
            'A warning must be emitted when a lock-recorded provider path escapes vendor.',
        );
    }

    public function testResolveProviderRootHonorsAbsoluteLockPathRegardlessOfNonEmptyProjectRoot(): void
    {
        $vendor = $this->tempDir . '/vendor';

        mkdir($vendor . '/pkg/name', 0777, recursive: true);

        $absoluteLockPath = realpath($vendor . '/pkg/name');

        self::assertIsString($absoluteLockPath, 'Test setup failed to resolve the seeded provider root.');

        $result = PathResolver::resolveProviderRoot($vendor, 'pkg/name', ['path' => $absoluteLockPath], $this->tempDir);

        self::assertNull($result['warning'], 'Absolute lock path must stay absolute when projectRoot is non-empty.');
    }

    public function testResolveProviderRootHonorsAbsoluteLockPathVerbatimWhenProjectRootIsNonEmpty(): void
    {
        $vendor = $this->tempDir . '/vendor';

        mkdir($vendor . '/pkg/name', 0777, recursive: true);

        $absoluteLockPath = realpath($vendor . '/pkg/name');

        self::assertIsString($absoluteLockPath, 'Test setup failed to resolve the seeded provider root.');

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => $absoluteLockPath],
            $this->tempDir . '/unrelated-project-root',
        );

        self::assertSame($absoluteLockPath, $result['root'], 'Absolute lock path inside vendor must be verbatim.');
        self::assertNull($result['warning'], 'Absolute path inside vendor must not emit a warning.');
    }

    public function testResolveProviderRootRejectsLockPathSharingSiblingPrefixWithVendorDir(): void
    {
        $vendor = "{$this->tempDir}/vendor";
        $sibling = "{$this->tempDir}/vendorsibling";

        mkdir($vendor, 0777, recursive: true);
        mkdir($sibling, 0777, recursive: true);

        $result = PathResolver::resolveProviderRoot($vendor, 'pkg/name', ['path' => $sibling]);

        self::assertNotNull(
            $result['warning'],
            'A lock path outside vendor that only shares a string prefix must emit a warning and fall back to the '
            . 'default root.',
        );
    }

    public function testResolveProviderRootResolvesRelativeLockPathAgainstProjectRoot(): void
    {
        $projectRoot = $this->tempDir;
        $vendor = "{$projectRoot}/vendor";

        mkdir($vendor . '/pkg/name', 0777, recursive: true);

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => 'vendor/pkg/name'],
            $projectRoot,
        );

        self::assertSame(
            realpath($vendor . '/pkg/name'),
            $result['root'],
            'A relative lock path must be expanded against the project root before the vendor-containment check.',
        );
        self::assertNull(
            $result['warning'],
            'No warning must be emitted when the relative path resolves inside the vendor directory.',
        );
    }

    public function testResolveProviderRootReturnsLockPathWhenInsideVendor(): void
    {
        $vendor = $this->tempDir;

        mkdir($vendor . '/pkg/name', 0777, recursive: true);

        $insidePath = "{$vendor}/pkg/name";

        $result = PathResolver::resolveProviderRoot($vendor, 'pkg/name', ['path' => $insidePath]);

        self::assertSame(
            realpath($insidePath),
            $result['root'],
            'When the lock-recorded path is inside vendor, it must be honored.',
        );
        self::assertNull(
            $result['warning'],
            'No warning must be emitted when the lock-recorded provider path is valid and inside vendor.',
        );
    }

    public function testResolveProviderRootTrimsTrailingSeparatorFromProjectRootBeforeJoiningRelativeLockPath(): void
    {
        $tempDir = PathResolver::realpathOrFallback($this->tempDir);
        $projectRoot = PathResolver::destination($tempDir, 'proj');
        $vendor = PathResolver::destination($projectRoot, 'vendor');

        mkdir($vendor, 0777, recursive: true);

        // relative path normalized through the same helper the production join uses, minus the leading separator.
        $relativeInVendor = ltrim(PathResolver::source('', 'vendor/pkg/name-missing'), '/\\');

        $result = PathResolver::resolveProviderRoot(
            $vendor,
            'pkg/name',
            ['path' => $relativeInVendor],
            PathResolver::destination($projectRoot, ''),
        );

        self::assertNull($result['warning'], 'Trailing separator in projectRoot must be rtrimmed before joining.');
    }

    public function testResolveProviderRootUsesDefaultWhenLockEntryIsMissingPath(): void
    {
        $result = PathResolver::resolveProviderRoot($this->tempDir, 'pkg/name', null);

        self::assertSame(
            PathResolver::realpathOrFallback($this->tempDir) . DIRECTORY_SEPARATOR . 'pkg/name',
            $result['root'],
            'When the lock record is missing or does not contain a path, resolveProviderRoot must fall back to the '
            . 'default root (canonicalized via realpathOrFallback to match implementation behavior).',
        );
        self::assertNull(
            $result['warning'],
            'No warning must be emitted when the lock record is missing or does not contain a path.',
        );
    }

    public function testSourceStripsLeadingSeparatorFromSource(): void
    {
        // single segment avoids `str_replace('/', DIRECTORY_SEPARATOR, ...)` normalization differences on Windows.
        self::assertSame(
            '/tmp/provider' . DIRECTORY_SEPARATOR . 'file.txt',
            PathResolver::source('/tmp/provider', '/file.txt'),
            'Leading separator in source must not produce a double separator.',
        );
    }

    public function testSourceStripsTrailingSeparatorFromProviderPath(): void
    {
        self::assertSame(
            '/tmp/provider' . DIRECTORY_SEPARATOR . 'file.txt',
            PathResolver::source('/tmp/provider/', 'file.txt'),
            'Trailing separator in provider path must not produce a double separator.',
        );
    }

    public function testSyncPermissionsCopiesSourcePermissionBitsOntoDestinationUnderPermissiveUmask(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $source = "{$this->tempDir}/exec.sh";
        $destination = "{$this->tempDir}/exec-copy.sh";

        file_put_contents($source, "#!/bin/sh\necho hello\n");
        file_put_contents($destination, "#!/bin/sh\necho hello\n");

        chmod($source, 0755);
        chmod($destination, 0644);

        $oldUmask = umask(0022);

        try {
            PathResolver::syncPermissions($source, $destination);

            self::assertSame(
                0755,
                fileperms($destination) & 0777,
                "Under a permissive '0022' umask, 'syncPermissions()' must preserve the source executable bit so "
                . 'scaffolded CLI stubs remain directly runnable.',
            );
        } finally {
            umask($oldUmask);
        }
    }

    public function testSyncPermissionsHonorsRestrictiveUmaskToAvoidSilentlyWideningPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $source = "{$this->tempDir}/world-readable.sh";
        $destination = "{$this->tempDir}/world-readable-copy.sh";

        file_put_contents($source, "#!/bin/sh\n");
        file_put_contents($destination, "#!/bin/sh\n");

        chmod($source, 0755);
        chmod($destination, 0600);

        $oldUmask = umask(0077);

        try {
            PathResolver::syncPermissions($source, $destination);

            self::assertSame(
                0700,
                fileperms($destination) & 0777,
                "Under a restrictive '0077' umask, 'syncPermissions()' must mask the source permissions and drop "
                . 'group/other bits so security-restrictive setups are never silently widened.',
            );
        } finally {
            umask($oldUmask);
        }
    }

    public function testSyncPermissionsMasksSourceBitsWithPermsAnd0777RatherThanPermsOr0777(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $source = "{$this->tempDir}/mode-0640.sh";
        $destination = "{$this->tempDir}/mode-0640-copy.sh";

        file_put_contents($source, "#!/bin/sh\n");
        file_put_contents($destination, "#!/bin/sh\n");

        chmod($source, 0640);
        chmod($destination, 0600);

        /*
         * A permissive '0000' umask isolates the `$perms & 0777` bitmask from the umask mask; any mutation that
         * swaps `&` for `|` (widening 0640 to 0777 via bitwise OR) becomes observable without being masked out by
         * the umask step that follows.
         */
        $oldUmask = umask(0000);

        try {
            PathResolver::syncPermissions($source, $destination);

            self::assertSame(
                0640,
                fileperms($destination) & 0777,
                "Under a permissive '0000' umask the destination must land on exactly the source permissions (0640); "
                . "mutating '\$perms & 0777' to '\$perms | 0777' widens to 0777 and this assertion fails, pinning the "
                . 'bitmask intent.',
            );
        } finally {
            umask($oldUmask);
        }
    }

    public function testSyncPermissionsReturnsEarlyWhenSourceFilepermsIsFalse(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX umask-based permissions do not apply to NTFS (Windows always reports 0777).');
        }

        $destination = "{$this->tempDir}/unchanged.sh";

        file_put_contents($destination, "#!/bin/sh\n");

        chmod($destination, 0644);

        PathResolver::syncPermissions("{$this->tempDir}/does-not-exist", $destination);

        self::assertSame(
            0644,
            fileperms($destination) & 0777,
            "When 'fileperms()' on the source returns 'false' (missing/unreadable source), 'syncPermissions()' "
            . 'must return early and leave the destination permissions untouched.',
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
}
