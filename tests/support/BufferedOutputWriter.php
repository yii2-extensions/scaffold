<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support;

use yii\scaffold\Console\OutputWriter;

/**
 * In-memory {@see OutputWriter} implementation that buffers stdout / stderr writes into public strings for assertion.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class BufferedOutputWriter implements OutputWriter
{
    /**
     * Buffers all messages written to stderr.
     */
    public string $stderrBuffer = '';
    /**
     * Buffers all messages written to stdout.
     */
    public string $stdoutBuffer = '';

    /**
     * Writes a message to stderr.
     *
     * @param string $message Message to write.
     */
    public function writeStderr(string $message): void
    {
        $this->stderrBuffer .= $message;
    }

    /**
     * Writes a message to stdout.
     *
     * @param string $message Message to write.
     */
    public function writeStdout(string $message): void
    {
        $this->stdoutBuffer .= $message;
    }
}
