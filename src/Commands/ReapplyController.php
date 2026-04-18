<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use RuntimeException;
use Throwable;
use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\Security\PathValidator;

use function sprintf;

/**
 * Re-applies scaffold stubs to the project, optionally overwriting user-modified files.
 *
 * Usage example:
 * ```bash
 * yii scaffold/reapply
 * yii scaffold/reapply config/params.php
 * yii scaffold/reapply config/params.php --force
 * yii scaffold/reapply --provider=yii2-extensions/app-base
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
class ReapplyController extends Controller
{
    /**
     * When `true`, overwrites user-modified files without prompting.
     */
    public bool $force = false;

    /**
     * Optional provider package name filter (for example, `yii2-extensions/app-base`). When empty, all providers are
     * processed.
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
        $validator = new PathValidator();

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

            $resolved = PathResolver::resolveProviderRoot(
                $vendorDir,
                $entry['provider'],
                $data['providers'][$entry['provider']] ?? null,
                $projectRoot,
            );

            $providerRoot = $resolved['root'];

            if ($resolved['warning'] !== null) {
                $this->stderr($resolved['warning'] . PHP_EOL);
            }

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

            $destPath = PathResolver::destination($projectRoot, $destination);
            $stubPath = PathResolver::source($providerRoot, $entry['source']);

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
                try {
                    $currentHash = $hasher->hash($destPath);
                } catch (Throwable $e) {
                    $this->stderr(
                        sprintf('[scaffold] Could not hash "%s": %s Skipping.', $destination, $e->getMessage())
                        . PHP_EOL,
                    );

                    continue;
                }

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

            try {
                PathResolver::ensureDirectory($destPath);
            } catch (RuntimeException $e) {
                $this->stderr(
                    sprintf('[scaffold] %s Skipping.', $e->getMessage()) . PHP_EOL,
                );

                continue;
            }

            if (file_put_contents($destPath, $stubContent) === false) {
                $this->stderr(
                    sprintf('[scaffold] Could not write "%s". Skipping.', $destPath) . PHP_EOL,
                );

                continue;
            }

            try {
                $newHash = $hasher->hash($destPath);
            } catch (Throwable $e) {
                $this->stderr(
                    sprintf('[scaffold] Could not hash written file "%s": %s Skipping lock update.', $destination, $e->getMessage())
                    . PHP_EOL,
                );

                continue;
            }

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
