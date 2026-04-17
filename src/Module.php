<?php

declare(strict_types=1);

namespace yii\scaffold;

use yii\base\Module as BaseModule;

/**
 * Yii2 console module exposing `yii scaffold/*` commands.
 *
 * Register in `config/console.php`:
 * ```php
 * 'modules' => [
 *     'scaffold' => \yii\scaffold\Module::class,
 * ],
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Module extends BaseModule
{
    /**
     * @var string
     */
    public $defaultRoute = 'status';

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = 'yii\\scaffold\\Commands';
    }
}
