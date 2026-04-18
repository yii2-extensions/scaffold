<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;
use yii\scaffold\Console\{ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\Security\PathValidator;

use function implode;
use function is_file;
use function is_string;
use function preg_replace;
use function rtrim;
use function sprintf;

/**
 * Computes and renders a line-by-line diff between a scaffold provider stub and the current on-disk file.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class DiffService
{
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

    /**
     * Renders the diff for `$file` tracked in `scaffold-lock.json`.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param string $file Destination path as recorded in `scaffold-lock.json`.
     * @param OutputWriter $out Output sink.
     *
     * @return int `0` on success, non-zero on unsafe lock entry, missing stub, or I/O failure.
     */
    public function run(string $projectRoot, string $vendorDir, string $file, OutputWriter $out): int
    {
        $data = (new LockFile($projectRoot))->read();

        $entry = $data['files'][$file] ?? null;

        if ($entry === null) {
            $out->writeStderr(
                sprintf('[scaffold] "%s" is not tracked in scaffold-lock.json.', $file) . PHP_EOL,
            );

            return ExitCode::Error->value;
        }

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

        $validator = new PathValidator();

        try {
            $validator->validateDestination($file, $projectRoot);
            $validator->validateSource($entry['source'], $providerRoot);
        } catch (RuntimeException $e) {
            $out->writeStderr(
                sprintf('[scaffold] Unsafe lock entry for "%s": %s', $file, $e->getMessage()) . PHP_EOL,
            );

            return ExitCode::Error->value;
        }

        $currentPath = PathResolver::destination($projectRoot, $file);

        if (is_file($currentPath)) {
            $rawCurrent = file_get_contents($currentPath);

            if ($rawCurrent === false) {
                $out->writeStderr(
                    sprintf('[scaffold] Could not read current file "%s".', $currentPath) . PHP_EOL,
                );

                return ExitCode::Error->value;
            }

            $currentContent = $rawCurrent;
        } else {
            $currentContent = '';
        }

        $stubPath = PathResolver::source($providerRoot, $entry['source']);

        if (!is_file($stubPath)) {
            $out->writeStderr(
                sprintf('[scaffold] Stub not found: "%s".', $stubPath) . PHP_EOL,
            );

            return ExitCode::Error->value;
        }

        $stubContent = (string) file_get_contents($stubPath);

        $diff = $this->buildDiff($stubContent, $currentContent);

        if ($diff === '') {
            $out->writeStdout('[scaffold] No differences found.' . PHP_EOL);
        } else {
            $out->writeStdout($diff);
        }

        return ExitCode::Ok->value;
    }
}
