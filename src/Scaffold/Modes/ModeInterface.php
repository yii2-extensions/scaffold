<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

/**
 * Contract for all scaffold file-application strategies.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
interface ModeInterface
{
    /**
     * Applies a file mapping to the project root and returns the result.
     *
     * @param FileMapping $mapping The file mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param Hasher $hasher Hash utility for computing and comparing file hashes.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at last scaffold time, or `null` if untracked.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult;
}
