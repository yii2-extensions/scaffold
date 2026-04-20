<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use yii\scaffold\Console\SymfonyOutputWriter;

/**
 * Unit tests for {@see SymfonyOutputWriter} verifying that stdout / stderr writes bypass Symfony's output formatter so
 * pre-formatted service messages (for example, diffs that contain angle-bracket tokens) land verbatim.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console')]
final class SymfonyOutputWriterTest extends TestCase
{
    public function testWriteStderrFallsBackToPrimaryOutputWhenNotConsoleOutputInterface(): void
    {
        $output = new BufferedOutput();

        (new SymfonyOutputWriter($output))->writeStderr('plain <output> fallback');

        self::assertSame(
            'plain <output> fallback' . PHP_EOL,
            $output->fetch(),
            "Without 'ConsoleOutputInterface', stderr writes must fall back to the primary output with a trailing newline.",
        );
    }

    public function testWriteStderrRoutesThroughErrorOutputOfConsoleOutputInterface(): void
    {
        $console = new ConsoleOutput();

        self::assertInstanceOf(
            BufferedOutput::class,
            $this->overrideErrorOutput($console),
            'Test setup: the error output must be swapped for a BufferedOutput so we can read it back.',
        );

        (new SymfonyOutputWriter($console))->writeStderr('error <bold>line</bold>');

        /** @var BufferedOutput $swapped */
        $swapped = $console->getErrorOutput();

        self::assertSame(
            'error <bold>line</bold>' . PHP_EOL,
            $swapped->fetch(),
            "'writeStderr' must route through 'getErrorOutput()' and preserve angle-bracket tokens verbatim.",
        );
    }
    public function testWriteStdoutEmitsAngleBracketTokensVerbatimWithoutFormatterInterpretation(): void
    {
        $output = new BufferedOutput();

        (new SymfonyOutputWriter($output))->writeStdout('<?php echo $user; ?>');

        self::assertSame(
            '<?php echo $user; ?>' . PHP_EOL,
            $output->fetch(),
            'Angle-bracket tokens inside a stdout write must survive verbatim without Symfony formatter interpretation.',
        );
    }

    public function testWriteStdoutEmitsSymfonyStyleTagsLiterallyWithoutApplyingAnsi(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: true);

        (new SymfonyOutputWriter($output))->writeStdout('<info>hello</info>');

        self::assertSame(
            '<info>hello</info>' . PHP_EOL,
            $output->fetch(),
            'Symfony-style tags must be written literally (no ANSI codes) even when decoration is enabled.',
        );
    }

    /**
     * Overrides the error output of a ConsoleOutput instance with a BufferedOutput for testing purposes.
     *
     * @param ConsoleOutput $console ConsoleOutput instance to modify.
     *
     * @return BufferedOutput BufferedOutput instance used as the new error output.
     */
    private function overrideErrorOutput(ConsoleOutput $console): BufferedOutput
    {
        $buffered = new BufferedOutput();
        $console->setErrorOutput($buffered);

        return $buffered;
    }
}
