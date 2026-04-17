<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Removes a file entry from `scaffold-lock.json` without deleting the file from disk.
 *
 * After ejection the file is no longer managed by scaffold and will not be re-applied
 * or overwritten on future runs.
 *
 * Usage example:
 * ```bash
 * yii scaffold/eject config/params.php --yes
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class EjectController extends Controller
{
    /**
     * When `true`, performs the ejection without prompting for confirmation.
     */
    public bool $yes = false;

    /**
     * Removes `$file` from `scaffold-lock.json`.
     *
     * Without `--yes`, only prints what would happen. Requires explicit confirmation
     * to modify the lock file. The on-disk file is never deleted.
     *
     * @param string $file Destination path as recorded in `scaffold-lock.json` (e.g. `config/params.php`).
     */
    public function actionIndex(string $file): int
    {
        $projectRoot = Yii::$app->basePath;
        $lock = new LockFile($projectRoot);
        $data = $lock->read();

        $entry = $data['files'][$file] ?? null;

        if ($entry === null) {
            $this->stderr(
                sprintf('[scaffold] "%s" is not tracked in scaffold-lock.json.', $file) . PHP_EOL,
            );

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->yes) {
            $this->stdout(
                sprintf(
                    '[scaffold] Would remove "%s" from scaffold-lock.json. Run with --yes to confirm.',
                    $file,
                ) . PHP_EOL,
            );

            return ExitCode::OK;
        }

        $updatedFiles = $data['files'];
        unset($updatedFiles[$file]);

        $lock->write(['providers' => $data['providers'], 'files' => $updatedFiles]);

        $this->stdout(
            sprintf(
                '[scaffold] Removed "%s" from scaffold-lock.json. The file was not deleted from disk.',
                $file,
            ) . PHP_EOL,
        );

        return ExitCode::OK;
    }

    /**
     * @param string $actionID
     */
    public function options($actionID): array
    {
        return array_values(array_merge(parent::options($actionID), ['yes']));
    }
}
