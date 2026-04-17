<?php

declare(strict_types=1);

namespace yii\scaffold\Bridge;

use Composer\IO\IOInterface;

/**
 * Logs an interoperability notice when `yiisoft/yii2-composer` may also be active.
 *
 * Version 1 behavior: notice only no automatic wiring is performed.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class Yii2ComposerBridge
{
    private static bool $noticeLogged = false;

    /**
     * Writes a once-per-process interop notice to the IO layer.
     */
    public static function logNotice(IOInterface $io): void
    {
        if (self::$noticeLogged) {
            return;
        }

        self::$noticeLogged = true;

        $io->write(
            '[scaffold] Tip: register the scaffold module in your config/console.php to enable yii scaffold/* commands.',
        );
    }
}
