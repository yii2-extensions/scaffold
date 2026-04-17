<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Represents the outcome of applying a single scaffold file mapping.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ApplyResult
{
    public function __construct(
        public readonly ApplyOutcome $outcome,
        public readonly string $newHash,
        public readonly string|null $warning,
    ) {}
}
