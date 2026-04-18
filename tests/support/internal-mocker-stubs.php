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
$stubs['file_get_contents'] = [
    'signatureArguments' => 'string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null',
    'arguments' => '$filename, $use_include_path, $context, $offset, $length',
];
$stubs['rename'] = [
    'signatureArguments' => 'string $from, string $to, $context = null',
    'arguments' => '$from, $to, $context',
];
$stubs['copy'] = [
    'signatureArguments' => 'string $from, string $to, $context = null',
    'arguments' => '$from, $to, $context',
];

return $stubs;
