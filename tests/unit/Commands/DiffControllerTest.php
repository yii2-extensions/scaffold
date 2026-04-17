<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\scaffold\Commands\DiffController;
use yii\scaffold\Module;
use yii\scaffold\tests\support\ConsoleApplicationTrait;

/**
 * Unit tests for {@see DiffController} diff computation via {@see DiffController::buildDiff()}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class DiffControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testBuildDiffPreservesUnchangedLinesWithIndent(): void
    {
        $diff = $this->makeController()->buildDiff("same\nchanged", "same\ndifferent");

        self::assertStringContainsString(
            '  same',
            $diff,
            'Unchanged lines should be prefixed with two spaces to indicate no change.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalContent(): void
    {
        self::assertSame(
            '',
            $this->makeController()->buildDiff('line1', 'line1'),
            'Identical content should result in an empty diff.',
        );
    }

    public function testBuildDiffReturnsEmptyStringForIdenticalMultilineContent(): void
    {
        $content = "line1\nline2\nline3";

        self::assertSame(
            '',
            $this->makeController()->buildDiff($content, $content),
            'Identical multiline content should result in an empty diff.',
        );
    }

    public function testBuildDiffShowsAddedLines(): void
    {
        $diff = $this->makeController()->buildDiff('line1', "line1\nnewline");

        self::assertStringContainsString(
            '+ newline',
            $diff,
            'Added lines should be prefixed with a plus sign.',
        );
        self::assertStringNotContainsString(
            '- newline',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
    }

    public function testBuildDiffShowsBothSidesForModifiedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\noriginal", "line1\nmodified");

        self::assertStringContainsString(
            '- original',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
        self::assertStringContainsString(
            '+ modified',
            $diff,
            'Added lines should be prefixed with a plus sign.',
        );
    }

    public function testBuildDiffShowsRemovedLines(): void
    {
        $diff = $this->makeController()->buildDiff("line1\nline2", 'line1');

        self::assertStringContainsString(
            '- line2',
            $diff,
            'Removed lines should be prefixed with a minus sign.',
        );
        self::assertStringNotContainsString(
            '+ line2',
            $diff,
            'Added lines should be prefixed with a plus sign.',
        );
    }

    public function testBuildDiffNormalizesCrlfAndLfAsIdentical(): void
    {
        self::assertSame(
            '',
            $this->makeController()->buildDiff("line1\r\nline2", "line1\nline2"),
            'CRLF and LF versions of the same content should be treated as identical.',
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

    private function makeController(): DiffController
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new DiffController('diff', $module);
    }
}
