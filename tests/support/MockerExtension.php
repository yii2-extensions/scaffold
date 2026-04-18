<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use PHPUnit\Event\Test\{Finished, FinishedSubscriber, PreparationStarted, PreparationStartedSubscriber};
use PHPUnit\Event\TestSuite\{Started, StartedSubscriber};
use PHPUnit\Runner\Extension\{Extension, Facade, ParameterCollection};
use PHPUnit\TextUI\Configuration\Configuration;
use Xepozz\InternalMocker\{Mocker, MockerState};

/**
 * PHPUnit extension that registers internal-function mocks for scaffold namespaces.
 *
 * Wires {@see \Xepozz\InternalMocker\Mocker} into the PHPUnit event loop so the scaffold source namespaces can
 * intercept selected PHP built-ins (`is_readable`, `mkdir`, `is_dir`, `file_put_contents`) during tests that need to
 * simulate filesystem failures or race conditions beyond what a real temporary directory can reproduce.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class MockerExtension implements Extension
{
    /**
     * Registers event subscribers that initialize and reset mock state across the PHPUnit lifecycle.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements StartedSubscriber {
                public function notify(Started $event): void
                {
                    MockerExtension::load();
                }
            },
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    MockerState::resetState();
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    MockerState::resetState();
                }
            },
        );
    }

    /**
     * Loads configured function mocks into scaffold namespaces and snapshots their initial state.
     */
    public static function load(): void
    {
        $mocks = [
            [
                'namespace' => 'yii\\scaffold\\Scaffold\\Lock',
                'name' => 'is_readable',
            ],
            [
                'namespace' => 'yii\\scaffold\\Scaffold\\Modes',
                'name' => 'file_put_contents',
            ],
            [
                'namespace' => 'yii\\scaffold\\Scaffold',
                'name' => 'mkdir',
            ],
            [
                'namespace' => 'yii\\scaffold\\Scaffold',
                'name' => 'is_dir',
            ],
        ];

        $mocksPath = __DIR__ . '/../../runtime/.phpunit.cache/internal-mocker/mocks.php';
        $stubPath = __DIR__ . '/internal-mocker-stubs.php';

        $mocker = new Mocker($mocksPath, $stubPath);

        $mocker->load($mocks);

        MockerState::saveState();
    }
}
