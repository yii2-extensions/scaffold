<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Shows a line-by-line diff between the provider stub and the current on-disk file.
 *
 * Usage example:
 * ```bash
 * yii scaffold/diff config/params.php
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class DiffController extends Controller
{
    /**
     * Outputs the diff between the provider stub and the current file for `$file`.
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

        $currentPath = $projectRoot . '/' . $file;
        $currentContent = is_file($currentPath) ? (string) file_get_contents($currentPath) : '';

        $vendorDir = $projectRoot . '/vendor';
        $stubPath = $vendorDir . '/' . $entry['provider'] . '/' . $entry['source'];
        $stubContent = is_file($stubPath) ? (string) file_get_contents($stubPath) : '';

        $diff = $this->buildDiff($stubContent, $currentContent);

        if ($diff === '') {
            $this->stdout('[scaffold] No differences found.' . PHP_EOL);
        } else {
            $this->stdout($diff);
        }

        return ExitCode::OK;
    }

    /**
     * Builds a simple line-by-line diff between `$stubContent` and `$currentContent`.
     *
     * Lines present only in the stub are prefixed with `- `, lines present only in the current
     * file are prefixed with `+ `, and shared unchanged lines are prefixed with two spaces.
     * Returns an empty string when the two inputs are identical.
     *
     * @param string $stubContent Content from the provider stub file.
     * @param string $currentContent Content of the current on-disk file.
     */
    public function buildDiff(string $stubContent, string $currentContent): string
    {
        if ($stubContent === $currentContent) {
            return '';
        }

        $stubLines = explode("\n", $stubContent);
        $currentLines = explode("\n", $currentContent);
        $count = max(count($stubLines), count($currentLines));
        $output = [];

        for ($i = 0; $i < $count; $i++) {
            $stub = array_key_exists($i, $stubLines) ? $stubLines[$i] : null;
            $current = array_key_exists($i, $currentLines) ? $currentLines[$i] : null;

            if ($stub === $current) {
                $output[] = '  ' . ($stub ?? '');
            } else {
                if ($stub !== null) {
                    $output[] = '- ' . $stub;
                }

                if ($current !== null) {
                    $output[] = '+ ' . $current;
                }
            }
        }

        return implode(PHP_EOL, $output) . PHP_EOL;
    }
}
