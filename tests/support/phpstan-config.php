<?php

declare(strict_types=1);

use yii\console\Application;
use yii\scaffold\Module;

return [
    'id' => 'scaffold-test',
    'basePath' => dirname(__DIR__),
    'components' => [],
    'modules' => [
        'scaffold' => Module::class,
    ],
    'phpstan' => [
        'application_type' => Application::class,
    ],
];
