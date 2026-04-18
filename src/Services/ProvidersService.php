<?php

declare(strict_types=1);

namespace yii\scaffold\Services;

use yii\scaffold\Console\{ExitCode, OutputWriter};
use yii\scaffold\Scaffold\Lock\LockFile;

use function sprintf;
use function str_repeat;

/**
 * Lists scaffold providers recorded in `scaffold-lock.json` with their file counts.
 *
 * Framework-free: invoked by both the console controller wrapper and the Symfony Console command, receiving
 * an {@see OutputWriter} for stdout / stderr rendering.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class ProvidersService
{
    /**
     * Returns the `provider-name => file-count` map derived from `scaffold-lock.json` under `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root.
     *
     * @return array<string, int> Map of provider package names to the number of files tracked for them.
     */
    public function getProviders(string $projectRoot): array
    {
        $data = (new LockFile($projectRoot))->read();

        $providers = [];

        foreach ($data['files'] as $entry) {
            $name = $entry['provider'];
            $providers[$name] = ($providers[$name] ?? 0) + 1;
        }

        return $providers;
    }

    /**
     * Renders the providers table for the project rooted at `$projectRoot`.
     *
     * @param string $projectRoot Absolute path to the project root directory.
     * @param OutputWriter $out Output sink used for stdout writes.
     *
     * @return int `0` on success.
     */
    public function run(string $projectRoot, OutputWriter $out): int
    {
        $providers = $this->getProviders($projectRoot);

        if ($providers === []) {
            $out->writeStdout('[scaffold] No providers tracked in scaffold-lock.json.' . PHP_EOL);

            return ExitCode::Ok->value;
        }

        $out->writeStdout(sprintf('%-44s %s', 'Provider', 'Files') . PHP_EOL);
        $out->writeStdout(str_repeat('-', 52) . PHP_EOL);

        foreach ($providers as $name => $count) {
            $out->writeStdout(sprintf('%-44s %d', $name, $count) . PHP_EOL);
        }

        return ExitCode::Ok->value;
    }
}
