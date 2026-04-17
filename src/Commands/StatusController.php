<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Displays the status of all scaffold-tracked files relative to their recorded hashes.
 *
 * Usage example:
 * ```bash
 * yii scaffold/status
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class StatusController extends Controller
{
    /**
     * Outputs a table of all scaffold-tracked files with their sync status.
     */
    public function actionIndex(): int
    {
        $statuses = $this->getStatuses(Yii::$app->basePath);

        if ($statuses === []) {
            $this->stdout('[scaffold] No files tracked in scaffold-lock.json.' . PHP_EOL);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-40s %-30s %-10s %s\n", 'File', 'Provider', 'Mode', 'Status'));
        $this->stdout(str_repeat('-', 92) . PHP_EOL);

        foreach ($statuses as $destination => $info) {
            $this->stdout(sprintf(
                "%-40s %-30s %-10s %s\n",
                $destination,
                $info['provider'],
                $info['mode'],
                $info['status'],
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Returns status data for all files tracked in `scaffold-lock.json`.
     *
     * Each entry maps the destination path to a status record containing the provider name,
     * the scaffold mode, and one of: `synced`, `MODIFIED`, or `missing`.
     *
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return array<string, array{provider: string, mode: string, status: string}>
     */
    public function getStatuses(string $projectRoot): array
    {
        $lock = new LockFile($projectRoot);
        $data = $lock->read();
        $hasher = new Hasher();
        $result = [];

        foreach ($data['files'] as $destination => $entry) {
            $absolutePath = $projectRoot . '/' . $destination;

            if (!file_exists($absolutePath)) {
                $status = 'missing';
            } else {
                $currentHash = $hasher->hash($absolutePath);
                $status = $hasher->equals($currentHash, $entry['hash']) ? 'synced' : 'MODIFIED';
            }

            $result[$destination] = [
                'provider' => $entry['provider'],
                'mode' => $entry['mode'],
                'status' => $status,
            ];
        }

        return $result;
    }
}
