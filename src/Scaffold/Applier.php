<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold;

use Composer\IO\IOInterface;
use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{ApplyResult, ModeInterface};
use yii\scaffold\Security\{PackageAllowlist, PathValidator};

/**
 * Applies a single scaffold file mapping after running all security pre-checks.
 *
 * Enforces package authorization, path validation, and forwards mode warnings to the IO layer.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Applier
{
    public function __construct(
        private readonly PackageAllowlist $allowlist,
        private readonly PathValidator $pathValidator,
        private readonly Hasher $hasher,
        private readonly IOInterface $io,
    ) {}

    /**
     * Validates and applies a single file mapping using the given mode strategy.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param ModeInterface $mode Strategy to use for writing the file.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at last scaffold time, or `null` if untracked.
     *
     * @throws RuntimeException when the provider is unauthorized or a path traversal is detected.
     *
     * @return ApplyResult Result of the apply operation, including any warnings.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        ModeInterface $mode,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $this->allowlist->assertAllowed($mapping->providerName);
        $this->pathValidator->validateDestination($mapping->destination, $projectRoot);
        $this->pathValidator->validateSource($mapping->source, $mapping->providerPath);

        $result = $mode->apply($mapping, $projectRoot, $this->hasher, $hashAtScaffold);

        if ($result->warning !== null) {
            $this->io->writeError('[scaffold] ' . $result->warning);
        }

        return $result;
    }
}
