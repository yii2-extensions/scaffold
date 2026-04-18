<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support\Spies;

use yii\scaffold\Commands\StatusController;

use function strlen;

/**
 * Test double for {@see StatusController} that buffers `stdout`/`stderr` output into in-memory strings.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class StatusControllerSpy extends StatusController
{
    /**
     * Accumulated `stderr` output.
     */
    public string $stderrBuffer = '';

    /**
     * Accumulated `stdout` output.
     */
    public string $stdoutBuffer = '';

    public function stderr($string): int
    {
        $this->stderrBuffer .= $string;

        return strlen($string);
    }

    public function stdout($string): int
    {
        $this->stdoutBuffer .= $string;

        return strlen($string);
    }
}
