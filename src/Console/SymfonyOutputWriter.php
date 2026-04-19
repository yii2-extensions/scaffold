<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use Symfony\Component\Console\Output\{ConsoleOutputInterface, OutputInterface};

/**
 * Adapter that bridges {@see OutputWriter} writes to a Symfony Console {@see OutputInterface}.
 *
 * Delegates to `writeln()` with {@see OutputInterface::OUTPUT_RAW} so the verbatim byte content stays exactly as the
 * service produced it and a single trailing newline is appended. The raw option is essential for the `diff` command,
 * whose output can legitimately contain angle-bracket tokens (for example, `<?php echo $user; ?>` in a stub) that the
 * default formatter would otherwise interpret as style tags and corrupt or crash on.
 *
 * When the underlying output is a {@see ConsoleOutputInterface}, stderr writes are routed through `getErrorOutput()`,
 * matching Symfony's "split stdout/stderr" convention.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class SymfonyOutputWriter implements OutputWriter
{
    public function __construct(private readonly OutputInterface $output) {}

    /**
     * Writes `$message` to stderr followed by a single trailing newline, bypassing Symfony's formatter.
     *
     * If the underlying output supports separate error output (i.e., implements {@see ConsoleOutputInterface}), the
     * message is sent to that stream; otherwise, it falls back to writing to the main output stream.
     *
     * @param string $message Message to write.
     */
    public function writeStderr(string $message): void
    {
        $sink = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        $sink->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Writes `$message` to stdout followed by a single trailing newline, bypassing Symfony's formatter.
     *
     * @param string $message Message to write.
     */
    public function writeStdout(string $message): void
    {
        $this->output->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
