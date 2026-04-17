<?php

declare(strict_types=1);

namespace yii\scaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin entry point for yii2-extensions/scaffold.
 *
 * Registers the event subscriber that orchestrates multi-layer file scaffolding
 * during `composer install`, `composer update`, and `composer create-project`.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $composer->getEventDispatcher()->addSubscriber(new EventSubscriber());
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [];
    }

    public function uninstall(Composer $composer, IOInterface $io): void {}
}
