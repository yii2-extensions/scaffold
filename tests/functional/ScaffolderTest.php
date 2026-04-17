<?php

declare(strict_types=1);

namespace yii\scaffold\tests\functional;

use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\ManifestLoader;
use yii\scaffold\Manifest\ManifestSchema;
use yii\scaffold\Scaffold\Applier;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Scaffold\Scaffolder;
use yii\scaffold\Security\PackageAllowlist;
use yii\scaffold\Security\PathValidator;
use yii\scaffold\tests\support\FakeProjectBuilder;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Functional tests for {@see Scaffolder} end-to-end scaffold execution.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class ScaffolderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testAppendModeAppliedOnFullScaffoldEvenIfAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/test', 'stubs/.gitignore', '*.log');
        $builder->createProjectFile('.gitignore', 'vendor/');

        $provider = $this->makeProviderPackage('yii2-extensions/test', [
            'scaffold' => [
                'file-mapping' => [
                    '.gitignore' => ['source' => 'stubs/.gitignore', 'mode' => 'append'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // First run: full scaffold writes append.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterFirst = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        // Second full scaffold: re-appends even though entry exists in lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterSecond = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        self::assertNotSame($afterFirst, $afterSecond);
    }

    public function testAppendModeSkippedOnInstallWhenAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/test', 'stubs/.gitignore', '*.log');
        $builder->createProjectFile('.gitignore', 'vendor/');

        $provider = $this->makeProviderPackage('yii2-extensions/test', [
            'scaffold' => [
                'file-mapping' => [
                    '.gitignore' => ['source' => 'stubs/.gitignore', 'mode' => 'append'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // First run: full scaffold writes the append and records lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterFirst = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        // Second run: partial (install) — append is already in lock, must be skipped.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), false);

        self::assertSame($afterFirst, file_get_contents($builder->getProjectRoot() . '/.gitignore'));
    }

    public function testEmptyAllowedPackagesIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new FakeProjectBuilder($this->tempDir);
        $root = $this->makeRootPackage([], $builder);

        $this->makeScaffolder([], $builder)->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testLastProviderWinsForSameDestination(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/base', 'stubs/app.php', 'base content');
        $builder->createStubFile('yii2-extensions/override', 'stubs/app.php', 'override content');

        $base = $this->makeProviderPackage('yii2-extensions/base', [
            'scaffold' => [
                'file-mapping' => [
                    'app.php' => ['source' => 'stubs/app.php', 'mode' => 'replace'],
                ],
            ],
        ]);

        $override = $this->makeProviderPackage('yii2-extensions/override', [
            'scaffold' => [
                'file-mapping' => [
                    'app.php' => ['source' => 'stubs/app.php', 'mode' => 'replace'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/base', 'yii2-extensions/override'], $builder);

        $this->makeScaffolder(['yii2-extensions/base', 'yii2-extensions/override'], $builder)
            ->scaffold(
                $root,
                [$base, $override],
                $builder->getProjectRoot(),
                $builder->getVendorDir(),
                true,
            );

        self::assertSame('override content', file_get_contents($builder->getProjectRoot() . '/app.php'));
    }

    public function testLockFileRecordsCorrectHash(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/app-base-scaffold', 'stubs/nginx.conf', 'server {}');

        $provider = $this->makeProviderPackage('yii2-extensions/app-base-scaffold', [
            'scaffold' => [
                'file-mapping' => [
                    'nginx.conf' => ['source' => 'stubs/nginx.conf', 'mode' => 'replace'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold'], $builder);

        $this->makeScaffolder(['yii2-extensions/app-base-scaffold'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $lockFile = new LockFile($builder->getProjectRoot());
        $hash = $lockFile->getHashAtScaffold('nginx.conf');

        self::assertNotNull($hash);
        self::assertStringStartsWith('sha256:', $hash);
        self::assertSame($hash, (new Hasher())->hash($builder->getProjectRoot() . '/nginx.conf'));
    }

    public function testMultipleFilesFromSameProvider(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/test', 'stubs/a.php', 'a');
        $builder->createStubFile('yii2-extensions/test', 'stubs/b.php', 'b');

        $provider = $this->makeProviderPackage('yii2-extensions/test', [
            'scaffold' => [
                'file-mapping' => [
                    'a.php' => ['source' => 'stubs/a.php', 'mode' => 'replace'],
                    'b.php' => ['source' => 'stubs/b.php', 'mode' => 'replace'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);

        $this->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertFileExists($builder->getProjectRoot() . '/a.php');
        self::assertFileExists($builder->getProjectRoot() . '/b.php');

        $lockFile = new LockFile($builder->getProjectRoot());
        self::assertNotNull($lockFile->getHashAtScaffold('a.php'));
        self::assertNotNull($lockFile->getHashAtScaffold('b.php'));
    }

    public function testNoScaffoldExtraOnRootPackageIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new FakeProjectBuilder($this->tempDir);
        $root = self::createStub(PackageInterface::class);
        $root->method('getExtra')->willReturn([]);

        $this->makeScaffolder([], $builder)->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testPreserveFileIsNotOverwritten(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/test', 'stubs/config/params.php', 'stub content');
        $builder->createProjectFile('config/params.php', 'user content');

        $provider = $this->makeProviderPackage('yii2-extensions/test', [
            'scaffold' => [
                'file-mapping' => [
                    'config/params.php' => ['source' => 'stubs/config/params.php', 'mode' => 'preserve'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);

        $this->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertSame('user content', file_get_contents($builder->getProjectRoot() . '/config/params.php'));
    }

    public function testReplaceFileIsWrittenAndLockCreated(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);
        $builder->createStubFile('yii2-extensions/app-base-scaffold', 'stubs/config/params.php', '<?php return [];');

        $provider = $this->makeProviderPackage('yii2-extensions/app-base-scaffold', [
            'scaffold' => [
                'file-mapping' => [
                    'config/params.php' => ['source' => 'stubs/config/params.php', 'mode' => 'replace'],
                ],
            ],
        ]);

        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold'], $builder);

        $this->makeScaffolder(['yii2-extensions/app-base-scaffold'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertFileExists($builder->getProjectRoot() . '/config/params.php');
        self::assertFileExists($builder->getProjectRoot() . '/scaffold-lock.json');
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
     * @param array<string, mixed> $extra
     */
    private function makeProviderPackage(string $name, array $extra): PackageInterface
    {
        $pkg = self::createStub(PackageInterface::class);
        $pkg->method('getName')->willReturn($name);
        $pkg->method('getExtra')->willReturn($extra);

        return $pkg;
    }

    /**
     * @param list<string> $allowedPackages
     */
    private function makeRootPackage(array $allowedPackages, FakeProjectBuilder $builder): PackageInterface
    {
        $pkg = self::createStub(PackageInterface::class);
        $pkg->method('getExtra')->willReturn(
            $allowedPackages !== []
                ? ['scaffold' => ['allowed-packages' => $allowedPackages]]
                : [],
        );

        return $pkg;
    }

    /**
     * @param list<string> $allowedPackages
     */
    private function makeScaffolder(array $allowedPackages, FakeProjectBuilder $builder): Scaffolder
    {
        return new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(
                new PackageAllowlist($allowedPackages),
                new PathValidator(),
                new Hasher(),
                new NullIO(),
            ),
            new LockFile($builder->getProjectRoot()),
            new NullIO(),
        );
    }
}
