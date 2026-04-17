<?php

declare(strict_types=1);

namespace yii\scaffold\Security;

use RuntimeException;

/**
 * Enforces the `extra.scaffold.allowed-packages` allowlist declared in the root project.
 *
 * Only packages explicitly listed in `allowed-packages` may write files to the project.
 * An empty allowlist rejects every package.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class PackageAllowlist
{
    /**
     * @param list<string> $allowedPackages Package names from `extra.scaffold.allowed-packages`.
     */
    public function __construct(
        private readonly array $allowedPackages,
    ) {}

    /**
     * Asserts that the given package is authorized to write scaffold files.
     *
     * @throws RuntimeException when the package is not in the allowlist.
     */
    public function assertAllowed(string $packageName): void
    {
        if (!$this->isAllowed($packageName)) {
            throw new RuntimeException(
                sprintf(
                    'Package "%s" is not authorized to scaffold files. Add it to extra.scaffold.allowed-packages.',
                    $packageName,
                ),
            );
        }
    }

    /**
     * Returns whether the given package name is in the allowlist.
     */
    public function isAllowed(string $packageName): bool
    {
        return in_array($packageName, $this->allowedPackages, strict: true);
    }
}
