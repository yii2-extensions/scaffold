<?php

declare(strict_types=1);

namespace yii\scaffold\Console;

/**
 * Standardized exit codes returned by scaffold services.
 *
 * Backed by `int` so service entry points can return the code via `->value` when called from Console commands
 * controllers, both of which expect an integer exit status.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
enum ExitCode: int
{
    /**
     * Recoverable error for example, an untracked file passed to `diff` / `eject`, an unsafe lock entry, a missing
     * stub, or an I/O failure mid-run.
     */
    case Error = 1;

    /**
     * Success, including preview / no-op runs (for example, `reapply` with no changes to apply, or `eject` without
     * `--yes`).
     */
    case Ok = 0;
}
