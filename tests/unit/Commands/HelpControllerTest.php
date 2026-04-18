<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Xepozz\InternalMocker\MockerState;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Module;
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\HelpControllerSpy;

/**
 * Unit tests for {@see \yii\scaffold\Commands\HelpController} module-scoped help listing.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class HelpControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testActionIndexContinuesToNextFileWhenOneInTheMiddleFailsToResolve(): void
    {
        $controllerPath = __DIR__ . '/../../../src/Commands';

        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'glob',
            [],
            [
                "{$controllerPath}/DiffController.php",
                "{$controllerPath}/NonexistentController.php",
                "{$controllerPath}/StatusController.php",
            ],
            default: true,
        );

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Mid-iteration skip must not abort the remaining files.',
        );
        self::assertStringContainsString(
            'scaffold/diff',
            $spy->stdoutBuffer,
            'Valid entries before the failing one must still appear in the output.',
        );
        self::assertStringContainsString(
            'scaffold/status',
            $spy->stdoutBuffer,
            "Valid entries AFTER the failing one must still appear in the output proves 'continue' behaviour (a "
            . "'break' mutation would stop at the nonexistent entry and omit 'scaffold/status').",
        );
        self::assertStringNotContainsString(
            'scaffold/nonexistent',
            $spy->stdoutBuffer,
            'The unresolvable file must not appear as a row.',
        );
    }

    public function testActionIndexEmitsSortedOutputEvenWhenGlobReturnsUnsortedFiles(): void
    {
        $controllerPath = __DIR__ . '/../../../src/Commands';

        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'glob',
            [],
            [
                "{$controllerPath}/StatusController.php",
                "{$controllerPath}/DiffController.php",
                "{$controllerPath}/HelpController.php",
            ],
            default: true,
        );

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            "Unsorted 'glob' output must still produce a successful response.",
        );

        $diffPos = strpos($spy->stdoutBuffer, 'scaffold/diff');
        $helpPos = strpos($spy->stdoutBuffer, 'scaffold/help');
        $statusPos = strpos($spy->stdoutBuffer, 'scaffold/status');

        self::assertNotFalse(
            $diffPos,
            "Expected 'scaffold/diff' row must be present in the output.",
        );
        self::assertNotFalse(
            $helpPos,
            "Expected 'scaffold/help' row must be present in the output.",
        );
        self::assertNotFalse(
            $statusPos,
            "Expected 'scaffold/status' row must be present in the output.",
        );
        self::assertLessThan(
            $helpPos,
            $diffPos,
            "ksort must sort rows alphabetically regardless of filesystem order: 'diff' must precede 'help'.",
        );
        self::assertLessThan(
            $statusPos,
            $helpPos,
            "ksort must sort rows alphabetically regardless of filesystem order: 'help' must precede 'status'.",
        );
    }

    public function testActionIndexListsAllScaffoldCommandsSortedWithDescriptions(): void
    {
        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Module-scoped help listing must return a success exit code.',
        );
        self::assertStringContainsString(
            'Command',
            $spy->stdoutBuffer,
            "Help output must include a 'Command' column header.",
        );
        self::assertStringContainsString(
            'Description',
            $spy->stdoutBuffer,
            "Help output must include a 'Description' column header.",
        );

        foreach (['diff', 'eject', 'help', 'providers', 'reapply', 'status'] as $command) {
            self::assertStringContainsString(
                "scaffold/{$command}",
                $spy->stdoutBuffer,
                sprintf("Help output must list 'scaffold/%s' among discovered commands.", $command),
            );
        }

        $diffPos = strpos($spy->stdoutBuffer, 'scaffold/diff');
        $statusPos = strpos($spy->stdoutBuffer, 'scaffold/status');

        self::assertNotFalse(
            $diffPos,
            "Expected 'scaffold/diff' row must be present in the output.",
        );
        self::assertNotFalse(
            $statusPos,
            "Expected 'scaffold/status' row must be present in the output.",
        );
        self::assertLessThan(
            $statusPos,
            $diffPos,
            "Commands must be alphabetically sorted: 'scaffold/diff' must appear before 'scaffold/status'.",
        );

        $lines = explode(PHP_EOL, rtrim($spy->stdoutBuffer, PHP_EOL));

        self::assertCount(
            8,
            $lines,
            "Help table must render exactly '8' lines: header + separator + '6' scaffold commands.",
        );
        self::assertStringEndsWith(
            PHP_EOL,
            $spy->stdoutBuffer,
            'Every rendered line must be terminated with PHP_EOL so piped output stays newline-delimited.',
        );

        $expectedNameColumnWidth = strlen('scaffold/providers');
        $headerLine = $lines[0];

        self::assertSame(
            'Command' . str_repeat(' ', $expectedNameColumnWidth - strlen('Command')) . '  Description',
            $headerLine,
            "Header must pad 'Command' to the widest command id so the 'Description' column aligns across rows.",
        );

        $separatorLine = $lines[1] ?? '';

        $dataRows = array_slice($lines, 2);

        self::assertNotEmpty(
            $dataRows,
            'Data rows must exist after header and separator.',
        );

        $expectedSeparatorLength = 0;

        foreach ($dataRows as $row) {
            $expectedSeparatorLength = max($expectedSeparatorLength, strlen($row));
        }

        self::assertSame(
            str_repeat('-', $expectedSeparatorLength),
            $separatorLine,
            "Separator must span exactly the width of the widest rendered data row (colName + '2' + colDesc).",
        );

        $providersRow = '';

        foreach ($lines as $line) {
            if (str_starts_with($line, 'scaffold/providers')) {
                $providersRow = $line;

                break;
            }
        }

        self::assertStringStartsWith(
            'scaffold/providers  ',
            $providersRow,
            'The widest command id must be followed by exactly two spaces before its description (no padding added).',
        );
    }

    public function testActionIndexPrintsNoCommandsMessageWhenControllerPathHasNoControllers(): void
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        $emptyDir = "{$this->tempDir}/empty-commands";

        mkdir($emptyDir);

        $module->setControllerPath($emptyDir);

        $spy = new HelpControllerSpy('help', $module);

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Empty controller path must return success, not an error.',
        );
        self::assertStringContainsString(
            'No commands discovered',
            $spy->stdoutBuffer,
            'Empty controller path must print the user-facing no-commands message instead of an empty table.',
        );
        self::assertStringEndsWith(
            PHP_EOL,
            $spy->stdoutBuffer,
            "'No commands discovered' message must end with PHP_EOL so the terminal prompt wraps cleanly.",
        );
    }

    public function testActionIndexPrintsNoCommandsMessageWhenGlobReturnsFalse(): void
    {
        MockerState::addCondition(
            'yii\\scaffold\\Commands',
            'glob',
            [],
            false,
            default: true,
        );

        $spy = $this->makeSpy();

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            "Help command must tolerate 'glob' failures and return success.",
        );
        self::assertStringContainsString(
            'No commands discovered',
            $spy->stdoutBuffer,
            "'glob === false' must surface the same user-facing message as an empty directory.",
        );
    }

    public function testActionIndexReturnsErrorWhenScaffoldModuleIsNotRegistered(): void
    {
        $detachedModule = new Module('scaffold');

        Yii::$app->setModule('scaffold', null);

        $spy = new HelpControllerSpy('help', $detachedModule);

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::UNSPECIFIED_ERROR,
            $exitCode,
            'Help command must fail when the scaffold module is not registered on the application.',
        );
        self::assertStringContainsString(
            'not registered',
            $spy->stderrBuffer,
            'Missing-module path must surface a stderr diagnostic mentioning registration state.',
        );
        self::assertStringEndsWith(
            PHP_EOL,
            $spy->stderrBuffer,
            'Stderr diagnostic must end with PHP_EOL so wrapping shell output stays clean.',
        );
        self::assertStringContainsString(
            '[scaffold]',
            $spy->stderrBuffer,
            "Diagnostic must be prefixed with '[scaffold]' so the source of the message is clear to the user.",
        );
    }

    public function testActionIndexSkipsControllerFilesThatDoNotResolveToValidControllers(): void
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        $fakeDir = $this->tempDir . '/fake-commands';

        mkdir($fakeDir);

        file_put_contents($fakeDir . '/BogusController.php', "<?php // class intentionally missing\n");

        $module->setControllerPath($fakeDir);

        $spy = new HelpControllerSpy('help', $module);

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Help command must recover gracefully when a controller file does not resolve to a valid controller.',
        );
        self::assertStringContainsString(
            'No commands discovered',
            $spy->stdoutBuffer,
            'When every discovered file fails to resolve, the no-commands message must be printed.',
        );
        self::assertStringNotContainsString(
            'scaffold/bogus',
            $spy->stdoutBuffer,
            'Unresolvable controllers must not leak into the rendered table.',
        );
    }

    public function testActionIndexSkipsControllersThatAreNotConsoleControllers(): void
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        $module->setControllerPath(__DIR__ . '/../../support/FakeWeb');

        $module->controllerNamespace = 'yii\\scaffold\\tests\\support\\FakeWeb';

        $spy = new HelpControllerSpy('help', $module);

        $exitCode = $spy->actionIndex();

        self::assertSame(
            ExitCode::OK,
            $exitCode,
            'Help command must treat non-console controllers as non-listable and return success.',
        );
        self::assertStringContainsString(
            'No commands discovered',
            $spy->stdoutBuffer,
            'Non-console controllers must be skipped, resulting in the no-commands message when no other controller '
            . 'matches.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpConsoleApplication();
    }

    protected function tearDown(): void
    {
        $this->tearDownConsoleApplication();
    }

    private function makeSpy(): HelpControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new HelpControllerSpy('help', $module);
    }
}
