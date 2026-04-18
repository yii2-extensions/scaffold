<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support\Spies;

use yii\scaffold\Commands\EjectController;

use function strlen;

/**
 * Test double for {@see EjectController} that buffers `stdout`/`stderr` output into in-memory strings.
 *
 * Yii's base {@see \yii\console\Controller::stdout()} writes directly to the `STDOUT` stream, which bypasses PHPUnit
 * output buffering. This spy overrides both writers so tests can assert on exact emitted content.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class EjectControllerSpy extends EjectController
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
