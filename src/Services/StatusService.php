<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use RuntimeException;
use Throwable;
use yii\scaffold\Console\{ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\Security\PathValidator;

use function max;
use function sprintf;
use function str_repeat;

/**
 * Computes the sync status of scaffold-tracked files relative to `scaffold-lock.json`.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class StatusService
{
    /**
     * Returns status data for all files tracked in `scaffold-lock.json` under `$projectRoot`.
     *
     * Each entry maps the destination path to a status record containing the provider name, the scaffold mode, and
     * one of: `synced`, `modified`, `missing`, or `error`.
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
        $validator = new PathValidator();

        $result = [];

        foreach ($data['files'] as $destination => $entry) {
            try {
                $validator->validateDestination($destination, $projectRoot);
            } catch (RuntimeException) {
                $result[$destination] = [
                    'provider' => $entry['provider'],
                    'mode' => $entry['mode'],
                    'status' => 'error',
                ];

                continue;
            }

            $absolutePath = PathResolver::destination($projectRoot, $destination);

            if (!file_exists($absolutePath)) {
                $status = 'missing';
            } else {
                try {
                    $currentHash = $hasher->hash($absolutePath);
                    $status = $hasher->equals($currentHash, $entry['hash']) ? 'synced' : 'modified';
                } catch (Throwable) {
                    $status = 'error';
                }
            }

            $result[$destination] = [
                'provider' => $entry['provider'],
                'mode' => $entry['mode'],
                'status' => $status,
            ];
        }

        return $result;
    }

    /**
     * Renders the status table for all files tracked in `scaffold-lock.json` under `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param OutputWriter $out Output sink used for stdout writes.
     *
     * @return int `0` on success.
     */
    public function run(string $projectRoot, OutputWriter $out): int
    {
        $statuses = $this->getStatuses($projectRoot);

        if ($statuses === []) {
            $out->writeStdout('[scaffold] No files tracked in scaffold-lock.json.' . PHP_EOL);

            return ExitCode::Ok->value;
        }

        $colFile = max(4, max(array_map('strlen', array_keys($statuses))));
        $colProvider = max(8, max(array_map('strlen', array_column($statuses, 'provider'))));
        $colMode = max(4, max(array_map('strlen', array_column($statuses, 'mode'))));
        $colStatus = max(6, max(array_map('strlen', array_column($statuses, 'status'))));
        $separator = str_repeat('-', $colFile + $colProvider + $colMode + $colStatus + 6);

        $out->writeStdout(
            sprintf("%-{$colFile}s  %-{$colProvider}s  %-{$colMode}s  %s", 'File', 'Provider', 'Mode', 'Status')
            . PHP_EOL,
        );
        $out->writeStdout($separator . PHP_EOL);

        foreach ($statuses as $destination => $info) {
            $out->writeStdout(sprintf(
                "%-{$colFile}s  %-{$colProvider}s  %-{$colMode}s  %s",
                $destination,
                $info['provider'],
                $info['mode'],
                $info['status'],
            ) . PHP_EOL);
        }

        return ExitCode::Ok->value;
    }
}
