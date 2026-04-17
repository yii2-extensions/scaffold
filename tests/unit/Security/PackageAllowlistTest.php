<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Security\PackageAllowlist;

/**
 * Unit tests for {@see PackageAllowlist} authorization enforcement.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('security')]
final class PackageAllowlistTest extends TestCase
{
    public function testAssertAllowedPassesSilentlyForAuthorizedPackage(): void
    {
        $this->expectNotToPerformAssertions();

        $list = new PackageAllowlist(['yii2-extensions/nginx-scaffold']);

        $list->assertAllowed('yii2-extensions/nginx-scaffold');
    }

    public function testAssertAllowedThrowsForEmptyAllowlist(): void
    {
        $list = new PackageAllowlist([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('allowed-packages');

        $list->assertAllowed('any/package');
    }

    public function testAssertAllowedThrowsForUnauthorizedPackage(): void
    {
        $list = new PackageAllowlist(['yii2-extensions/app-base-scaffold']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yii2-extensions/nginx-scaffold');

        $list->assertAllowed('yii2-extensions/nginx-scaffold');
    }

    public function testEmptyAllowlistRejectsEveryPackage(): void
    {
        $list = new PackageAllowlist([]);

        self::assertFalse(
            $list->isAllowed('yii2-extensions/anything'),
            'Empty allowlist should reject all packages.',
        );
    }

    public function testIsAllowedUsesStrictStringComparison(): void
    {
        $list = new PackageAllowlist(['yii2-extensions/nginx-scaffold']);

        self::assertFalse(
            $list->isAllowed('yii2-extensions/apache-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('yii2-extensions/nginx-scaffold'),
            'Package in allowlist should be allowed.',
        );
    }

    public function testMultiplePackagesInAllowlist(): void
    {
        $list = new PackageAllowlist([
            'yii2-extensions/app-base-scaffold',
            'yii2-extensions/nginx-scaffold',
            'yii2-extensions/inertia-vue-scaffold',
        ]);

        self::assertTrue(
            $list->isAllowed('yii2-extensions/app-base-scaffold'),
            'First package in allowlist should be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('yii2-extensions/nginx-scaffold'),
            'Second package in allowlist should be allowed.',
        );
        self::assertTrue(
            $list->isAllowed('yii2-extensions/inertia-vue-scaffold'),
            'Third package in allowlist should be allowed.',
        );
        self::assertFalse(
            $list->isAllowed('yii2-extensions/apache-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
    }
    public function testPackageInAllowlistIsAllowed(): void
    {
        $list = new PackageAllowlist(['yii2-extensions/app-base-scaffold', 'yii2-extensions/nginx-scaffold']);

        self::assertTrue(
            $list->isAllowed('yii2-extensions/app-base-scaffold'),
            'Package in allowlist should be allowed.',
        );
    }

    public function testPackageNotInAllowlistIsNotAllowed(): void
    {
        $list = new PackageAllowlist(['yii2-extensions/app-base-scaffold']);

        self::assertFalse(
            $list->isAllowed('yii2-extensions/nginx-scaffold'),
            'Package not in allowlist should not be allowed.',
        );
    }
}
