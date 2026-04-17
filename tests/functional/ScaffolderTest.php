<?php

declare(strict_types=1);

namespace yii\scaffold\tests\functional;

use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Manifest\{ManifestLoader, ManifestSchema};
use yii\scaffold\Scaffold\Applier;
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Scaffold\Scaffolder;
use yii\scaffold\Security\{PackageAllowlist, PathValidator};
use yii\scaffold\tests\support\{FakeProjectBuilder, TempDirectoryTrait};

/**
 * Functional tests for {@see Scaffolder} end-to-end scaffold execution.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('functional')]
final class ScaffolderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testAppendModeAppliedOnFullScaffoldEvenIfAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/.gitignore', '*.log');
        $builder->createProjectFile('.gitignore', 'vendor/');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        '.gitignore' => ['source' => 'stubs/.gitignore', 'mode' => 'append'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // first run: full scaffold writes append.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterFirst = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        // second full scaffold: re-appends even though entry exists in lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterSecond = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        self::assertNotSame(
            $afterFirst,
            $afterSecond,
            'Append mode file should be re-appended on full scaffold even if already in lock.',
        );
    }

    public function testAppendModeSkippedOnInstallWhenAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/.gitignore', '*.log');
        $builder->createProjectFile('.gitignore', 'vendor/');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        '.gitignore' => ['source' => 'stubs/.gitignore', 'mode' => 'append'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // first run: full scaffold writes the append and records lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterFirst = file_get_contents($builder->getProjectRoot() . '/.gitignore');

        // second run: partial (install) — append is already in lock, must be skipped.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), false);

        self::assertSame(
            $afterFirst,
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            'Append mode file should not be re-appended on install if already in lock.',
        );
    }

    public function testEmptyAllowedPackagesIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new FakeProjectBuilder($this->tempDir);

        $root = $this->makeRootPackage([], $builder);

        $this
            ->makeScaffolder([], $builder)
            ->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testLastProviderWinsForSameDestination(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/base', 'stubs/app.php', 'base content');
        $builder->createStubFile('yii2-extensions/override', 'stubs/app.php', 'override content');

        $base = $this->makeProviderPackage(
            'yii2-extensions/base',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => ['source' => 'stubs/app.php', 'mode' => 'replace'],
                    ],
                ],
            ],
        );

        $override = $this->makeProviderPackage(
            'yii2-extensions/override',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => ['source' => 'stubs/app.php', 'mode' => 'replace'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/base', 'yii2-extensions/override'], $builder);

        $this
            ->makeScaffolder(['yii2-extensions/base', 'yii2-extensions/override'], $builder)
            ->scaffold(
                $root,
                [$base, $override],
                $builder->getProjectRoot(),
                $builder->getVendorDir(),
                true,
            );

        self::assertSame(
            'override content',
            file_get_contents($builder->getProjectRoot() . '/app.php'),
            'When multiple providers write to the same destination, the last provider in the list should win.',
        );
    }

    public function testLockFileRecordsCorrectHash(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/app-base-scaffold', 'stubs/nginx.conf', 'server {}');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/app-base-scaffold',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'nginx.conf' => ['source' => 'stubs/nginx.conf', 'mode' => 'replace'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold'], $builder);

        $this
            ->makeScaffolder(['yii2-extensions/app-base-scaffold'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $lockFile = new LockFile($builder->getProjectRoot());

        $hash = $lockFile->getHashAtScaffold('nginx.conf');

        self::assertNotNull(
            $hash,
            'Lock file should contain a hash entry for the scaffolded file.',
        );
        self::assertStringStartsWith(
            'sha256:',
            $hash,
            'Hash should be prefixed with sha256:',
        );
        self::assertSame(
            $hash,
            (new Hasher())->hash($builder->getProjectRoot() . '/nginx.conf'),
            'Hash should match the computed hash of the scaffolded file.',
        );
    }

    public function testMultipleFilesFromSameProvider(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/a.php', 'a');
        $builder->createStubFile('yii2-extensions/test', 'stubs/b.php', 'b');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'a.php' => ['source' => 'stubs/a.php', 'mode' => 'replace'],
                        'b.php' => ['source' => 'stubs/b.php', 'mode' => 'replace'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);

        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertFileExists(
            $builder->getProjectRoot() . '/a.php',
            'First scaffolded file should exist.',
        );
        self::assertFileExists(
            $builder->getProjectRoot() . '/b.php',
            'Second scaffolded file should exist.',
        );

        $lockFile = new LockFile($builder->getProjectRoot());

        self::assertNotNull(
            $lockFile->getHashAtScaffold('a.php'),
            'Lock file should contain a hash entry for the first scaffolded file.',
        );
        self::assertNotNull(
            $lockFile->getHashAtScaffold('b.php'),
            'Lock file should contain a hash entry for the second scaffolded file.',
        );
    }

    public function testNoScaffoldExtraOnRootPackageIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new FakeProjectBuilder($this->tempDir);

        $root = self::createStub(PackageInterface::class);

        $root->method('getExtra')->willReturn([]);

        $this
            ->makeScaffolder([], $builder)
            ->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testPreserveFileIsNotOverwritten(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/config/params.php', 'stub content');
        $builder->createProjectFile('config/params.php', 'user content');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'config/params.php' => ['source' => 'stubs/config/params.php', 'mode' => 'preserve'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test'], $builder);

        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertSame(
            'user content',
            file_get_contents($builder->getProjectRoot() . '/config/params.php'),
            'Preserve mode file should not be overwritten if it already exists.',
        );
    }

    public function testReplaceFileIsWrittenAndLockCreated(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/app-base-scaffold', 'stubs/config/params.php', '<?php return [];');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/app-base-scaffold',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'config/params.php' => ['source' => 'stubs/config/params.php', 'mode' => 'replace'],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold'], $builder);

        $this
            ->makeScaffolder(['yii2-extensions/app-base-scaffold'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertFileExists(
            $builder->getProjectRoot() . '/config/params.php',
            'Replace mode file should be created if it does not exist.',
        );
        self::assertFileExists(
            $builder->getProjectRoot() . '/scaffold-lock.json',
            'Lock file should be created when replace mode file is written.',
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

        $pkg
            ->method('getExtra')
            ->willReturn(
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
