<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

/**
 * Abstracts `stdout` / `stderr` writes so scaffold service implementations can run unchanged under console controllers,
 * Symfony Console commands, or the standalone `bin/scaffold` binary.
 *
 * Implementations append a single trailing newline to every write (writeln semantics), mirroring Symfony Console's
 * conventions; services pass pre-formatted strings without a trailing `PHP_EOL`.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
interface OutputWriter
{
    /**
     * Writes `$message` to the standard error stream followed by a trailing newline.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStderr(string $message): void;

    /**
     * Writes `$message` to the standard output stream followed by a trailing newline.
     *
     * @param string $message Message without a trailing newline; the writer appends one.
     */
    public function writeStdout(string $message): void;
}
