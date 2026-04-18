<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use Symfony\Component\Console\Output\{ConsoleOutputInterface, OutputInterface};

/**
 * Adapter that bridges {@see OutputWriter} writes to a Symfony Console {@see OutputInterface}.
 *
 * Scaffold services render pre-formatted messages terminated with `PHP_EOL`; this adapter forwards them via `write()`
 * (not `writeln()`) so the trailing newline stays exactly as the service produced it. When the underlying output is a
 * {@see ConsoleOutputInterface}, stderr writes are routed through `getErrorOutput()`, matching Symfony's
 * "split stdout/stderr" convention.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class SymfonyOutputWriter implements OutputWriter
{
    public function __construct(private readonly OutputInterface $output) {}

    public function writeStderr(string $message): void
    {
        $sink = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        $sink->write($message);
    }

    public function writeStdout(string $message): void
    {
        $this->output->write($message);
    }
}
