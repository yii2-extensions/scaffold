<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use yii\scaffold\Scaffold\Scaffolder;

/**
 * Unit tests for the private `Scaffolder::toProjectRelative` path-normalization helper.
 *
 * Exercised via {@see ReflectionMethod} because the helper is intentionally private; testing it directly pins down
 * normalization edge cases that would otherwise only appear by accident through end-to-end scaffolding.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
final class ScaffolderToProjectRelativeTest extends TestCase
{
    public function testReturnsBasenameRelativeWhenPackagePathSitsInsideProjectRoot(): void
    {
        self::assertSame(
            'vendor/pkg',
            $this->call('/tmp/project/vendor/pkg', '/tmp/project'),
            'Package path inside the project root must be returned as a project-relative path with forward slashes.',
        );
    }

    public function testReturnsEmptyStringWhenPackagePathEqualsProjectRootExactly(): void
    {
        self::assertSame(
            '',
            $this->call('/tmp/project', '/tmp/project'),
            "When the package path equals the project root exactly, 'toProjectRelative' must return an empty string; "
            . "the boundary-enforcing '. \"/\"' concatenation on 'normalizedPath' guards this edge case and its "
            . 'removal would return the full absolute path instead.',
        );
    }

    public function testReturnsFullAbsolutePathWithForwardSlashesWhenOutsideProjectRoot(): void
    {
        self::assertSame(
            '/outside/project/pkg',
            $this->call('/outside/project/pkg', '/tmp/project'),
            'Package paths outside the project root must fall back to the normalized absolute path so the lock '
            . 'still records a usable location.',
        );
    }

    public function testStripsTrailingSeparatorFromAbsoluteFallbackWhenOutsideProjectRoot(): void
    {
        self::assertSame(
            '/other/location',
            $this->call('/other/location/', '/tmp/project'),
            "When the package path lies outside the project root, the fallback must still 'rtrim' trailing separators "
            . "so absolute lock paths are recorded without spurious trailing '/'.",
        );
    }

    public function testStripsTrailingSeparatorFromProjectRootBeforePrefixCheck(): void
    {
        self::assertSame(
            'vendor/pkg',
            $this->call('/tmp/project/vendor/pkg', '/tmp/project/'),
            "A trailing separator on the project root must be stripped before the prefix check; without 'rtrim' the "
            . "doubled slash ('/tmp/project//') would miss the prefix and leak the absolute package path into the "
            . 'lock.',
        );
    }

    public function testUsesCaseSensitiveMatchingOnPosixSoMixedCaseRootsAreTreatedAsOutsideProject(): void
    {
        self::assertSame(
            '/tmp/project/pkg',
            $this->call('/tmp/project/pkg', '/tmp/Project'),
            'On POSIX, mixed-case prefixes must not match (byte-exact comparison required).',
        );
    }

    public function testStripsTrailingSeparatorFromRelativeReturnValue(): void
    {
        self::assertSame(
            'vendor/pkg',
            $this->call('/tmp/project/vendor/pkg/', '/tmp/project'),
            "A trailing separator on the relative segment (after 'substr') must be stripped so lock entries never "
            . "record paths like 'vendor/pkg/'; the outer 'rtrim(substr(...), \"/\")' guards this.",
        );
    }

    /**
     * Invokes the private static {@see Scaffolder::toProjectRelative()} via reflection.
     */
    private function call(string $absolutePath, string $projectRoot): string
    {
        $method = new ReflectionMethod(Scaffolder::class, 'toProjectRelative');

        /** @var string $result */
        $result = $method->invoke(null, $absolutePath, $projectRoot);

        return $result;
    }
}
