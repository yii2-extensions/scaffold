<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use RuntimeException;
use Throwable;
use yii\scaffold\Manifest\ManifestLoader;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Scaffold\Modes\{AppendMode, ApplyOutcome, ModeInterface, PrependMode, PreserveMode, ReplaceMode};

use function is_array;
use function is_string;

/**
 * Orchestrates the full scaffold lifecycle: provider resolution, manifest loading, mode application, and lock writing.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Scaffolder
{
    public function __construct(
        private readonly ManifestLoader $loader,
        private readonly Applier $applier,
        private readonly LockFile $lockFile,
        private readonly IOInterface $io,
    ) {}

    /**
     * Runs the scaffold process for all authorized providers.
     *
     * @param PackageInterface $rootPackage Root Composer package providing the configuration.
     * @param PackageInterface[] $installedPackages All packages currently installed in the project.
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the Composer vendor directory (used as fallback for install paths).
     * @param bool $fullScaffold When `true`, applies all modes including `append` and `prepend`. When `false`, skips
     * `append`/`prepend` for files already recorded in the lock file.
     * @param array<string, string> $installPaths Map of package name to absolute install path resolved by Composer's
     * `InstallationManager`. When provided, these paths take precedence over the default vendor-dir concatenation,
     * enabling correct path resolution for packages with custom installer strategies.
     */
    public function scaffold(
        PackageInterface $rootPackage,
        array $installedPackages,
        string $projectRoot,
        string $vendorDir,
        bool $fullScaffold,
        array $installPaths = [],
    ): void {
        $extra = $rootPackage->getExtra();

        $scaffoldConfig = $extra['scaffold'] ?? null;

        if (!is_array($scaffoldConfig)) {
            $this->io->write('[scaffold] No extra.scaffold configuration found. Skipping scaffold.');

            return;
        }

        $rawAllowed = $scaffoldConfig['allowed-packages'] ?? null;

        if (!is_array($rawAllowed) || $rawAllowed === []) {
            $this->io->write('[scaffold] No allowed-packages configured. Skipping scaffold.');

            return;
        }

        // index installed packages by name for O(1) lookup.
        $byName = [];

        foreach ($installedPackages as $package) {
            $byName[$package->getName()] = $package;
        }

        // merge file mappings in allowed-packages order; last provider wins for duplicate destinations.
        $merged = [];
        $resolvedProviderPaths = [];

        foreach ($rawAllowed as $allowedName) {
            if (!is_string($allowedName)) {
                continue;
            }

            $package = $byName[$allowedName] ?? null;

            if ($package === null) {
                $this->io->write(sprintf('[scaffold] Provider "%s" is not installed. Skipping.', $allowedName));

                continue;
            }

            $packagePath = $installPaths[$allowedName] ?? "{$vendorDir}/{$allowedName}";
            $resolvedProviderPaths[$allowedName] = $packagePath;

            try {
                $mappings = $this->loader->load($package, $packagePath);
            } catch (Throwable $e) {
                $this->io->writeError(
                    sprintf('[scaffold] Failed to load manifest for "%s": %s', $allowedName, $e->getMessage()),
                );

                continue;
            }

            foreach ($mappings as $mapping) {
                $merged[$mapping->destination] = $mapping;
            }
        }

        if ($merged === []) {
            return;
        }

        $lockData = $this->lockFile->read();
        $dirty = false;

        foreach ($resolvedProviderPaths as $providerName => $providerPath) {
            $existing = $lockData['providers'][$providerName] ?? null;
            $storedPath = is_array($existing) && is_string($existing['path'] ?? null) ? $existing['path'] : null;

            if ($storedPath !== $providerPath) {
                $lockData['providers'][$providerName] = ['path' => $providerPath];
                $dirty = true;
            }
        }

        foreach ($merged as $destination => $mapping) {
            if (
                !$fullScaffold
                && ($mapping->mode === 'append' || $mapping->mode === 'prepend')
                && isset($lockData['files'][$destination])
            ) {
                continue;
            }

            try {
                $mode = $this->resolveMode($mapping->mode);
                $hashAtScaffold = $this->extractHash($lockData, $destination);

                $result = $this->applier->apply($mapping, $projectRoot, $mode, $hashAtScaffold);

                if ($result->outcome === ApplyOutcome::Written) {
                    $lockData['files'][$destination] = [
                        'hash' => $result->newHash,
                        'provider' => $mapping->providerName,
                        'source' => $mapping->source,
                        'mode' => $mapping->mode,
                    ];
                    $dirty = true;
                } elseif ($result->newHash !== '' && !isset($lockData['files'][$destination])) {
                    $lockData['files'][$destination] = [
                        'hash' => $result->newHash,
                        'provider' => $mapping->providerName,
                        'source' => $mapping->source,
                        'mode' => $mapping->mode,
                    ];
                    $dirty = true;
                }
            } catch (Throwable $e) {
                $this->io->writeError(
                    sprintf('[scaffold] Error applying "%s": %s', $destination, $e->getMessage()),
                );
            }
        }

        if ($dirty) {
            $this->lockFile->write($lockData);
        }
    }

    /**
     * Extracts the hash for a given destination from the lock data, if it exists.
     *
     * @param array{
     *   providers: array<string, mixed>,
     *   files: array<string, array{hash: string, provider: string, source: string, mode: string}>,
     * } $lockData Decoded lock file data.
     * @param string $destination Destination path to look up.
     *
     * @return string|null Hash if found, or `null` if the destination is not in the lock data.
     */
    private function extractHash(array $lockData, string $destination): string|null
    {
        $entry = $lockData['files'][$destination] ?? null;
        $hash = $entry['hash'] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * Resolves a mode string to its corresponding ModeInterface implementation.
     *
     * @param string $mode Mode name (for example, 'replace', 'preserve', 'append', 'prepend').
     *
     * @throws RuntimeException if the mode name is unknown.
     *
     * @return ModeInterface Corresponding mode implementation.
     */
    private function resolveMode(string $mode): ModeInterface
    {
        return match ($mode) {
            'replace' => new ReplaceMode(),
            'preserve' => new PreserveMode(),
            'append' => new AppendMode(),
            'prepend' => new PrependMode(),
            default => throw new RuntimeException(sprintf('Unknown scaffold mode "%s".', $mode)),
        };
    }
}
