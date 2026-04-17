<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};

use function sprintf;

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
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
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
     * @param string $file Optional destination path to reapply (for example, `config/params.php`).
     * When empty, all tracked files are processed.
     * @param string $provider Optional provider package name filter (for example, `yii2-extensions/app-base-scaffold`).
     * When empty, all providers are processed.
     *
     * @return int Exit code indicating success or failure of the operation.
     */
    public function actionIndex(string $file = '', string $provider = ''): int
    {
        $projectRoot = Yii::$app->basePath;
        $vendorDir = Yii::$app->vendorPath;

        $lock = new LockFile($projectRoot);

        $data = $lock->read();

        $hasher = new Hasher();

        $updatedFiles = $data['files'];

        $anyUpdated = false;
        $anyMatched = false;

        foreach ($data['files'] as $destination => $entry) {
            if ($file !== '' && $destination !== $file) {
                continue;
            }

            if ($provider !== '' && $entry['provider'] !== $provider) {
                continue;
            }

            $anyMatched = true;

            $destPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($destination, '/\\');

            $stubPath = "{$vendorDir}/" . $entry['provider'] . '/' . $entry['source'];

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

        if (($file !== '' || $provider !== '') && !$anyMatched) {
            $this->stderr('[scaffold] No tracked files matched the given filter.' . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($anyUpdated) {
            $lock->write(['providers' => $data['providers'], 'files' => $updatedFiles]);
        }

        return ExitCode::OK;
    }

    /**
     * Returns the list of available options for the specified action.
     *
     * @param string $actionID ID of the action being executed.
     *
     * @return array<string> List of available options for the specified action. This method is used by the console
     * application to determine which options are valid for a given action.
     */
    public function options($actionID): array
    {
        return [
            ...parent::options($actionID), 'force',
        ];
    }
}
