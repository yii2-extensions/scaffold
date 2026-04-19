<?php

declare(strict_types=1);

namespace yii\scaffold\tests\functional;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\EventSubscriber;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\{ComposerEventHarness, FakeProjectBuilder, TempDirectoryTrait};

/**
 * Functional tests for the `post-create-project-cmd` flow and its interaction with `post-install-cmd`.
 *
 * Composer fires both events during `composer create-project`. The plugin must apply the scaffold exactly once, so
 * append/prepend entries are not duplicated. These tests exercise the `$installScaffoldRan` flag end-to-end.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('functional')]
final class CreateProjectTest extends TestCase
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function testCreateProjectAppliesAppendEntryOnFullScaffoldEvenIfAlreadyInLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        // pre-populate the lock so partial scaffold (fullScaffold=false) would skip '.gitignore'.
        $builder->createProjectFile('.gitignore', "existing\n");
        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => [
                    'demo/scaffold' => ['version' => '1.0.0', 'path' => $builder->getVendorDir() . '/demo/scaffold'],
                ],
                'files' => [
                    '.gitignore' => [
                        'hash' => 'sha256:' . hash('sha256', "existing\n"),
                        'provider' => 'demo/scaffold',
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        self::assertSame(
            "existing\n/runtime/\n",
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            "'onPostCreateProject' must dispatch with 'fullScaffold=true' so append entries are applied even when the "
            . "destination is already recorded in the lock; flipping the flag to 'false' would short-circuit the append "
            . "through the 'isset(\$lockData['files'][\$destination])' guard and leave the file untouched.",
        );
    }

    public function testCreateProjectAppliesFullScaffoldWhenInstallDidNotRun(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        self::assertSame(
            "/runtime/\n",
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            "'post-create-project-cmd' alone must apply append-mode entries when post-install-cmd did not run "
            . 'before it.',
        );
    }

    public function testInstallThenCreateProjectDoesNotDuplicateAppendEntries(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        $subscriber = new EventSubscriber();

        // composer create-project fires post-install-cmd first, then post-create-project-cmd within the same process.
        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));
        $subscriber->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        self::assertSame(
            "/runtime/\n",
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            "'post-create-project-cmd' must short-circuit when 'post-install-cmd' already ran so append-mode lines are "
            . 'not concatenated twice within the same Composer invocation.',
        );
    }

    public function testLockRecordsBothFilesAndProvidersAfterCreateProjectFlow(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/config/params.php', "<?php return [];\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    'config/params.php' => [
                        'source' => 'stubs/config/params.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
            '2.5.0',
        );
        $this->resetInstallScaffoldRanFlag();

        $subscriber = new EventSubscriber();

        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));
        $subscriber->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        $providerEntry = $lockData['providers']['demo/scaffold'] ?? null;

        self::assertIsArray(
            $providerEntry,
            'Lock must contain an entry for the authorized provider.',
        );
        self::assertSame(
            '2.5.0',
            $providerEntry['version'] ?? null,
            'Provider version reported by Composer must be persisted into the lock file.',
        );
        self::assertArrayHasKey(
            'config/params.php',
            $lockData['files'],
            'Destinations applied during the create-project flow must be tracked in the lock file.',
        );
    }

    public function testOnPostInstallSkipsAppendEntryAlreadyInLockToAvoidDuplication(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        $builder->createProjectFile('.gitignore', "existing\n");
        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => [
                    'demo/scaffold' => ['version' => '1.0.0', 'path' => $builder->getVendorDir() . '/demo/scaffold'],
                ],
                'files' => [
                    '.gitignore' => [
                        'hash' => 'sha256:' . hash('sha256', "existing\n"),
                        'provider' => 'demo/scaffold',
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostInstall($this->makePostInstallEvent($composer, $io));

        self::assertSame(
            "existing\n",
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            "'onPostInstall' must dispatch with 'fullScaffold=false' so append entries already tracked in the lock are "
            . "skipped; flipping the flag to 'true' would re-apply the stub and duplicate content across composer "
            . 'install invocations.',
        );
    }

    public function testOnPostUpdateSkipsAppendEntryAlreadyInLockToAvoidDuplication(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/scaffold',
                        ],
                    ],
                ],
            ],
        );

        $builder->createProjectFile('.gitignore', "existing\n");
        (new LockFile($builder->getProjectRoot()))->write(
            [
                'providers' => [
                    'demo/scaffold' => ['version' => '1.0.0', 'path' => $builder->getVendorDir() . '/demo/scaffold'],
                ],
                'files' => [
                    '.gitignore' => [
                        'hash' => 'sha256:' . hash('sha256', "existing\n"),
                        'provider' => 'demo/scaffold',
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvider(
            $composer,
            'demo/scaffold',
            [
                'file-mapping' => [
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostUpdate($this->makePostUpdateEvent($composer, $io));

        self::assertSame(
            "existing\n",
            file_get_contents($builder->getProjectRoot() . '/.gitignore'),
            "'onPostUpdate' must dispatch with 'fullScaffold=false' so append entries already tracked in the lock are "
            . "skipped; flipping the flag to 'true' would re-apply the stub and duplicate content across composer "
            . 'update invocations.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
        $this->resetInstallScaffoldRanFlag();
    }
}
