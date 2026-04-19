<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use RuntimeException;
use Throwable;
use yii\scaffold\Console\{ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\{Hasher, LockFile};
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\Security\PathValidator;

use function is_file;
use function sprintf;

/**
 * Re-applies scaffold stubs to the project, optionally overwriting user-modified files.
 *
 * Framework-free: invoked by both the console controller wrapper and the Symfony Console command.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ReapplyService
{
    /**
     * Re-applies scaffold stubs under the given constraints.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param string $file Optional destination path filter (empty string = all).
     * @param string $provider Optional provider package name filter (empty string = all).
     * @param bool $force When `true`, overwrites user-modified files without prompting.
     * @param OutputWriter $out Output sink.
     *
     * @return int `0` on success (including no-op), non-zero when a file/provider filter matched nothing.
     */
    public function run(
        string $projectRoot,
        string $vendorDir,
        string $file,
        string $provider,
        bool $force,
        OutputWriter $out,
    ): int {
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

            if ($provider !== '' && $entry['provider'] !== $provider) {
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
                $out->writeStderr($resolved['warning'] . PHP_EOL);
            }

            try {
                $validator->validateDestination($destination, $projectRoot);
                $validator->validateSource($entry['source'], $providerRoot);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    sprintf('[scaffold] Unsafe lock entry for "%s": %s Skipping.', $destination, $e->getMessage())
                    . PHP_EOL,
                );

                continue;
            }

            $mode = $entry['mode'];

            if ($mode === 'append' || $mode === 'prepend') {
                $out->writeStdout(
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

            if ($mode === 'preserve' && !$force && is_file($destPath)) {
                $out->writeStdout(
                    sprintf('[scaffold] "%s" uses mode "preserve". Use --force to overwrite.', $destination) . PHP_EOL,
                );

                continue;
            }

            if (!is_file($stubPath)) {
                $out->writeStderr(
                    sprintf('[scaffold] Stub not found: "%s". Skipping.', $stubPath) . PHP_EOL,
                );

                continue;
            }

            if (!$force && is_file($destPath)) {
                try {
                    $currentHash = $hasher->hash($destPath);
                } catch (Throwable $e) {
                    $out->writeStderr(
                        sprintf('[scaffold] Could not hash "%s": %s Skipping.', $destination, $e->getMessage())
                        . PHP_EOL,
                    );

                    continue;
                }

                if (!$hasher->equals($currentHash, $entry['hash'])) {
                    $out->writeStdout(
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
                $out->writeStderr(
                    sprintf('[scaffold] Could not read stub "%s". Skipping.', $stubPath) . PHP_EOL,
                );

                continue;
            }

            try {
                PathResolver::ensureDirectory($destPath);
            } catch (RuntimeException $e) {
                $out->writeStderr(
                    sprintf('[scaffold] %s Skipping.', $e->getMessage()) . PHP_EOL,
                );

                continue;
            }

            if (file_put_contents($destPath, $stubContent) === false) {
                $out->writeStderr(
                    sprintf('[scaffold] Could not write "%s". Skipping.', $destPath) . PHP_EOL,
                );

                continue;
            }

            PathResolver::syncPermissions($stubPath, $destPath);

            try {
                $newHash = $hasher->hash($destPath);
            } catch (Throwable $e) {
                $out->writeStderr(
                    sprintf(
                        '[scaffold] Could not hash written file "%s": %s Skipping lock update.',
                        $destination,
                        $e->getMessage(),
                    ) . PHP_EOL,
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

            $out->writeStdout(sprintf('[scaffold] Reapplied "%s".', $destination) . PHP_EOL);
        }

        if (($file !== '' || $provider !== '') && !$anyMatched) {
            $out->writeStderr('[scaffold] No tracked files matched the given filter.' . PHP_EOL);

            return ExitCode::Error->value;
        }

        if ($anyUpdated) {
            $lock->write(['providers' => $data['providers'], 'files' => $updatedFiles]);
        }

        return ExitCode::Ok->value;
    }
}
