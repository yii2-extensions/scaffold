<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Represents the outcome of applying a single scaffold file mapping.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ApplyResult
{
    public function __construct(
        public readonly ApplyOutcome $outcome,
        public readonly string $newHash,
        public readonly string|null $warning,
    ) {}
}
