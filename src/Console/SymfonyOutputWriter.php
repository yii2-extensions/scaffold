<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

use Symfony\Component\Console\Output\{ConsoleOutputInterface, OutputInterface};

/**
 * Adapter that bridges {@see OutputWriter} writes to a Symfony Console {@see OutputInterface}.
 *
 * Scaffold services render pre-formatted messages terminated with `PHP_EOL`; this adapter forwards them via `write()`
 * (not `writeln()`) with {@see OutputInterface::OUTPUT_RAW} so the trailing newline and the verbatim byte content stay
 * exactly as the service produced them. The raw option is essential for the `diff` command, whose output can
 * legitimately contain angle-bracket tokens (for example, `<?php echo $user; ?>` in a stub) that the default formatter
 * would otherwise interpret as style tags and corrupt or crash on.
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
     * Writes a message to stderr, bypassing any formatting and without appending a newline.
     *
     * If the underlying output supports separate error output (i.e., implements {@see ConsoleOutputInterface}), the
     * message is sent to that stream; otherwise, it falls back to writing to the main output stream.
     *
     * @param string $message Message to write, which should already include any necessary newlines.
     */
    public function writeStderr(string $message): void
    {
        $sink = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        $sink->write($message, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Writes a message to stdout, bypassing any formatting and without appending a newline.
     *
     * The message should already include any necessary newlines, as this method does not modify the content in any way.
     *
     * @param string $message Message to write.
     */
    public function writeStdout(string $message): void
    {
        $this->output->write($message, false, OutputInterface::OUTPUT_RAW);
    }
}
