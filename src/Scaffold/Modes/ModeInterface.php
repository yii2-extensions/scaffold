<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;

/**
 * Contract for all scaffold file-application strategies.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
interface ModeInterface
{
    /**
     * Applies a file mapping to the project root and returns the result.
     *
     * @param FileMapping $mapping File mapping to apply.
     * @param string $projectRoot Absolute path to the project root.
     * @param Hasher $hasher Hash utility for computing and comparing file hashes.
     * @param string|null $hashAtScaffold Hash recorded in the lock file at last scaffold time, or `null` if untracked.
     *
     * @return ApplyResult Result of the apply operation, including the outcome, new hash, and any warning message.
     */
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult;
}
