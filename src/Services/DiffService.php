<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;
use yii\scaffold\Console\{DiffColorizer, ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Scaffold\PathResolver;
use yii\scaffold\Security\PathValidator;

use function is_file;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function substr;

/**
 * Computes and renders a git-style unified diff between a scaffold provider stub and the current on-disk file.
 */
final class DiffService
{
    /**
     * Builds a git-style unified diff between `$stubContent` and `$currentContent`.
     *
     * Renders `--- a/$file` and `+++ b/$file` headers followed by `@@ -l,c +l,c @@` hunks with three context lines.
     * Lines present only in the stub are prefixed with `-`, and lines present only in the current file with `+`.
     *
     * @param string $stubContent Content from the provider stub file.
     * @param string $currentContent Content of the current on-disk file.
     * @param string $file Destination path used in the `a/`–`b/` header labels.
     *
     * @return string Unified diff output without a trailing newline. Empty string if the contents are identical.
     */
    public function buildDiff(string $stubContent, string $currentContent, string $file): string
    {
        $stubContent = (string) preg_replace('/\r\n|\r/', "\n", $stubContent);
        $currentContent = (string) preg_replace('/\r\n|\r/', "\n", $currentContent);

        // The builder defaults match git: three context lines and collapsed single-line ranges. It returns an empty
        // string for identical contents, so only the header labels need configuring.
        $differ = new Differ(
            new StrictUnifiedDiffOutputBuilder(
                [
                    'fromFile' => 'a/' . $file,
                    'toFile' => 'b/' . $file,
                ],
            ),
        );

        $diff = $differ->diff($stubContent, $currentContent);

        // The builder always terminates its output with exactly one newline; strip it because 'writeStdout()' appends
        // the final newline itself.
        return str_ends_with($diff, "\n") ? substr($diff, 0, -1) : $diff;
    }

    /**
     * Renders the diff for `$file` tracked in `scaffold-lock.json`.
     *
     * @param string $projectRoot Absolute path to the project root.
     * @param string $vendorDir Absolute path to the Composer vendor directory.
     * @param string $file Destination path as recorded in `scaffold-lock.json`.
     * @param OutputWriter $out Output sink.
     * @param bool $decorated Whether to wrap the diff lines in ANSI color escape codes.
     *
     * @return int `0` on success, non-zero on unsafe lock entry, missing stub, or I/O failure.
     */
    public function run(
        string $projectRoot,
        string $vendorDir,
        string $file,
        OutputWriter $out,
        bool $decorated = false,
    ): int {
        $data = (new LockFile($projectRoot))->read();

        $entry = $data['files'][$file] ?? null;

        if ($entry === null) {
            $out->writeStderr(sprintf('[scaffold] "%s" is not tracked in scaffold-lock.json.', $file));

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
            $out->writeStderr($resolved['warning']);
        }

        $validator = new PathValidator();

        try {
            $validator->validateDestination($file, $projectRoot);
            $validator->validateSource($entry['source'], $providerRoot);
        } catch (RuntimeException $e) {
            $out->writeStderr(sprintf('[scaffold] Unsafe lock entry for "%s": %s', $file, $e->getMessage()));

            return ExitCode::Error->value;
        }

        $currentPath = PathResolver::destination($projectRoot, $file);

        if (is_file($currentPath)) {
            $rawCurrent = file_get_contents($currentPath);

            if ($rawCurrent === false) {
                $out->writeStderr(sprintf('[scaffold] Could not read current file "%s".', $currentPath));

                return ExitCode::Error->value;
            }

            $currentContent = $rawCurrent;
        } else {
            $currentContent = '';
        }

        $stubPath = PathResolver::source($providerRoot, $entry['source']);

        if (!is_file($stubPath)) {
            $out->writeStderr(sprintf('[scaffold] Stub not found: "%s".', $stubPath));

            return ExitCode::Error->value;
        }

        $stubContent = (string) file_get_contents($stubPath);

        $diff = $this->buildDiff($stubContent, $currentContent, $file);

        if ($diff === '') {
            $out->writeStdout('[scaffold] No differences found.');
        } else {
            $out->writeStdout($decorated ? (new DiffColorizer())->colorize($diff) : $diff);
        }

        return ExitCode::Ok->value;
    }
}
