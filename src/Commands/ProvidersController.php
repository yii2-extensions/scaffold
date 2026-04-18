<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\{Controller, ExitCode};
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Lists all scaffold providers recorded in `scaffold-lock.json` with their file counts.
 *
 * Usage example:
 * ```bash
 * yii scaffold/providers
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
class ProvidersController extends Controller
{
    /**
     * Outputs a summary table of all providers tracked in `scaffold-lock.json`.
     *
     * @return int Exit code indicating the result of the command execution.
     */
    public function actionIndex(): int
    {
        $lock = new LockFile(Yii::$app->basePath);

        $data = $lock->read();

        /** @var array<string, int> $providers */
        $providers = [];

        foreach ($data['files'] as $entry) {
            $name = $entry['provider'];
            $providers[$name] = ($providers[$name] ?? 0) + 1;
        }

        if ($providers === []) {
            $this->stdout('[scaffold] No providers tracked in scaffold-lock.json.' . PHP_EOL);

            return ExitCode::OK;
        }

        $this->stdout(sprintf('%-44s %s', 'Provider', 'Files') . PHP_EOL);
        $this->stdout(str_repeat('-', 52) . PHP_EOL);

        foreach ($providers as $name => $count) {
            $this->stdout(sprintf('%-44s %d', $name, $count) . PHP_EOL);
        }

        return ExitCode::OK;
    }
}
