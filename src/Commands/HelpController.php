<?php

declare(strict_types=1);

namespace yii\scaffold\Commands;

use Yii;
use yii\console\{Controller, ExitCode};
use yii\helpers\Inflector;

use function array_map;
use function is_array;
use function max;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * Lists the scaffold module's own commands with descriptions.
 *
 * Mirrors the layout of `yii help` but scoped to the `scaffold` module, so consumers can discover the plugin's
 * subcommands without scrolling through the full application help.
 *
 * Usage example:
 * ```bash
 * yii scaffold/help
 * ```
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
class HelpController extends Controller
{
    /**
     * Outputs a table of commands exposed by the scaffold module with their summaries.
     *
     * @return int Exit code indicating success or failure of the command.
     */
    public function actionIndex(): int
    {
        $module = Yii::$app->getModule('scaffold');

        if ($module === null) {
            $this->stderr('[scaffold] Module "scaffold" is not registered in this application.' . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $files = glob($module->getControllerPath() . '/*Controller.php');

        $rows = [];

        if (is_array($files)) {
            foreach ($files as $file) {
                $id = Inflector::camel2id(basename($file, 'Controller.php'));

                $result = Yii::$app->createController('scaffold/' . $id);

                if (!is_array($result) || !$result[0] instanceof Controller) {
                    continue;
                }

                $rows['scaffold/' . $id] = $result[0]->getHelpSummary();
            }
        }

        if ($rows === []) {
            $this->stdout('[scaffold] No commands discovered under the scaffold module.' . PHP_EOL);

            return ExitCode::OK;
        }

        ksort($rows);

        $colName = max(
            strlen('Command'),
            max(array_map(static fn(string $v): int => strlen($v), array_keys($rows))),
        );
        $colDesc = max(
            strlen('Description'),
            max(array_map(static fn(string $v): int => strlen($v), $rows)),
        );

        $this->stdout(sprintf("%-{$colName}s  %s", 'Command', 'Description') . PHP_EOL);
        $this->stdout(str_repeat('-', $colName + 2 + $colDesc) . PHP_EOL);

        foreach ($rows as $name => $summary) {
            $this->stdout(sprintf("%-{$colName}s  %s", $name, $summary) . PHP_EOL);
        }

        return ExitCode::OK;
    }
}
