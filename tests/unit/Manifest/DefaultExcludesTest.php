<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\DefaultExcludes;

/**
 * Unit tests for {@see DefaultExcludes} built-in exclusion patterns.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class DefaultExcludesTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function excludedPathProvider(): array
    {
        return [
            ['composer.json'],
            ['composer.lock'],
            ['vendor/autoload.php'],
            ['vendor/nested/pkg/file.php'],
            ['.git/HEAD'],
            ['.github/workflows/ci.yml'],
            ['.gitattributes'],
            ['.gitignore'],
            ['tests/unit/SomeTest.php'],
            ['tests/functional/deep/Fixture.php'],
            ['phpunit.xml'],
            ['phpunit.xml.dist'],
            ['.phpunit.cache/foo'],
            ['phpstan.neon'],
            ['phpstan.neon.dist'],
            ['phpstan.cache/bar'],
            ['infection.json5'],
            ['infection.log'],
            ['.editorconfig'],
            ['.php-cs-fixer.php'],
            ['.php-cs-fixer.dist.php'],
            ['ecs.php'],
            ['psalm.xml'],
            ['README.md'],
            ['CHANGELOG.md'],
            ['LICENSE'],
            ['docs/providers.md'],
            ['scaffold.json'],
            ['scaffold-lock.json'],
            ['runtime/cache/foo'],
            ['runtime/.gitignore'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function includedPathProvider(): array
    {
        return [
            ['src/controllers/SiteController.php'],
            ['src/models/User.php'],
            ['config/params.php'],
            ['config/web.php'],
            ['migrations/M2024_CreateUserTable.php'],
            ['public/index.php'],
            ['public/assets/.gitkeep'],
            ['yii'],
            ['rbac/items.php'],
            ['resources/mail/layouts/html.php'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonPrunableDirectoryProvider(): array
    {
        return [
            ['src'],
            ['config'],
            ['migrations'],
            ['public'],
            ['rbac'],
            ['resources'],
            ['vendor/bin'],
            ['.git/hooks'],
            ['tests/unit'],
            ['docs/nested'],
            [''],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function prunableDirectoryProvider(): array
    {
        return [
            ['vendor'],
            ['.git'],
            ['.github'],
            ['tests'],
            ['.phpunit.cache'],
            ['phpunit.cache'],
            ['phpstan.cache'],
            ['.infection'],
            ['docs'],
            ['runtime'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedPathProvider')]
    public function testExcludedPathMatchesDefaultExcludes(string $path): void
    {
        self::assertTrue(
            DefaultExcludes::matches($path),
            "'{$path}' must match at least one default-exclude pattern.",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('includedPathProvider')]
    public function testIncludedPathDoesNotMatchDefaultExcludes(string $path): void
    {
        self::assertFalse(
            DefaultExcludes::matches($path),
            "'{$path}' is project content and must not match any default-exclude pattern.",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPrunableDirectoryProvider')]
    public function testNonPrunableDirectoryIsNotMatchedByMatchesDirectory(string $relativeDir): void
    {
        self::assertFalse(
            DefaultExcludes::matchesDirectory($relativeDir),
            "'{$relativeDir}' must not be pruned: no default pattern of the form '{$relativeDir}/**' excludes every "
            . 'possible descendant.',
        );
    }

    public function testPatternsArrayIsNotEmpty(): void
    {
        self::assertNotEmpty(
            DefaultExcludes::PATTERNS,
            'DefaultExcludes::PATTERNS must declare at least one default-exclude pattern.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prunableDirectoryProvider')]
    public function testPrunableDirectoryIsMatchedByMatchesDirectory(string $relativeDir): void
    {
        self::assertTrue(
            DefaultExcludes::matchesDirectory($relativeDir),
            "'{$relativeDir}' must be prunable: a default pattern of the form '{$relativeDir}/**' excludes every "
            . 'possible descendant, so descent can be safely skipped.',
        );
    }
}
