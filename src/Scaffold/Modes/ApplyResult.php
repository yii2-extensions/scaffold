<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

/**
 * Represents the outcome of applying a single scaffold file mapping.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final readonly class ApplyResult
{
    public function __construct(
        public ApplyOutcome $outcome,
        public string $newHash,
        public string|null $warning,
    ) {}
}
