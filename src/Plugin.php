<?php

declare(strict_types=1);

namespace yii\scaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\{Capable, PluginInterface};

/**
 * Composer plugin entry point for scaffold.
 *
 * Registers the event subscriber that orchestrates multi-layer file scaffolding during `composer install`,
 * `composer update`, and `composer create-project`.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Plugin implements PluginInterface, Capable
{
    /**
     * Event subscriber instance registered with Composer, or `null` if not currently registered.
     */
    private EventSubscriber|null $subscriber = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->subscriber = new EventSubscriber();
        $composer->getEventDispatcher()->addSubscriber($this->subscriber);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        if ($this->subscriber !== null) {
            $composer->getEventDispatcher()->removeListener($this->subscriber);
            $this->subscriber = null;
        }
    }

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
