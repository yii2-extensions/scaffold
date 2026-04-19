<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

/**
 * Immutable value object representing a single file mapping from a scaffold provider.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class FileMapping
{
    public function __construct(
        public readonly string $destination,
        public readonly string $source,
        public readonly FileMode $mode,
        public readonly string $providerName,
        public readonly string $providerPath,
    ) {}
}
