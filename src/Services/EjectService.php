<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use yii\scaffold\Console\{ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\LockFile;

use function sprintf;

/**
 * Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class EjectService
{
    /**
     * Ejects `$file` from the lock file under `$projectRoot`.
     *
     * Without `$confirmed=true`, only prints what would happen. With it, removes the entry and rewrites the lock.
     * The on-disk file is never deleted.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $file Destination path as recorded in `scaffold-lock.json`.
     * @param bool $confirmed When `true`, performs the ejection. Otherwise only previews.
     * @param OutputWriter $out Output sink.
     *
     * @return int `0` on success (including previews), non-zero when the entry is not tracked.
     */
    public function run(string $projectRoot, string $file, bool $confirmed, OutputWriter $out): int
    {
        $lock = new LockFile($projectRoot);

        $data = $lock->read();

        if (!isset($data['files'][$file])) {
            $out->writeStderr(sprintf('[scaffold] "%s" is not tracked in scaffold-lock.json.', $file));

            return ExitCode::Error->value;
        }

        if (!$confirmed) {
            $out->writeStdout(
                sprintf(
                    '[scaffold] Would remove "%s" from scaffold-lock.json. Run with --yes to confirm.',
                    $file,
                ),
            );

            return ExitCode::Ok->value;
        }

        unset($data['files'][$file]);

        $lock->write($data);

        $out->writeStdout(
            sprintf(
                '[scaffold] Removed "%s" from scaffold-lock.json. The file was not deleted from disk.',
                $file,
            ),
        );

        return ExitCode::Ok->value;
    }
}
