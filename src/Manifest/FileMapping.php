<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

/**
 * Immutable value object representing a single file mapping from a scaffold provider.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class FileMapping
{
    public function __construct(
        public readonly string $destination,
        public readonly string $source,
        public readonly string $mode,
        public readonly string $providerName,
        public readonly string $providerPath,
    ) {}
}
