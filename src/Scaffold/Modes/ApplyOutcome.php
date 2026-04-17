<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Enumerates the possible outcomes after applying a scaffold file mapping.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
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
