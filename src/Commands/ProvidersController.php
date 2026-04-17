<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\scaffold\Scaffold\Lock\LockFile;

/**
 * Lists all scaffold providers recorded in `scaffold-lock.json` with their file counts.
 *
 * Usage example:
 * ```bash
 * yii scaffold/providers
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ProvidersController extends Controller
{
    /**
     * Outputs a summary table of all providers tracked in `scaffold-lock.json`.
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

        $this->stdout(sprintf("%-44s %s\n", 'Provider', 'Files'));
        $this->stdout(str_repeat('-', 52) . PHP_EOL);

        foreach ($providers as $name => $count) {
            $this->stdout(sprintf("%-44s %d\n", $name, $count));
        }

        return ExitCode::OK;
    }
}
