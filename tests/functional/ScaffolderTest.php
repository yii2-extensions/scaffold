<?php

declare(strict_types=1);

namespace yii\scaffold\tests\functional;

use Composer\IO\{BufferIO, IOInterface, NullIO};
use Composer\Package\PackageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use yii\scaffold\Manifest\{ManifestLoader, ManifestSchema};
use yii\scaffold\Scaffold\{Applier, Scaffolder};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
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

    public function testAllowedPackageNotInstalledEmitsSkipMessage(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        // root authorizes a provider that was never added to the installed-packages list.
        $root = $this->makeRootPackage(['yii2-extensions/missing']);

        $io = new BufferIO();
        $scaffolder = new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(
                new PackageAllowlist(['yii2-extensions/missing']),
                new PathValidator(),
                new Hasher(),
                $io,
            ),
            new LockFile($builder->getProjectRoot()),
            $io,
        );

        $scaffolder->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertStringContainsString(
            'Provider "yii2-extensions/missing" is not installed',
            $io->getOutput(),
            'Allowed providers that Composer did not install must emit a clear skip message so typos in '
            . "'allowed-packages' are noticed.",
        );
    }

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
                        '.gitignore' => [
                            'source' => 'stubs/.gitignore',
                            'mode' => 'append',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
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
        $root = $this->makeRootPackage(['yii2-extensions/test']);
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

    public function testApplierExceptionIsLoggedAndScaffoldingContinues(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        /**
         * An allowlist of `['safe/provider']` authorizes only that provider; when the actual provider is
         * `yii2-extensions/test`, the Applier will throw with "Package not allowed" inside apply(). That throw must be
         * caught by the Scaffolder loop and reported via writeError.
         */
        $builder->createStubFile('yii2-extensions/test', 'stubs/app.php', 'content');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);

        $io = new BufferIO();

        // allowlist only admits a DIFFERENT provider, so assertAllowed throws RuntimeException during apply().
        $scaffolder = new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(new PackageAllowlist(['safe/other']), new PathValidator(), new Hasher(), $io),
            new LockFile($builder->getProjectRoot()),
            $io,
        );

        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertStringContainsString(
            'Error applying "app.php"',
            $io->getOutput(),
            'Applier exceptions must be caught per-destination, reported via writeError, and must not abort the rest '
            . 'of the scaffold loop.',
        );
    }

    public function testEmptyAllowedPackagesIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        $builder = new FakeProjectBuilder($this->tempDir);

        $root = $this->makeRootPackage([]);
        $this
            ->makeScaffolder([], $builder)
            ->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testEmptyAllowedPackagesListEmitsSkipMessage(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        // root with `extra.scaffold` present but the allowed-packages list explicitly empty.
        $root = self::createStub(PackageInterface::class);
        $root->method('getExtra')->willReturn(['scaffold' => ['allowed-packages' => []]]);

        $io = new BufferIO();
        $scaffolder = new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(new PackageAllowlist([]), new PathValidator(), new Hasher(), $io),
            new LockFile($builder->getProjectRoot()),
            $io,
        );

        $scaffolder->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertStringContainsString(
            'No allowed-packages configured',
            $io->getOutput(),
            'Empty allowed-packages list (as opposed to missing scaffold extra) must emit the configured-but-empty '
            . 'skip message.',
        );
    }

    public function testFailedManifestLoadIsLoggedAndSkippedInsteadOfFatal(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $provider = $this->makeProviderPackage(
            'yii2-extensions/bad',
            [
                // `manifest: "../escape.json"` triggers a traversal RuntimeException inside the loader.
                'scaffold' => ['manifest' => '../escape.json'],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/bad']);

        $io = new BufferIO();
        $scaffolder = new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(new PackageAllowlist(['yii2-extensions/bad']), new PathValidator(), new Hasher(), $io),
            new LockFile($builder->getProjectRoot()),
            $io,
        );

        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertStringContainsString(
            'Failed to load manifest for "yii2-extensions/bad"',
            $io->getOutput(),
            'A manifest-loader exception must be caught and reported per-provider rather than aborting the entire run.',
        );
    }

    public function testFirstScaffoldPersistsProviderPathInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/app.php', 'a');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        self::assertArrayHasKey(
            'yii2-extensions/test',
            $lockData['providers'],
            'Lock file must persist the provider entry after the first scaffold run.',
        );

        $providerEntry = $lockData['providers']['yii2-extensions/test'] ?? null;

        $expectedPath = str_replace('\\', '/', $builder->getVendorDir() . '/yii2-extensions/test');

        self::assertSame(
            ['version' => '2.0.0', 'path' => $expectedPath],
            $providerEntry,
            'Provider entry must record both version and path (path stays absolute when vendor lives outside the '
            . 'project root, as in this fixture); separators are normalized to forward slashes.',
        );
    }

    public function testFirstScaffoldRecordsRelativePathWhenProviderLivesInsideProject(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        /**
         * Simulate the realistic layout where vendor is nested under the project root; the scaffolder must record a
         * relative path so the committed lock stays stable across machines.
         */
        $providerRootInsideProject = $builder->getProjectRoot() . '/vendor/yii2-extensions/test';

        mkdir($providerRootInsideProject . '/stubs', 0777, recursive: true);
        file_put_contents($providerRootInsideProject . '/stubs/app.php', 'a');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold(
                $root,
                [$provider],
                $builder->getProjectRoot(),
                $builder->getVendorDir(),
                true,
                ['yii2-extensions/test' => $providerRootInsideProject],
            );

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        self::assertSame(
            ['version' => '2.0.0', 'path' => 'vendor/yii2-extensions/test'],
            $lockData['providers']['yii2-extensions/test'] ?? null,
            'When the provider install path lies inside the project root, the lock must store a project-relative path '
            . 'so the committed scaffold-lock.json stays stable across developer machines.',
        );
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
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $override = $this->makeProviderPackage(
            'yii2-extensions/override',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/base', 'yii2-extensions/override']);
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

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        $appEntry = $lockData['files']['app.php'] ?? null;

        self::assertNotNull(
            $appEntry,
            "Lock must contain exactly one entry for 'app.php'.",
        );
        self::assertCount(
            1,
            array_filter(array_keys($lockData['files']), static fn(string $k) => $k === 'app.php'),
            "Lock must contain exactly one entry for 'app.php'.",
        );
        self::assertSame(
            'yii2-extensions/override',
            $appEntry['provider'],
            "Lock entry for 'app.php' must be attributed to the last (winning) provider.",
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
                        'nginx.conf' => [
                            'source' => 'stubs/nginx.conf',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold']);
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

    public function testLogsSkipMessageWhenRootPackageHasNoScaffoldExtra(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $root = self::createStub(PackageInterface::class);

        $root->method('getExtra')->willReturn([]);

        $io = $this->createMock(IOInterface::class);

        $io->expects(self::once())
            ->method('write')
            ->with(self::stringContains('No extra.scaffold configuration'));

        (new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(new PackageAllowlist([]), new PathValidator(), new Hasher(), new NullIO()),
            new LockFile($builder->getProjectRoot()),
            $io,
        ))->scaffold($root, [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
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
                        'a.php' => [
                            'source' => 'stubs/a.php',
                            'mode' => 'replace',
                        ],
                        'b.php' => [
                            'source' => 'stubs/b.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
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

        $this
            ->makeScaffolder([], $builder)
            ->scaffold($this->makeRootPackage([]), [], $builder->getProjectRoot(), $builder->getVendorDir(), true);
    }

    public function testPrefixMatchesHonorsCaseInsensitiveFlag(): void
    {
        $method = new ReflectionMethod(Scaffolder::class, 'prefixMatches');

        // case-sensitive branch matches identical casing but rejects differing casing.
        self::assertTrue(
            (bool) $method->invoke(null, '/Project/vendor/foo', '/Project/', false),
            'Byte-exact comparison must accept an exact casing prefix.',
        );
        self::assertFalse(
            (bool) $method->invoke(null, '/project/vendor/foo', '/Project/', false),
            'Byte-exact comparison must reject a prefix that only differs in casing.',
        );

        /**
         * case-insensitive branch is the Windows path; it must match regardless of casing. Covers the otherwise
         * Linux-unreachable stripos branch used for NTFS compatibility.
         */
        self::assertTrue(
            (bool) $method->invoke(null, '/project/vendor/foo', '/Project/', true),
            'Case-insensitive comparison must accept a prefix that only differs in casing.',
        );
        self::assertFalse(
            (bool) $method->invoke(null, '/elsewhere/vendor/foo', '/Project/', true),
            'Case-insensitive comparison must still reject a prefix that is genuinely different.',
        );
    }

    public function testPrependModeSkippedOnPartialRunWhenAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/prepend.txt', 'header');
        $builder->createProjectFile('prepend.txt', 'existing');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'prepend.txt' => [
                            'source' => 'stubs/prepend.txt',
                            'mode' => 'prepend',
                        ],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // first run records a lock entry for the prepend mapping.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $afterFirst = file_get_contents($builder->getProjectRoot() . '/prepend.txt');

        // ´artial run must leave the prepended file untouched because it already lives in the lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), false);

        self::assertSame(
            $afterFirst,
            file_get_contents($builder->getProjectRoot() . '/prepend.txt'),
            'Prepend mode must be skipped on partial runs when the destination is already recorded in the lock.',
        );
    }

    public function testPreserveDoesNotOverwriteAlreadyTrackedLockEntry(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/config.php', 'stub');
        $builder->createProjectFile('config.php', 'user-content');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'config.php' => [
                            'source' => 'stubs/config.php',
                            'mode' => 'preserve',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // first run records the current hash in the lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $initialHash = (new LockFile($builder->getProjectRoot()))->getHashAtScaffold('config.php');

        // user modifies the file in place; a second preserve run must NOT overwrite the lock entry.
        file_put_contents($builder->getProjectRoot() . '/config.php', 'user-modified');

        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertSame(
            $initialHash,
            (new LockFile($builder->getProjectRoot()))->getHashAtScaffold('config.php'),
            'Preserve mode must not overwrite an already-tracked lock entry even when the on-disk file changes.',
        );
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
                        'config/params.php' => [
                            'source' => 'stubs/config/params.php',
                            'mode' => 'preserve',
                        ],
                    ],
                ],
            ],
        );

        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertSame(
            'user content',
            file_get_contents($builder->getProjectRoot() . '/config/params.php'),
            'Preserve mode file should not be overwritten if it already exists.',
        );

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        $lockEntry = $lockData['files']['config/params.php'] ?? null;

        $userContentHash = (new Hasher())->hash($builder->getProjectRoot() . '/config/params.php');

        if ($lockEntry !== null) {
            self::assertSame(
                $userContentHash,
                $lockEntry['hash'],
                'If a lock entry exists for a preserved file, its hash must match the untouched user content.',
            );
        }
    }

    public function testPreserveRecordsExistingUntrackedFileInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/config.php', 'stub');
        $builder->createProjectFile('config.php', 'user-content');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'config.php' => [
                            'source' => 'stubs/config.php',
                            'mode' => 'preserve',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        self::assertFileExists(
            $builder->getProjectRoot() . '/scaffold-lock.json',
            'Lock file must be written when preserve mode records an existing untracked file.',
        );

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        self::assertArrayHasKey(
            'config.php',
            $lockData['files'],
            'Preserve mode must record untracked existing files in the lock.',
        );

        $entry = $lockData['files']['config.php'] ?? null;

        self::assertNotNull(
            $entry,
            'Lock entry must exist for the preserved file.',
        );
        self::assertSame(
            (new Hasher())->hash($builder->getProjectRoot() . '/config.php'),
            $entry['hash'],
            'Recorded hash must match the untouched user content.',
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
                        'config/params.php' => [
                            'source' => 'stubs/config/params.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/app-base-scaffold']);
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

    public function testReplaceOverwritesLockEntryForPreviouslyTrackedDestination(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/app.php', 'v1');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'app.php' => [
                            'source' => 'stubs/app.php',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $firstHash = (new LockFile($builder->getProjectRoot()))->getHashAtScaffold('app.php');

        // change the stub so the second run writes different content.
        $builder->createStubFile('yii2-extensions/test', 'stubs/app.php', 'v2');
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $secondHash = (new LockFile($builder->getProjectRoot()))->getHashAtScaffold('app.php');

        self::assertNotSame(
            $firstHash,
            $secondHash,
            'Lock entry must be overwritten when replace mode re-writes a previously tracked destination.',
        );
        self::assertSame(
            (new Hasher())->hash($builder->getProjectRoot() . '/app.php'),
            $secondHash,
            'Lock entry must match the hash of the newly written content.',
        );
    }

    public function testScaffoldPersistsUpdatedProviderPathWhenOnlyProviderChanged(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/config.php', 'stub');
        $builder->createProjectFile('config.php', 'user-content');

        $userHash = (new Hasher())->hash($builder->getProjectRoot() . '/config.php');

        /**
         * pre-populate the lock with an outdated provider path but a correct file entry. The upcoming scaffold run must
         * only set `$dirty = true` through the provider-path branch (L141) — the preserve branch leaves `$dirty` alone.
         */
        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => [
                    'yii2-extensions/test' => ['path' => '/obsolete/path'],
                ],
                'files' => [
                    'config.php' => [
                        'hash' => $userHash,
                        'provider' => 'yii2-extensions/test',
                        'source' => 'stubs/config.php',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'config.php' => [
                            'source' => 'stubs/config.php',
                            'mode' => 'preserve',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $this
            ->makeScaffolder(['yii2-extensions/test'], $builder)
            ->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        $providerEntry = $lockData['providers']['yii2-extensions/test'] ?? null;

        $expectedPath = str_replace('\\', '/', $builder->getVendorDir() . '/yii2-extensions/test');

        self::assertSame(
            ['version' => '2.0.0', 'path' => $expectedPath],
            $providerEntry,
            'Provider-entry updates alone must mark the lock dirty so the fresh {version, path} is persisted to disk; '
            . 'path separators are normalized to forward slashes for cross-platform stability.',
        );
    }

    public function testSubsequentFileAppliedAfterFirstEntryIsSkipped(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('yii2-extensions/test', 'stubs/append.txt', 'appended');
        $builder->createStubFile('yii2-extensions/test', 'stubs/fresh.txt', 'fresh content');
        $builder->createProjectFile('append.txt', 'existing');

        $provider = $this->makeProviderPackage(
            'yii2-extensions/test',
            [
                'scaffold' => [
                    'file-mapping' => [
                        'append.txt' => [
                            'source' => 'stubs/append.txt',
                            'mode' => 'append',
                        ],
                        'fresh.txt' => [
                            'source' => 'stubs/fresh.txt',
                            'mode' => 'replace',
                        ],
                    ],
                ],
            ],
        );
        $root = $this->makeRootPackage(['yii2-extensions/test']);
        $scaffolder = $this->makeScaffolder(['yii2-extensions/test'], $builder);

        // first full run records both entries in the lock.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), true);

        // delete `fresh.txt` so the second run must re-create it even though `append.txt` is locked and skipped first.
        unlink($builder->getProjectRoot() . '/fresh.txt');

        // partial run: append.txt is skipped (continue), but the loop must continue to process fresh.txt.
        $scaffolder->scaffold($root, [$provider], $builder->getProjectRoot(), $builder->getVendorDir(), false);

        self::assertFileExists(
            $builder->getProjectRoot() . '/fresh.txt',
            'A file following a skipped append entry must still be applied (loop continues, not breaks).',
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
    private function makeProviderPackage(string $name, array $extra, string $version = '2.0.0'): PackageInterface
    {
        $pkg = self::createStub(PackageInterface::class);

        $pkg->method('getName')->willReturn($name);
        $pkg->method('getExtra')->willReturn($extra);
        $pkg->method('getPrettyVersion')->willReturn($version);

        return $pkg;
    }

    /**
     * @param list<string> $allowedPackages
     */
    private function makeRootPackage(array $allowedPackages): PackageInterface
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
