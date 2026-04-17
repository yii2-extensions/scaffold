<?php

declare(strict_types=1);

namespace yii\scaffold;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use yii\scaffold\Bridge\Yii2ComposerBridge;
use yii\scaffold\Manifest\ManifestLoader;
use yii\scaffold\Manifest\ManifestSchema;
use yii\scaffold\Scaffold\Applier;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Scaffold\Scaffolder;
use yii\scaffold\Security\PackageAllowlist;
use yii\scaffold\Security\PathValidator;

/**
 * Listens to Composer script events and triggers the scaffold workflow.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class EventSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProject',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    public function onPostCreateProject(Event $event): void
    {
        $this->runScaffold($event, fullScaffold: true);
    }

    public function onPostInstall(Event $event): void
    {
        $this->runScaffold($event, fullScaffold: false);
    }

    public function onPostUpdate(Event $event): void
    {
        $this->runScaffold($event, fullScaffold: false);
    }

    /**
     * @param list<string> $allowedPackages
     */
    private function buildScaffolder(array $allowedPackages, string $projectRoot, IOInterface $io): Scaffolder
    {
        return new Scaffolder(
            new ManifestLoader(new ManifestSchema()),
            new Applier(new PackageAllowlist($allowedPackages), new PathValidator(), new Hasher(), $io),
            new LockFile($projectRoot),
            $io,
        );
    }

    /**
     * @param array<mixed> $extra
     *
     * @return list<string>
     */
    private function extractAllowedPackages(array $extra, IOInterface $io): array
    {
        $scaffoldConfig = $extra['scaffold'] ?? null;

        if (!is_array($scaffoldConfig)) {
            return [];
        }

        $rawAllowed = $scaffoldConfig['allowed-packages'] ?? null;

        if (!is_array($rawAllowed)) {
            return [];
        }

        $allowed = [];

        foreach ($rawAllowed as $item) {
            if (is_string($item)) {
                $allowed[] = $item;
            } else {
                $io->writeError('[scaffold] Non-string entry in allowed-packages ignored.');
            }
        }

        return $allowed;
    }

    private function runScaffold(Event $event, bool $fullScaffold): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectRoot = dirname($composer->getConfig()->getConfigSource()->getName());
        $allowedPackages = $this->extractAllowedPackages($composer->getPackage()->getExtra(), $io);
        $scaffolder = $this->buildScaffolder($allowedPackages, $projectRoot, $io);

        $localRepo = $composer->getRepositoryManager()->getLocalRepository();

        $scaffolder->scaffold(
            $composer->getPackage(),
            $localRepo->getPackages(),
            $projectRoot,
            $vendorDir,
            $fullScaffold,
        );

        Yii2ComposerBridge::logNotice($io);
    }
}
