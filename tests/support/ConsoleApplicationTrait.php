<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use yii\console\Application;
use yii\scaffold\Module;

/**
 * Provides a minimal {@see Application} for console controller tests.
 *
 * Creates a temporary project directory, boots a {@see Application} using it as `basePath`, registers the `scaffold`
 * module, and tears everything down after each test.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
trait ConsoleApplicationTrait
{
    use TempDirectoryTrait;

    protected function setUpConsoleApplication(): void
    {
        $this->setUpTempDirectory();

        // intentionally not assigned — Application::__construct() sets Yii::$app as a side effect.
        new Application(
            [
                'id' => 'scaffold-test',
                'basePath' => $this->tempDir,
                'modules' => [
                    'scaffold' => Module::class,
                ],
            ],
        );
    }

    protected function tearDownConsoleApplication(): void
    {
        restore_error_handler();
        restore_exception_handler();

        $this->tearDownTempDirectory();
    }
}
