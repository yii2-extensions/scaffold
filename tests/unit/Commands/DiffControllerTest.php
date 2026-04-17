<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\TestCase;
use Yii;
use yii\scaffold\Commands\DiffController;
use yii\scaffold\Module;
use yii\scaffold\tests\support\ConsoleApplicationTrait;

/**
 * Unit tests for {@see DiffController} diff computation via {@see DiffController::buildDiff()}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class DiffControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testBuildDiffPreservesUnchangedLinesWithIndent(): void
    {
        $diff = $this->makeController()->buildDiff("same\nchanged", "same\ndifferent");

        self::assertStringContainsString('  same', $diff);
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalContent(): void
    {
        self::assertSame('', $this->makeController()->buildDiff('line1', 'line1'));
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalMultilineContent(): void
    {
        $content = "line1\nline2\nline3";

        self::assertSame('', $this->makeController()->buildDiff($content, $content));
    }

    public function testBuildDiffShowsAddedLines(): void
    {
        $diff = $this->makeController()->buildDiff('line1', "line1\nnewline");

        self::assertStringContainsString('+ newline', $diff);
        self::assertStringNotContainsString('- newline', $diff);
    }

    public function testBuildDiffShowsBothSidesForModifiedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\noriginal", "line1\nmodified");

        self::assertStringContainsString('- original', $diff);
        self::assertStringContainsString('+ modified', $diff);
    }

    public function testBuildDiffShowsRemovedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\nline2", 'line1');

        self::assertStringContainsString('- line2', $diff);
        self::assertStringNotContainsString('+ line2', $diff);
    }

    protected function setUp(): void
    {
        $this->setUpConsoleApplication();
    }

    protected function tearDown(): void
    {
        $this->tearDownConsoleApplication();
    }

    private function makeController(): DiffController
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new DiffController('diff', $module);
    }
}
