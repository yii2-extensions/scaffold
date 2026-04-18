<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use Composer\Package\PackageInterface;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\{FileMapping, ManifestLoader, ManifestSchema};

/**
 * Unit tests for {@see ManifestLoader} inline and external manifest loading.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class ManifestLoaderTest extends TestCase
{
    public function testAllFileMappingsAreInstancesOfFileMapping(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => [
                        'file-mapping' => [
                            'a.php' => [
                                'source' => 'stubs/a.php',
                                'mode' => 'replace',
                            ],
                            'b.php' => [
                                'source' => 'stubs/b.php',
                                'mode' => 'preserve',
                            ],
                        ],
                    ],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $result = (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/yii2-extensions/test');

        foreach ($result as $mapping) {
            self::assertInstanceOf(
                FileMapping::class,
                $mapping,
                'Expected all items in result to be instances of FileMapping',
            );
        }
    }

    public function testExternalManifestLoadsFileMappings(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/valid-external';

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'scaffold.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/inertia-vue-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);

        self::assertCount(
            2,
            $result,
            "Expected exactly '2' FileMappings in result.",
        );

        $first = array_shift($result);

        if ($first === null) {
            self::fail('Expected at least one FileMapping in result.');
        }

        self::assertSame(
            'resources/js/app.js',
            $first->destination,
            "Expected destination to be 'resources/js/app.js'",
        );
        self::assertSame(
            'stubs/resources/js/app.js',
            $first->source,
            "Expected source to be 'stubs/resources/js/app.js'",
        );
        self::assertSame(
            'preserve',
            $first->mode,
            "Expected mode to be 'preserve'",
        );
        self::assertSame(
            'yii2-extensions/inertia-vue-scaffold',
            $first->providerName,
            "Expected providerName to be 'yii2-extensions/inertia-vue-scaffold'",
        );
        self::assertSame(
            $providerPath,
            $first->providerPath,
            "Expected providerPath to be '{$providerPath}'",
        );
    }

    public function testExternalManifestWhenFileGetContentsFailsThrows(): void
    {
        $providerPath = sys_get_temp_dir() . '/scaffold-unreadable-manifest-' . uniqid();

        mkdir($providerPath, 0777, recursive: true);

        $manifestPath = "{$providerPath}/scaffold.json";

        file_put_contents($manifestPath, '{}');

        MockerState::addCondition(
            'yii\\scaffold\\Manifest',
            'file_get_contents',
            [$manifestPath, false, null, 0, null],
            false,
        );

        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('yii2-extensions/bad');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('could not read manifest file');

            (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
        } finally {
            @unlink($manifestPath);
            @rmdir($providerPath);
        }
    }

    public function testExternalManifestWithBackslashAbsolutePathThrows(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => '\\absolute\\path.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a relative path inside the provider root');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/some/path');
    }

    public function testExternalManifestWithDriveLetterAbsolutePathThrows(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'C:/absolute/path.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a relative path inside the provider root');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/some/path');
    }

    public function testExternalManifestWithEmptyPathThrows(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => ''],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty string');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/some/path');
    }

    public function testExternalManifestWithMalformedJsonThrows(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/malformed-manifest';

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'scaffold.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/bad');

        $this->expectException(JsonException::class);

        (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
    }

    public function testExternalManifestWithMidPathColonIsNotTreatedAsAbsolute(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'nested/C:file.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        // Must fall through to the "not found" branch rather than being caught by the absolute-path regex.
        $this->expectExceptionMessage('manifest file not found');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/nonexistent/provider');
    }

    public function testExternalManifestWithMissingFileThrows(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'nonexistent.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/nonexistent/path');
    }

    public function testExternalManifestWithNonObjectJsonThrows(): void
    {
        $providerPath = sys_get_temp_dir() . '/scaffold-non-object-manifest-' . uniqid();

        if (!mkdir($providerPath, 0777, recursive: true)) {
            self::fail("Could not create fixture directory '{$providerPath}'.");
        }

        // write a JSON payload that decodes to a scalar, not an object.
        file_put_contents("{$providerPath}/scaffold.json", '"just a string"');

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => 'scaffold.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/bad');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must decode to an object');

            (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
        } finally {
            @unlink("{$providerPath}/scaffold.json");
            @rmdir($providerPath);
        }
    }

    public function testExternalManifestWithTraversalPathThrows(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => ['manifest' => '../escape.json'],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a relative path inside the provider root');

        (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/yii2-extensions/bad');
    }

    public function testInlineFileMappingCarriesProviderNameAndPath(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => [
                        'file-mapping' => [
                            'nginx.conf' => [
                                'source' => 'stubs/nginx.conf',
                                'mode' => 'replace',
                            ],
                        ],
                    ],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/nginx-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load(
            $package,
            '/vendor/yii2-extensions/nginx-scaffold',
        );

        self::assertCount(1, $result);

        $mapping = array_shift($result);

        if ($mapping === null) {
            self::fail('Expected one FileMapping in result.');
        }

        self::assertSame(
            'yii2-extensions/nginx-scaffold',
            $mapping->providerName,
            "Expected providerName to be 'yii2-extensions/nginx-scaffold'",
        );
        self::assertSame(
            '/vendor/yii2-extensions/nginx-scaffold',
            $mapping->providerPath,
            "Expected providerPath to be '/vendor/yii2-extensions/nginx-scaffold'",
        );
    }

    public function testInlineMappingLoadsFileMappings(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => [
                        'file-mapping' => [
                            'config/params.php' => [
                                'source' => 'stubs/params.php',
                                'mode' => 'preserve',
                            ],
                            'vite.config.js' => [
                                'source' => 'stubs/vite.config.js',
                                'mode' => 'replace',
                            ],
                        ],
                    ],
                ],
            );
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/inertia-vue-scaffold');

        $result = (new ManifestLoader(new ManifestSchema()))->load(
            $package,
            '/vendor/yii2-extensions/inertia-vue-scaffold',
        );

        self::assertCount(
            2,
            $result,
            "Expected exactly '2' FileMappings in result.",
        );

        $first = array_shift($result);

        if ($first === null) {
            self::fail('Expected at least one FileMapping in result.');
        }

        self::assertSame(
            'config/params.php',
            $first->destination,
            "Expected destination to be 'config/params.php'",
        );
        self::assertSame(
            'stubs/params.php',
            $first->source,
            "Expected source to be 'stubs/params.php'",
        );
        self::assertSame(
            'preserve',
            $first->mode,
            "Expected mode to be 'preserve'",
        );
        self::assertSame(
            'yii2-extensions/inertia-vue-scaffold',
            $first->providerName,
            "Expected providerName to be 'yii2-extensions/inertia-vue-scaffold'",
        );
        self::assertSame(
            '/vendor/yii2-extensions/inertia-vue-scaffold',
            $first->providerPath,
            "Expected providerPath to be '/vendor/yii2-extensions/inertia-vue-scaffold'",
        );
    }

    public function testPackageWithNoScaffoldExtraReturnsEmptyArray(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn([]);
        $package
            ->method('getName')
            ->willReturn('some/package');

        self::assertSame(
            [],
            (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/some/package'),
            'Expected empty array when no scaffold extra is present',
        );
    }

    public function testPackageWithScaffoldExtraButNoMappingReturnsEmptyArray(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['scaffold' => ['locations' => ['web-root' => 'public/']]]);
        $package
            ->method('getName')
            ->willReturn('some/package');

        self::assertSame(
            [],
            (new ManifestLoader(new ManifestSchema()))->load($package, '/vendor/some/package'),
            'Expected empty array when scaffold extra is present but no mapping is defined',
        );
    }

    public function testSchemaViolationInExternalManifestThrows(): void
    {
        $providerPath = dirname(__DIR__, 2) . '/fixtures/providers/invalid-traversal';

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn([
                'scaffold' => ['manifest' => 'scaffold.json'],
            ]);
        $package
            ->method('getName')
            ->willReturn('yii2-extensions/bad');

        $this->expectException(RuntimeException::class);

        (new ManifestLoader(new ManifestSchema()))->load($package, $providerPath);
    }
}
