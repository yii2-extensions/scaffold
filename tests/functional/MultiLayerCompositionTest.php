<?php

declare(strict_types=1);

namespace yii\scaffold\tests\functional;

use Composer\Composer;
use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\EventSubscriber;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\{ComposerEventHarness, FakeProjectBuilder, TempDirectoryTrait};

/**
 * Functional tests for multi-provider composition and deterministic precedence.
 *
 * Exercises the spec's contract that when several authorized providers declare the same destination, the provider
 * listed last in `extra.scaffold.allowed-packages` wins. The lock file must record the winning provider.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('functional')]
final class MultiLayerCompositionTest extends TestCase
{
    use ComposerEventHarness;
    use TempDirectoryTrait;

    public function testLastAllowedPackageWinsForSharedDestination(): void
    {
        $builder = $this->seedTwoProvidersSharingDestination();

        $builder->createComposerJson(
            [
                'name' => 'demo/composition',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    // provider-b is listed last, so its stub must win.
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/provider-a',
                            'demo/provider-b',
                        ],
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvidersSharingDestination($composer);
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        self::assertSame(
            'provider-b content',
            file_get_contents($builder->getProjectRoot() . '/config/web.php'),
            'When multiple allowed providers map the same destination, the last entry in allowed-packages must win.',
        );

        $lockData = (new LockFile($builder->getProjectRoot()))->read();

        self::assertSame(
            'demo/provider-b',
            $lockData['files']['config/web.php']['provider'] ?? null,
            'Lock file must attribute the destination to the winning provider, not the overridden one.',
        );
    }

    public function testSwappedOrderChangesPrecedence(): void
    {
        $builder = $this->seedTwoProvidersSharingDestination();

        $builder->createComposerJson(
            [
                'name' => 'demo/composition',
                'config' => ['vendor-dir' => $builder->getVendorDir()],
                'extra' => [
                    // now provider-a is listed last.
                    'scaffold' => [
                        'allowed-packages' => [
                            'demo/provider-b',
                            'demo/provider-a',
                        ],
                    ],
                ],
            ],
        );

        $io = new BufferIO();

        $composer = $this->buildComposerForProject($builder->getProjectRoot(), $io);
        $this->addMockProvidersSharingDestination($composer);
        $this->resetInstallScaffoldRanFlag();

        (new EventSubscriber())->onPostCreateProject($this->makePostCreateProjectEvent($composer, $io));

        self::assertSame(
            'provider-a content',
            file_get_contents($builder->getProjectRoot() . '/config/web.php'),
            'Swapping the order of allowed-packages must swap which provider wins the shared destination.',
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

    private function addMockProvidersSharingDestination(Composer $composer): void
    {
        $manifest = [
            'file-mapping' => [
                'config/web.php' => [
                    'source' => 'stubs/config/web.php',
                    'mode' => 'replace',
                ],
            ],
        ];

        $this->addMockProvider($composer, 'demo/provider-a', $manifest);
        $this->addMockProvider($composer, 'demo/provider-b', $manifest);
    }

    private function seedTwoProvidersSharingDestination(): FakeProjectBuilder
    {
        $builder = new FakeProjectBuilder($this->tempDir);

        $builder->createStubFile('demo/provider-a', 'stubs/config/web.php', 'provider-a content');
        $builder->createStubFile('demo/provider-b', 'stubs/config/web.php', 'provider-b content');

        return $builder;
    }
}
