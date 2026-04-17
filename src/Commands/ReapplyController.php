<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Re-applies scaffold stubs to the project, optionally overwriting user-modified files.
 *
 * Usage example:
 * ```bash
 * yii scaffold/reapply
 * yii scaffold/reapply config/params.php
 * yii scaffold/reapply config/params.php --force
 * yii scaffold/reapply --provider=yii2-extensions/app-base-scaffold
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ReapplyController extends Controller
{
    /**
     * When `true`, overwrites user-modified files without prompting.
     */
    public bool $force = false;

    /**
     * Re-applies scaffold files from vendor stubs, updating lock hashes on success.
     *
     * @param string $file Optional destination path to reapply (e.g. `config/params.php`).
     *                     When empty, all tracked files are processed.
     * @param string $provider Optional provider package name filter (e.g. `yii2-extensions/app-base-scaffold`).
     *                         When empty, all providers are processed.
     */
    public function actionIndex(string $file = '', string $provider = ''): int
    {
        $projectRoot = Yii::$app->basePath;
        $vendorDir = $projectRoot . '/vendor';
        $lock = new LockFile($projectRoot);
        $data = $lock->read();
        $hasher = new Hasher();
        $updatedFiles = $data['files'];
        $anyUpdated = false;

        foreach ($data['files'] as $destination => $entry) {
            if ($file !== '' && $destination !== $file) {
                continue;
            }

            if ($provider !== '' && $entry['provider'] !== $provider) {
                continue;
            }

            $destPath = $projectRoot . '/' . $destination;
            $stubPath = $vendorDir . '/' . $entry['provider'] . '/' . $entry['source'];

            if (!is_file($stubPath)) {
                $this->stderr(
                    sprintf('[scaffold] Stub not found: "%s". Skipping.', $stubPath) . PHP_EOL,
                );

                continue;
            }

            if (!$this->force && is_file($destPath)) {
                $currentHash = $hasher->hash($destPath);

                if (!$hasher->equals($currentHash, $entry['hash'])) {
                    $this->stdout(
                        sprintf(
                            '[scaffold] "%s" is user-modified. Use --force to overwrite.',
                            $destination,
                        ) . PHP_EOL,
                    );

                    continue;
                }
            }

            $stubContent = file_get_contents($stubPath);

            if ($stubContent === false) {
                $this->stderr(
                    sprintf('[scaffold] Could not read stub "%s". Skipping.', $stubPath) . PHP_EOL,
                );

                continue;
            }

            $destDir = dirname($destPath);

            if (!is_dir($destDir) && mkdir($destDir, 0777, true) === false && !is_dir($destDir)) {
                $this->stderr(
                    sprintf('[scaffold] Could not create directory "%s". Skipping.', $destDir) . PHP_EOL,
                );

                continue;
            }

            if (file_put_contents($destPath, $stubContent) === false) {
                $this->stderr(
                    sprintf('[scaffold] Could not write "%s". Skipping.', $destPath) . PHP_EOL,
                );

                continue;
            }

            $newHash = $hasher->hash($destPath);

            $updatedFiles[$destination] = [
                'hash' => $newHash,
                'provider' => $entry['provider'],
                'source' => $entry['source'],
                'mode' => $entry['mode'],
            ];

            $anyUpdated = true;

            $this->stdout(sprintf('[scaffold] Reapplied "%s".', $destination) . PHP_EOL);
        }

        if ($anyUpdated) {
            $lock->write(['providers' => $data['providers'], 'files' => $updatedFiles]);
        }

        return ExitCode::OK;
    }

    /**
     * @param string $actionID
     */
    public function options($actionID): array
    {
        return array_values(array_merge(parent::options($actionID), ['force']));
    }
}
