<?php

declare(strict_types=1);

namespace yii\scaffold\Manifest;

/**
 * Immutable value object representing a single file mapping from a scaffold provider.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final readonly class FileMapping
{
    public function __construct(
        public string $destination,
        public string $source,
        public FileMode $mode,
        public string $providerName,
        public string $providerPath,
    ) {}
}
