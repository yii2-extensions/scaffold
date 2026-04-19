<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Security;

use PHPUnit\Framework\Attributes\{Group, RequiresOperatingSystemFamily};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use yii\scaffold\Security\PathValidator;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see PathValidator} path traversal and absolute path detection.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('security')]
final class PathValidatorTest extends TestCase
{
    use TempDirectoryTrait;

    public function testDestinationAcceptsDeepNonExistentPathInsideRoot(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('a/b/c/d/does-not-exist.txt', $this->tempDir);
    }

    public function testDestinationAcceptsEmptyRelativePath(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('', $this->tempDir);
    }

    public function testDestinationAcceptsNonExistentFileInsideExistingSubdirectory(): void
    {
        // walking to an existing in-root ancestor must NOT trigger an escape detection.
        $root = "{$this->tempDir}/root";

        mkdir($root . '/sub', 0777, recursive: true);

        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('sub/missing-file.txt', $root);
    }

    public function testDestinationAcceptsPathWithColonInSegment(): void
    {
        $this->expectNotToPerformAssertions();

        // colon in a non-leading segment must not be mistaken for a Windows drive letter prefix.
        (new PathValidator())->validateDestination('nested/C:file.txt', $this->tempDir);
    }

    public function testDestinationAcceptsSymlinkThatLoopsBackToRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows canonicalizes symlink targets with short-path/long-path variance that shifts the prefix used by
            // `realpath`, so the POSIX "loop-to-root" equivalence this test models is not reproducible reliably there.
            self::markTestSkipped('Symlink canonicalization on Windows is not equivalent to POSIX.');
        }

        // a symlink whose resolved target is `realRoot` itself must pass when used as an ancestor of a missing leaf.
        $root = "{$this->tempDir}/root";

        mkdir($root, 0777, recursive: true);

        $this->createSymlinkOrSkip($root, $root . '/loop');
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('loop/missing-file.txt', $root);
    }

    public function testDestinationAcceptsTrailingSeparator(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('config/', $this->tempDir);
    }

    public function testDestinationRejectsNonExistentLeafBeneathSiblingPrefixSymlink(): void
    {
        // `rootsibling` shares a string prefix with `root` but resolves outside root. Because the leaf does not exist,
        // the validator walks ancestors and must detect the escape at the resolved symlink inside the loop (L125).
        $root = "{$this->tempDir}/root";
        $sibling = "{$this->tempDir}/rootsibling";

        mkdir($root, 0777, recursive: true);
        mkdir($sibling, 0777, recursive: true);

        $this->createSymlinkOrSkip($sibling, $root . '/link');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination path escapes the root via symlink');

        (new PathValidator())->validateDestination('link/missing-leaf.txt', $root);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testDestinationRejectsNonExistentPathBeneathSymlinkEscapingAncestor(): void
    {
        $root = "{$this->tempDir}/root";
        $outside = "{$this->tempDir}/outside";

        mkdir($root, 0777, recursive: true);
        mkdir($outside, 0777, recursive: true);

        $this->createSymlinkOrSkip($outside, $root . '/escape');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination path escapes the root via symlink');

        // the terminal file does not exist, forcing the validator to walk ancestors.
        (new PathValidator())->validateDestination('escape/missing/file.txt', $root);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testDestinationRejectsSymlinkEscapingOutsideRoot(): void
    {
        $root = "{$this->tempDir}/root";
        $outside = "{$this->tempDir}/outside";

        mkdir($root, 0777, recursive: true);
        mkdir($outside, 0777, recursive: true);

        $this->createSymlinkOrSkip($outside, "{$root}/escape");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination path escapes the root via symlink');

        (new PathValidator())->validateDestination('escape', $root);
    }

    public function testDestinationRejectsSymlinkToSiblingPrefix(): void
    {
        // `rootsibling` is a directory whose path shares a prefix with `root` but is not inside it.
        $root = "{$this->tempDir}/root";
        $sibling = "{$this->tempDir}/rootsibling";

        mkdir($root, 0777, recursive: true);
        mkdir($sibling, 0777, recursive: true);

        $this->createSymlinkOrSkip($sibling, "{$root}/link");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination path escapes the root via symlink');

        (new PathValidator())->validateDestination('link', $root);
    }

    public function testDestinationWithAbsoluteUnixPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('/etc/passwd', sys_get_temp_dir());
    }

    public function testDestinationWithAbsoluteWindowsPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('C:\\Windows\\System32\\file', sys_get_temp_dir());
    }

    public function testDestinationWithBackslashTraversalThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('config\\..\\..\\etc\\passwd', sys_get_temp_dir());
    }

    public function testDestinationWithDotFileIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('.gitignore', sys_get_temp_dir());
    }

    public function testDestinationWithDoubleDotInFilenameIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        // "foo..bar" has ".." inside a filename segment not a traversal component.
        (new PathValidator())->validateDestination('foo..bar', sys_get_temp_dir());
    }

    public function testDestinationWithTraversalAtStartThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('../../../etc/passwd', sys_get_temp_dir());
    }

    public function testDestinationWithTraversalInMiddleThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('config/../../../etc/passwd', sys_get_temp_dir());
    }

    public function testNonExistentProjectRootThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new PathValidator())->validateDestination('config/params.php', '/nonexistent/path/' . uniqid());
    }

    public function testNonExistentProviderRootThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new PathValidator())->validateSource('stubs/params.php', '/nonexistent/provider/' . uniqid());
    }

    public function testNormalizePathStripsTrailingSeparatorFromCombinedResult(): void
    {
        /*
         * Pins the outer 'rtrim($combined, DIRECTORY_SEPARATOR)' at line 194: with a relative that ends in a
         * separator, 'normalizePath' must trim it so downstream 'dirname' iterations and string comparisons work on
         * a canonical absolute path. Exercised via reflection because 'normalizePath' is private.
         */
        $method = new ReflectionMethod(PathValidator::class, 'normalizePath');

        /** @var string $result */
        $result = $method->invoke(new PathValidator(), '/root', 'subdir/');

        self::assertSame(
            '/root' . DIRECTORY_SEPARATOR . 'subdir',
            $result,
            'Trailing separator on the relative segment must be stripped from the combined path.',
        );
    }

    public function testSourceAcceptsEmptyRelativePath(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateSource('', $this->tempDir);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testSourceRejectsSymlinkEscapingOutsideRoot(): void
    {
        $root = "{$this->tempDir}/provider";
        $outside = "{$this->tempDir}/outside";

        mkdir($root, 0777, recursive: true);
        mkdir($outside, 0777, recursive: true);

        $this->createSymlinkOrSkip($outside, "{$root}/escape");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');

        (new PathValidator())->validateSource('escape', $root);
    }

    public function testSourceWithAbsolutePathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('/etc/shadow', sys_get_temp_dir());
    }

    public function testSourceWithTraversalAtStartThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('../stubs/params.php', sys_get_temp_dir());
    }

    public function testSourceWithTraversalInMiddleThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('stubs/../../../etc/shadow', sys_get_temp_dir());
    }

    public function testValidNestedRelativeDestinationPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('resources/js/Pages/Home.vue', sys_get_temp_dir());
    }

    public function testValidRelativeDestinationPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('config/params.php', sys_get_temp_dir());
    }

    public function testValidRelativeSourcePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateSource('stubs/params.php', sys_get_temp_dir());
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
     * Creates a filesystem symlink or skips the current test when the environment does not support symlink creation
     * (for example, Windows without developer mode, restricted container mounts, or chroots without `CAP_SYS_ADMIN`).
     *
     * @param string $target The target path the symlink should point to.
     * @param string $link The path where the symlink should be created.
     */
    private function createSymlinkOrSkip(string $target, string $link): void
    {
        if (@symlink($target, $link) === false) {
            self::markTestSkipped('Symlink creation is not supported in this environment.');
        }
    }
}
