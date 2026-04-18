<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

/**
 * Abstracts `stdout` / `stderr` writes so scaffold service implementations can run unchanged under console controllers,
 * Symfony Console commands, or the standalone `bin/scaffold` binary.
 *
 * Implementations are responsible for appending newlines only when the caller supplies them; services pass
 * pre-formatted strings (including `PHP_EOL`) and expect them to be written verbatim.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
interface OutputWriter
{
    /**
     * Writes `$message` to the standard error stream.
     *
     * @param string $message Pre-formatted message, typically terminated with `PHP_EOL`.
     */
    public function writeStderr(string $message): void;

    /**
     * Writes `$message` to the standard output stream.
     *
     * @param string $message Pre-formatted message, typically terminated with `PHP_EOL`.
     */
    public function writeStdout(string $message): void;
}
