<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

/**
 * Scaffold file write modes used in provider manifests and surfaced on {@see FileMapping}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
enum FileMode: string
{
    /**
     * Append the source to the destination on the first apply (skipped on subsequent runs).
     */
    case Append = 'append';

    /**
     * Prepend the source to the destination on the first apply (skipped on subsequent runs).
     */
    case Prepend = 'prepend';

    /**
     * Write only when the destination does not exist; never overwrite without `--force`.
     */
    case Preserve = 'preserve';

    /**
     * Overwrite the destination unless the user has modified it (hash mismatch = skip).
     */

    case Replace = 'replace';
}
