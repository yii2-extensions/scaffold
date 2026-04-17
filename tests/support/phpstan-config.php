<?php

declare(strict_types=1);

return [
    'id' => 'scaffold-test',
    'basePath' => dirname(__DIR__),
    'components' => [],
    'modules' => [
        'scaffold' => \yii\scaffold\Module::class,
    ],
    'phpstan' => [
        'application_type' => \yii\console\Application::class,
    ],
];
