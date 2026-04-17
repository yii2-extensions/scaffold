<?php

declare(strict_types=1);

namespace yii\scaffold\Security;

use RuntimeException;

use function in_array;

/**
 * Enforces the `extra.scaffold.allowed-packages` allowlist declared in the root project.
 *
 * Only packages explicitly listed in `allowed-packages` may write files to the project. An empty allowlist rejects
 * every package.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class PackageAllowlist
{
    /**
     * @param list<string> $allowedPackages Package names from `extra.scaffold.allowed-packages`.
     */
    public function __construct(private readonly array $allowedPackages) {}

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
     *
     * @param string $packageName Name of the package to check.
     *
     * @return bool `true` if the package is allowed to scaffold files, `false` otherwise.
     */
    public function isAllowed(string $packageName): bool
    {
        return in_array($packageName, $this->allowedPackages, strict: true);
    }
}
