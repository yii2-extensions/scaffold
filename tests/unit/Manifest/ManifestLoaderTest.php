<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use Composer\Package\PackageInterface;
use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Manifest\ManifestLoader;
use yii\scaffold\Manifest\ManifestSchema;

/**
 * Unit tests for {@see ManifestLoader} inline and external manifest loading.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class ManifestLoaderTest extends TestCase
{
    public function testAllFileMappingsAreInstancesOfFileMapping(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => [
                'file-mapping' => [
                    'a.php' => ['source' => 'stubs/a.php', 'mode' => 'replace'],
                    'b.php' => ['source' => 'stubs/b.php', 'mode' => 'preserve'],
                ],
            ],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/test');

        $result = (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/yii2-extensions/test');

        foreach ($result as $mapping) {
            self::assertInstanceOf(FileMapping::class, $mapping);
        }
    }

    public function testExternalManifestLoadsFileMappings(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/valid-external';
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => ['manifest' => 'scaffold.json'],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/inertia-vue-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);

        self::assertCount(2, $result);

        $first = array_shift($result);

        if ($first === null) {
            self::fail('Expected at least one FileMapping in result.');
        }

        self::assertSame('resources/js/app.js', $first->destination);
        self::assertSame('stubs/resources/js/app.js', $first->source);
        self::assertSame('preserve', $first->mode);
        self::assertSame('yii2-extensions/inertia-vue-scaffold', $first->providerName);
        self::assertSame($providerPath, $first->providerPath);
    }

    public function testExternalManifestWithEmptyPathThrows(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => ['manifest' => ''],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty string');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/some/path');
    }

    public function testExternalManifestWithMalformedJsonThrows(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/malformed-manifest';
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => ['manifest' => 'scaffold.json'],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/bad');

        $this->expectException(JsonException::class);

        (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
    }

    public function testExternalManifestWithMissingFileThrows(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => ['manifest' => 'nonexistent.json'],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/nonexistent/path');
    }

    public function testInlineFileMappingCarriesProviderNameAndPath(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => [
                'file-mapping' => [
                    'nginx.conf' => ['source' => 'stubs/nginx.conf', 'mode' => 'replace'],
                ],
            ],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/nginx-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load(
            $package,
            '/vendor/yii2-extensions/nginx-scaffold',
        );

        self::assertCount(1, $result);

        $mapping = array_shift($result);

        if ($mapping === null) {
            self::fail('Expected one FileMapping in result.');
        }

        self::assertSame('yii2-extensions/nginx-scaffold', $mapping->providerName);
        self::assertSame('/vendor/yii2-extensions/nginx-scaffold', $mapping->providerPath);
    }

    public function testInlineMappingLoadsFileMappings(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => [
                'file-mapping' => [
                    'config/params.php' => ['source' => 'stubs/params.php', 'mode' => 'preserve'],
                    'vite.config.js' => ['source' => 'stubs/vite.config.js', 'mode' => 'replace'],
                ],
            ],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/inertia-vue-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load(
            $package,
            '/vendor/yii2-extensions/inertia-vue-scaffold',
        );

        self::assertCount(2, $result);

        $first = array_shift($result);

        if ($first === null) {
            self::fail('Expected at least one FileMapping in result.');
        }

        self::assertSame('config/params.php', $first->destination);
        self::assertSame('stubs/params.php', $first->source);
        self::assertSame('preserve', $first->mode);
        self::assertSame('yii2-extensions/inertia-vue-scaffold', $first->providerName);
        self::assertSame('/vendor/yii2-extensions/inertia-vue-scaffold', $first->providerPath);
    }
    public function testPackageWithNoScaffoldExtraReturnsEmptyArray(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([]);
        $package->method('getName')->willReturn('some/package');

        self::assertSame([], (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/some/package'));
    }

    public function testPackageWithScaffoldExtraButNoMappingReturnsEmptyArray(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn(['scaffold' => ['locations' => ['web-root' => 'public/']]]);
        $package->method('getName')->willReturn('some/package');

        self::assertSame([], (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/some/package'));
    }

    public function testSchemaViolationInExternalManifestThrows(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/invalid-traversal';
        $package = self::createStub(PackageInterface::class);
        $package->method('getExtra')->willReturn([
            'scaffold' => ['manifest' => 'scaffold.json'],
        ]);
        $package->method('getName')->willReturn('yii2-extensions/bad');

        $this->expectException(RuntimeException::class);

        (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
    }
}
