<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold;

use Composer\IO\IOInterface;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\ApplyResult;
use yii\scaffold\Scaffold\Modes\ModeInterface;
use yii\scaffold\Security\PackageAllowlist;
use yii\scaffold\Security\PathValidator;

/**
 * Applies a single scaffold file mapping after running all security pre-checks.
 *
 * Enforces package authorization, path validation, and forwards mode warnings to the IO layer.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
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
     * @param FileMapping $mapping The file mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param ModeInterface $mode The strategy to use for writing the file.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at last scaffold time, or `null` if untracked.
     *
     * @throws \RuntimeException when the provider is unauthorized or a path traversal is detected.
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
