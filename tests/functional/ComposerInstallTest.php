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
 * Functional tests for the `post-install-cmd` flow using a real {@see \Composer\Composer} instance.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('functional')]
final class ComposerInstallTest extends TestCase
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function testFirstPostInstallWritesFilesAndLock(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/config/params.php', "<?php return ['adminEmail' => 'a@b.c'];\n");
        $builder->createStubFile('demo/scaffold', 'stubs/.gitignore', "/runtime/\n");
        $builder->createComposerJson(
            [
                'name' => 'demo/smoke-project',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    'scaffold' => ['allowed-packages' => ['demo/scaffold']],
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
                        'mode' => 'preserve',
                    ],
                    '.gitignore' => [
                        'source' => 'stubs/.gitignore',
                        'mode' => 'append',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostInstall($this->makePostInstallEvent($composer, $io));

        self::assertFileExists(
            $builder->getProjectRoot() . '/config/params.php',
            "First 'post-install-cmd' must materialize preserve-mode files from the provider stub.",
        );
        self::assertFileExists(
            $builder->getProjectRoot() . '/.gitignore',
            "First 'post-install-cmd' must materialize append-mode files from the provider stub.",
        );

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        self::assertArrayHasKey(
            'demo/scaffold',
            $lockData['providers'],
            "Lock must record the provider after the first 'post-install-cmd'.",
        );
        self::assertArrayHasKey(
            'config/params.php',
            $lockData['files'],
            "Lock must track every destination written during 'post-install-cmd'.",
        );
        self::assertArrayHasKey(
            '.gitignore',
            $lockData['files'],
            "Lock must track every destination written during 'post-install-cmd'.",
        );
    }

    public function testReplaceModeWarnsAndSkipsWhenUserModifiedBetweenRuns(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.env.dist', "APP_ENV=dev\n");
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
                    '.env.dist' => [
                        'source' => 'stubs/.env.dist',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        $subscriber = new EventSubscriber();

        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));

        // simulate the user editing the replace-mode file between two install runs.
        file_put_contents(
            $builder->getProjectRoot() . '/.env.dist',
            "APP_ENV=production\nUSER_EDIT=1\n",
        );

        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));

        self::assertStringContainsString(
            'User-modified file skipped: ".env.dist".',
            $io->getOutput(),
            'Replace mode must emit a user-modified warning when the current file diverges from the scaffold hash.',
        );

        $envContent = file_get_contents($builder->getProjectRoot() . '/.env.dist');

        self::assertIsString(
            $envContent,
            'Replace-mode destination must remain readable after scaffolding.',
        );
        self::assertStringContainsString(
            'USER_EDIT=1',
            $envContent,
            'Replace mode must preserve the user edit instead of reverting to the provider stub.',
        );
    }

    public function testSecondPostInstallPreservesContentAndLockWhenUnmodified(): void
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/scaffold', 'stubs/.env.dist', "APP_ENV=dev\n");
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
                    '.env.dist' => [
                        'source' => 'stubs/.env.dist',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );
        $this->resetInstallScaffoldRanFlag();

        $subscriber = new EventSubscriber();

        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));

        $lockAfterFirst = file_get_contents($builder->getProjectRoot() . '/scaffold-lock.json');

        $subscriber->onPostInstall($this->makePostInstallEvent($composer, $io));

        self::assertSame(
            "APP_ENV=dev\n",
            file_get_contents($builder->getProjectRoot() . '/.env.dist'),
            "Second 'post-install-cmd' must not corrupt a replace-mode file whose hash still matches the lock.",
        );
        self::assertSame(
            $lockAfterFirst,
            file_get_contents($builder->getProjectRoot() . '/scaffold-lock.json'),
            "Second 'post-install-cmd' must not grow or mutate the lock when no scaffold state actually changed.",
        );
        self::assertStringNotContainsString(
            'User-modified file skipped',
            $io->getOutput(),
            'No user-modified warning must be emitted when the file hash still matches what the lock recorded.',
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
