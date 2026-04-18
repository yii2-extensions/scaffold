<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

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
     * Application name.
     */
    public const string NAME = 'yii2-extensions/scaffold';
    /**
     * Application version.
     */
    public const string VERSION = '0.1.x-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

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
}
