<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\DefaultExcludes;
use yii\scaffold\tests\providers\DefaultExcludesProvider;

/**
 * Unit tests for {@see DefaultExcludes} built-in exclusion patterns.
 *
 * {@see DefaultExcludesProvider} for test case data providers.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class DefaultExcludesTest extends TestCase
{
    #[DataProviderExternal(DefaultExcludesProvider::class, 'excludedPaths')]
    public function testExcludedPathMatchesDefaultExcludes(string $path): void
    {
        self::assertTrue(
            DefaultExcludes::matches($path),
            "'{$path}' must match at least one default-exclude pattern.",
        );
    }

    #[DataProviderExternal(DefaultExcludesProvider::class, 'includedPaths')]
    public function testIncludedPathDoesNotMatchDefaultExcludes(string $path): void
    {
        self::assertFalse(
            DefaultExcludes::matches($path),
            "'{$path}' is project content and must not match any default-exclude pattern.",
        );
    }

    #[DataProviderExternal(DefaultExcludesProvider::class, 'nonPrunableDirectories')]
    public function testNonPrunableDirectoryIsNotMatchedByMatchesDirectory(string $relativeDir): void
    {
        self::assertFalse(
            DefaultExcludes::matchesDirectory($relativeDir),
            "'{$relativeDir}' must not be prunable: no default pattern '{$relativeDir}/**' excludes every descendant.",
        );
    }

    public function testPatternsArrayIsNotEmpty(): void
    {
        self::assertNotEmpty(
            DefaultExcludes::PATTERNS,
            'DefaultExcludes::PATTERNS must declare at least one default-exclude pattern.',
        );
    }

    #[DataProviderExternal(DefaultExcludesProvider::class, 'prunableDirectories')]
    public function testPrunableDirectoryIsMatchedByMatchesDirectory(string $relativeDir): void
    {
        self::assertTrue(
            DefaultExcludes::matchesDirectory($relativeDir),
            "'{$relativeDir}' must be prunable: pattern '{$relativeDir}/**' excludes every descendant.",
        );
    }
}
