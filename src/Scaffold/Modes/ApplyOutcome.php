<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Enumerates the possible outcomes after applying a scaffold file mapping.
 */
enum ApplyOutcome
{
    /**
     * File was skipped either preserved or user-modified.
     */
    case Skipped;

    /**
     * File was written or overwritten successfully.
     */
    case Written;
}
