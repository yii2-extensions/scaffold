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

    public function testResolveProviderRootUsesDefaultWhenLockEntryIsMissingPath(): void
    {
        $result = PathResolver::resolveProviderRoot($this->tempDir, 'pkg/name', null);

        self::assertSame(
            $this->tempDir . DIRECTORY_SEPARATOR . 'pkg/name',
            $result['root'],
            'When the lock record is missing or does not contain a path, resolveProviderRoot must fall back to the '
            . 'default root.',
        );
        self::assertNull(
            $result['warning'],
            'No warning must be emitted when the lock record is missing or does not contain a path.',
        );
    }

    public function testSourceStripsLeadingSeparatorFromSource(): void
    {
        self::assertSame(
            '/tmp/provider' . DIRECTORY_SEPARATOR . 'stubs/a.txt',
            PathResolver::source('/tmp/provider', '/stubs/a.txt'),
            'Leading separator in source must not produce a double separator.',
        );
    }

    public function testSourceStripsTrailingSeparatorFromProviderPath(): void
    {
        self::assertSame(
            '/tmp/provider' . DIRECTORY_SEPARATOR . 'stubs/a.txt',
            PathResolver::source('/tmp/provider/', 'stubs/a.txt'),
            'Trailing separator in provider path must not produce a double separator.',
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
