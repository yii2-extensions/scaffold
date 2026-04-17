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
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Module extends BaseModule
{
    /**
     * @var string Default route when accessing the module without specifying a `controller/action`.
     */
    public $defaultRoute = 'status';

    public function init(): void
    {
        parent::init();

        $this->controllerNamespace = 'yii\\scaffold\\Commands';
    }
}
