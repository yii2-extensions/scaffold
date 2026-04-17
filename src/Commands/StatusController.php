<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};

use function sprintf;

/**
 * Displays the status of all scaffold-tracked files relative to their recorded hashes.
 *
 * Usage example:
 * ```bash
 * yii scaffold/status
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class StatusController extends Controller
{
    /**
     * Outputs a table of all scaffold-tracked files with their sync status.
     *
     * @return int Exit code indicating success or failure of the command.
     */
    public function actionIndex(): int
    {
        $statuses = $this->getStatuses(Yii::$app->basePath);

        if ($statuses === []) {
            $this->stdout(
                '[scaffold] No files tracked in scaffold-lock.json.' . PHP_EOL,
            );

            return ExitCode::OK;
        }

        $colFile = max(4, max(array_map('strlen', array_keys($statuses))));
        $colProvider = max(8, max(array_map('strlen', array_column($statuses, 'provider'))));
        $colMode = max(4, max(array_map('strlen', array_column($statuses, 'mode'))));
        $colStatus = max(6, max(array_map('strlen', array_column($statuses, 'status'))));
        $separator = str_repeat('-', $colFile + $colProvider + $colMode + $colStatus + 6);

        $this->stdout(
            sprintf("%-{$colFile}s  %-{$colProvider}s  %-{$colMode}s  %s", 'File', 'Provider', 'Mode', 'Status')
            . PHP_EOL,
        );
        $this->stdout(
            $separator . PHP_EOL,
        );

        foreach ($statuses as $destination => $info) {
            $this->stdout(sprintf(
                "%-{$colFile}s  %-{$colProvider}s  %-{$colMode}s  %s",
                $destination,
                $info['provider'],
                $info['mode'],
                $info['status'],
            ) . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Returns status data for all files tracked in `scaffold-lock.json`.
     *
     * Each entry maps the destination path to a status record containing the provider name, the scaffold mode, and one
     * of: `synced`, `modified`, or `missing`.
     *
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return array<string, array{provider: string, mode: string, status: string}> An associative array of file
     * statuses keyed by their destination paths.
     */
    public function getStatuses(string $projectRoot): array
    {
        $lock = new LockFile($projectRoot);

        $data = $lock->read();

        $hasher = new Hasher();

        $result = [];

        foreach ($data['files'] as $destination => $entry) {
            $absolutePath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($destination, '/\\');

            if (!file_exists($absolutePath)) {
                $status = 'missing';
            } else {
                $currentHash = $hasher->hash($absolutePath);
                $status = $hasher->equals($currentHash, $entry['hash']) ? 'synced' : 'modified';
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
