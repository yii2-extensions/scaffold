<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;
use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Security\PathValidator;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Shows a line-by-line diff between the provider stub and the current on-disk file.
 *
 * Usage example:
 * ```bash
 * yii scaffold/diff config/params.php
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class DiffController extends Controller
{
    /**
     * Outputs the diff between the provider stub and the current file for `$file`.
     *
     * @param string $file Destination path as recorded in `scaffold-lock.json` (for example, `config/params.php`).
     *
     * @return int Exit code indicating success or failure of the operation.
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

        $currentPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
        $currentContent = is_file($currentPath) ? (string) file_get_contents($currentPath) : '';

        $vendorDir = Yii::$app->vendorPath;

        $resolvedVendor = realpath($vendorDir);
        $safeVendorDir = $resolvedVendor !== false ? $resolvedVendor : rtrim($vendorDir, '/\\');

        $providerLock = $data['providers'][$entry['provider']] ?? null;
        $providerRoot = $safeVendorDir . DIRECTORY_SEPARATOR . $entry['provider'];

        if (is_array($providerLock) && is_string($providerLock['path'] ?? null)) {
            $rawPath = rtrim($providerLock['path'], '/\\');
            $resolved = realpath($rawPath);

            $candidate = $resolved !== false ? $resolved : $rawPath;

            if (str_starts_with($candidate . DIRECTORY_SEPARATOR, $safeVendorDir . DIRECTORY_SEPARATOR)) {
                $providerRoot = $candidate;
            } else {
                $this->stderr(
                    sprintf(
                        '[scaffold] Provider root for "%s" resolves outside vendor dir; using default path.',
                        $entry['provider'],
                    ) . PHP_EOL,
                );
            }
        }

        $validator = new PathValidator();

        try {
            $validator->validateDestination($file, $projectRoot);
            $validator->validateSource($entry['source'], $providerRoot);
        } catch (RuntimeException $e) {
            $this->stderr(
                sprintf('[scaffold] Unsafe lock entry for "%s": %s', $file, $e->getMessage()) . PHP_EOL,
            );

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stubPath = $providerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);

        if (!is_file($stubPath)) {
            $this->stderr(
                sprintf('[scaffold] Stub not found: "%s".', $stubPath) . PHP_EOL,
            );

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stubContent = (string) file_get_contents($stubPath);

        $diff = $this->buildDiff($stubContent, $currentContent);

        if ($diff === '') {
            $this->stdout('[scaffold] No differences found.' . PHP_EOL);
        } else {
            $this->stdout($diff);
        }

        return ExitCode::OK;
    }

    /**
     * Builds a line-by-line diff between `$stubContent` and `$currentContent` using LCS.
     *
     * Lines present only in the stub are prefixed with `- `, lines present only in the current file are prefixed with
     * `+ `, and shared unchanged lines are prefixed with two spaces.
     *
     * @param string $stubContent Content from the provider stub file.
     * @param string $currentContent Content of the current on-disk file.
     *
     * @return string Formatted diff output. Empty string if the contents are identical.
     */
    public function buildDiff(string $stubContent, string $currentContent): string
    {
        $stubContent = (string) preg_replace('/\r\n|\r/', "\n", $stubContent);
        $currentContent = (string) preg_replace('/\r\n|\r/', "\n", $currentContent);

        if ($stubContent === $currentContent) {
            return '';
        }

        $differ = new Differ(new DiffOnlyOutputBuilder(''));

        $entries = $differ->diffToArray($stubContent, $currentContent);

        $output = [];

        foreach ($entries as [$line, $type]) {
            $line = is_string($line) ? $line : '';

            if ($type === Differ::REMOVED) {
                $output[] = '- ' . rtrim($line, "\n");
            } elseif ($type === Differ::ADDED) {
                $output[] = '+ ' . rtrim($line, "\n");
            } else {
                $output[] = '  ' . rtrim($line, "\n");
            }
        }

        return implode(PHP_EOL, $output) . PHP_EOL;
    }
}
