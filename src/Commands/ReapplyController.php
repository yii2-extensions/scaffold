<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use RuntimeException;
use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Security\PathValidator;

use function is_array;
use function is_string;
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
     * Optional provider package name filter (for example, `yii2-extensions/app-base-scaffold`). When empty, all
     * providers are processed.
     */
    public string $provider = '';

    /**
     * Re-applies scaffold files from vendor stubs, updating lock hashes on success.
     *
     * @param string $file Optional destination path to reapply (for example, `config/params.php`). When empty, all
     * tracked files are processed.
     *
     * @return int Exit code indicating success or failure of the operation.
     */
    public function actionIndex(string $file = ''): int
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

            if ($this->provider !== '' && $entry['provider'] !== $this->provider) {
                continue;
            }

            $anyMatched = true;

            $resolvedVendor = realpath($vendorDir);
            $safeVendorDir = $resolvedVendor !== false ? $resolvedVendor : rtrim($vendorDir, '/\\');

            $providerLock = $data['providers'][$entry['provider']] ?? null;
            $providerRoot = $safeVendorDir . DIRECTORY_SEPARATOR . $entry['provider'];

            if (is_array($providerLock) && is_string($providerLock['path'] ?? null)) {
                $rawPath = rtrim($providerLock['path'], '/\\');
                $resolved = realpath($rawPath);
                $providerRoot = $resolved !== false ? $resolved : $rawPath;
            }

            $validator = new PathValidator();

            try {
                $validator->validateDestination($destination, $projectRoot);
                $validator->validateSource($entry['source'], $providerRoot);
            } catch (RuntimeException $e) {
                $this->stderr(
                    sprintf('[scaffold] Unsafe lock entry for "%s": %s Skipping.', $destination, $e->getMessage())
                    . PHP_EOL,
                );

                continue;
            }

            $mode = $entry['mode'];

            if ($mode === 'append' || $mode === 'prepend') {
                $this->stdout(
                    sprintf(
                        '[scaffold] "%s" uses mode "%s" and cannot be safely reapplied; run composer install instead.',
                        $destination,
                        $mode,
                    ) . PHP_EOL,
                );

                continue;
            }

            $destPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($destination, '/\\');

            $stubPath = $providerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);

            if ($mode === 'preserve' && !$this->force && is_file($destPath)) {
                $this->stdout(
                    sprintf('[scaffold] "%s" uses mode "preserve". Use --force to overwrite.', $destination) . PHP_EOL,
                );

                continue;
            }

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

        if (($file !== '' || $this->provider !== '') && !$anyMatched) {
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
            ...parent::options($actionID), 'force', 'provider',
        ];
    }
}
