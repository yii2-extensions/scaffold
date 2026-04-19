<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Symfony\Component\Console\Application as SymfonyApplication;
use yii\scaffold\Console\Command\{DiffCommand, EjectCommand, ProvidersCommand, ReapplyCommand, StatusCommand};

/**
 * Symfony Console application that exposes the scaffold plugin's five commands as a standalone CLI, available via
 * `vendor/bin/scaffold` after `composer install`.
 *
 * Works in any PHP project with Composer, regardless of framework (Yii2, Yii3, Laravel, Symfony, Slim, pure PHP).
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Application extends SymfonyApplication
{
    /**
     * Fallback version returned by {@see resolveVersion()} when the package is not installed via Composer (for example,
     * when the source tree is loaded directly without running `composer install` first).
     */
    public const string FALLBACK_VERSION = '0.1.x-dev';

    /**
     * Application / Composer package name.
     */
    public const string NAME = 'yii2-extensions/scaffold';

    public function __construct()
    {
        parent::__construct(self::NAME, self::resolveVersion());

        $this->addCommands(
            [
                new StatusCommand(),
                new ProvidersCommand(),
                new DiffCommand(),
                new EjectCommand(),
                new ReapplyCommand(),
            ],
        );
    }

    /**
     * Returns the installed version of `$package` via Composer's runtime metadata, falling back to
     * {@see FALLBACK_VERSION} when the package is not present in `vendor/composer/installed.php` (missing autoload,
     * dev checkout run outside Composer, etc.).
     *
     * @param string $package Composer package name to query. Defaults to this application's own package name so
     * `vendor/bin/scaffold --version` reports the actual installed version on every tagged release.
     *
     * @return string Semver-compatible version string or the fallback.
     */
    public static function resolveVersion(string $package = self::NAME): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? self::FALLBACK_VERSION;
        } catch (OutOfBoundsException) {
            return self::FALLBACK_VERSION;
        }
    }
}
