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
 * Registers the event subscriber that orchestrates multi-layer file scaffolding during `composer install`,
 * `composer update`, and `composer create-project`.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $composer->getEventDispatcher()->addSubscriber(new EventSubscriber());
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<string, string> An associative array mapping capability class names to their implementing class
     * names.
     */
    public function getCapabilities(): array
    {
        return [];
    }

    public function uninstall(Composer $composer, IOInterface $io): void {}
}
