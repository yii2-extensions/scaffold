<?php

declare(strict_types=1);

namespace yii\scaffold\Bridge;

use Composer\IO\IOInterface;

/**
 * Logs an interoperability notice when `yiisoft/yii2-composer` may also be active.
 *
 * Version 1 behavior: notice only — no automatic wiring is performed.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class Yii2ComposerBridge
{
    /**
     * Writes a one-time interop notice to the IO layer.
     */
    public static function logNotice(IOInterface $io): void
    {
        $io->write(
            '[scaffold] Tip: register the scaffold module in your console.php to enable yii scaffold/* commands.',
        );
    }
}
