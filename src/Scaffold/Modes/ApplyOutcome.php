<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Enumerates the possible outcomes after applying a scaffold file mapping.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum ApplyOutcome
{
    /** File was skipped — either preserved or user-modified. */
    case Skipped;

    /** File was written but a warning was issued (reserved for future use). */
    case Warned;
    /** File was written or overwritten successfully. */
    case Written;
}
