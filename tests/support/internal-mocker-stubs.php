<?php

declare(strict_types=1);

/**
 * Exposes the default {@see \Xepozz\InternalMocker\Mocker} stubs so the extension can generate mock wrappers for
 * internal PHP functions inside scaffold namespaces.
 */
$stubs = require __DIR__ . '/../../vendor/xepozz/internal-mocker/src/stubs.php';

if (is_array($stubs) === false) {
    $stubs = [];
}

// override PHP `8.0+` "optional before required" deprecations by giving `$context` a default null.
$stubs['mkdir'] = [
    'signatureArguments' => 'string $directory, int $permissions = 0777, bool $recursive = false, $context = null',
    'arguments' => '$directory, $permissions, $recursive, $context',
];
$stubs['file_put_contents'] = [
    'signatureArguments' => 'string $filename, mixed $data, int $flags = 0, $context = null',
    'arguments' => '$filename, $data, $flags, $context',
];

return $stubs;
