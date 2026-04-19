<?php

declare(strict_types=1);

namespace yii\scaffold\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for scaffold Symfony Console commands that need to resolve the project root from the current working
 * directory.
 *
 * Centralizes the `getcwd()` fail-fast check so every command reports a single, consistent diagnostic when the CWD
 * becomes unreadable (for example, after the parent directory is removed) instead of silently propagating an
 * empty project root into {@see \yii\scaffold\Scaffold\Lock\LockFile} or {@see \yii\scaffold\Scaffold\PathResolver}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
abstract class AbstractScaffoldCommand extends Command
{
    /**
     * Returns the current working directory, or `null` after writing an error to `$output` when `getcwd()` fails.
     *
     * Callers must return {@see Command::FAILURE} when the returned value is `null`.
     *
     * @param OutputInterface $output Symfony Console output used for the error message.
     *
     * @return string|null Current working directory, or `null` when it cannot be determined.
     */
    protected function resolveProjectRoot(OutputInterface $output): string|null
    {
        $cwd = getcwd();

        if ($cwd === false) {
            $output->writeln('<error>[scaffold] Unable to determine current working directory.</error>');

            return null;
        }

        return $cwd;
    }
}
