<?php

declare(strict_types=1);

namespace yii\scaffold\tests\providers;

/**
 * Data provider for {@see \yii\scaffold\tests\unit\Manifest\DefaultExcludesTest} test cases.
 *
 * Provides representative input/output pairs for {@see \yii\scaffold\Manifest\DefaultExcludes} matching.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class DefaultExcludesProvider
{
    /**
     * @phpstan-return list<array{0: string}>
     */
    public static function excludedPaths(): array
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
     * @phpstan-return list<array{0: string}>
     */
    public static function includedPaths(): array
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
     * @phpstan-return list<array{0: string}>
     */
    public static function nonPrunableDirectories(): array
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
     * @phpstan-return list<array{0: string}>
     */
    public static function prunableDirectories(): array
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
}
