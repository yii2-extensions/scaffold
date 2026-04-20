<?php

declare(strict_types=1);

namespace yii\scaffold;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Script\{Event, ScriptEvents};
use Throwable;
use yii\scaffold\Manifest\{ManifestExpander, ManifestLoader, ManifestSchema};
use yii\scaffold\Scaffold\{Applier, Scaffolder};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Security\{PackageAllowlist, PathValidator};

use function is_array;
use function is_string;
use function sprintf;

/**
 * Listens to Composer script events and triggers the scaffold workflow.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class EventSubscriber implements EventSubscriberInterface
{
    /**
     * Set to `true` after `onPostInstall` executes in the current process.
     *
     * During `composer create-project`, Composer fires `post-install-cmd` before `post-create-project-cmd` within the
     * same process. The install run applies all scaffold entries (including `append`/`prepend`) because the lock is
     * empty. If the create-project handler were also to run a full scaffold, those entries would be re-applied and
     * duplicated. This flag prevents that second run.
     */
    private static bool $installScaffoldRan = false;

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

    /**
     * Handles the post-create-project event to perform a full scaffold.
     *
     * Skipped when `post-install-cmd` already ran in this process (i.e., during `composer create-project`), since the
     * partial install scaffold with an empty lock is equivalent to a full scaffold.
     *
     * @param Event $event Composer event object containing context for the operation.
     */
    public function onPostCreateProject(Event $event): void
    {
        if (self::$installScaffoldRan) {
            return;
        }

        $this->runScaffold($event, fullScaffold: true);
    }

    /**
     * Handles the post-install event to perform a partial scaffold.
     *
     * @param Event $event Composer event object containing context for the operation.
     */
    public function onPostInstall(Event $event): void
    {
        self::$installScaffoldRan = true;

        $this->runScaffold($event, fullScaffold: false);
    }

    /**
     * Handles the post-update event to perform a partial scaffold.
     *
     * @param Event $event Composer event object containing context for the operation.
     */
    public function onPostUpdate(Event $event): void
    {
        $this->runScaffold($event, fullScaffold: false);
    }

    /**
     * Builds the Scaffolder instance with the necessary components.
     *
     * @param list<string> $allowedPackages List of package names that are allowed to be scaffolded, extracted from
     * `composer.json`.
     * @param string $projectRoot Absolute path to the project root.
     * @param IOInterface $io Composer IO interface for user interaction.
     *
     * @return Scaffolder Configured Scaffolder instance.
     */
    private function buildScaffolder(array $allowedPackages, string $projectRoot, IOInterface $io): Scaffolder
    {
        return new Scaffolder(
            new ManifestLoader(new ManifestSchema(), new ManifestExpander()),
            new Applier(new PackageAllowlist($allowedPackages), new PathValidator(), new Hasher(), $io),
            new LockFile($projectRoot),
            $io,
        );
    }

    /**
     * Extracts the list of allowed packages from the `extra` section of `composer.json`.
     *
     * @param array<mixed> $extra  `extra` section from the root package's `composer.json`, which may contain scaffold
     * configuration.
     * @param IOInterface $io Composer IO interface for logging warnings about invalid configuration entries.
     *
     * @return list<string> List of allowed package names that can be scaffolded. If the configuration is missing or
     * invalid, an empty list is returned.
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

    /**
     * Executes the scaffold process based on the given Composer event and scaffold type.
     *
     * @param Event $event Composer event object containing context for the operation.
     * @param bool $fullScaffold Whether to perform a full scaffold (`true` for post-create-project) or a partial
     * scaffold (`true` for post-install and post-update).
     */
    private function runScaffold(Event $event, bool $fullScaffold): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();
        $vendorDirRaw = $composer->getConfig()->get('vendor-dir');

        if ($vendorDirRaw === '') {
            $io->writeError('[scaffold] Unable to resolve vendor-dir from Composer config; aborting scaffold.');

            return;
        }

        $vendorDirResolved = realpath($vendorDirRaw);
        $vendorDir = $vendorDirResolved !== false ? $vendorDirResolved : $vendorDirRaw;

        $projectRootRaw = dirname($composer->getConfig()->getConfigSource()->getName());
        $projectRootResolved = realpath($projectRootRaw);
        $projectRoot = $projectRootResolved !== false ? $projectRootResolved : $projectRootRaw;

        $allowedPackages = $this->extractAllowedPackages($composer->getPackage()->getExtra(), $io);
        $scaffolder = $this->buildScaffolder($allowedPackages, $projectRoot, $io);
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $composer->getInstallationManager();

        $installPaths = [];

        foreach ($localRepo->getPackages() as $package) {
            $path = $installationManager->getInstallPath($package);

            if ($path !== null) {
                $installPaths[$package->getName()] = $path;
            }
        }

        try {
            $scaffolder->scaffold(
                $composer->getPackage(),
                $localRepo->getPackages(),
                $projectRoot,
                $vendorDir,
                $fullScaffold,
                $installPaths,
            );
        } catch (Throwable $e) {
            $io->writeError(sprintf('[scaffold] Scaffolding aborted: %s', $e->getMessage()));
        }
    }
}
