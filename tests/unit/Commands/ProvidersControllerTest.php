<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\ExitCode;
use yii\scaffold\Module;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\ConsoleApplicationTrait;
use yii\scaffold\tests\support\Spies\ProvidersControllerSpy;

/**
 * Unit tests for {@see \yii\scaffold\Commands\ProvidersController} provider listing behavior.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('commands')]
final class ProvidersControllerTest extends TestCase
{
    use ConsoleApplicationTrait;

    public function testAggregatesFileCountsPerProvider(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/a' => [
                        'version' => '1.0.0',
                        'path' => 'vendor/pkg/a',
                    ],
                    'pkg/b' => [
                        'version' => '2.0.0',
                        'path' => 'vendor/pkg/b',
                    ],
                ],
                'files' => [
                    'a1.txt' => [
                        'hash' => 'sha256:a1',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a1.txt',
                        'mode' => 'replace',
                    ],
                    'a2.txt' => [
                        'hash' => 'sha256:a2',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a2.txt',
                        'mode' => 'preserve',
                    ],
                    'b1.txt' => [
                        'hash' => 'sha256:b1',
                        'provider' => 'pkg/b',
                        'source' => 'stubs/b1.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $controller = $this->makeController();

        $controller->actionIndex();

        self::assertMatchesRegularExpression(
            '/pkg\/a\s+2/',
            $controller->stdoutBuffer,
            "Provider with two tracked files must report a count of '2'.",
        );
        self::assertMatchesRegularExpression(
            '/pkg\/b\s+1/',
            $controller->stdoutBuffer,
            "Provider with a single tracked file must report a count of '1'.",
        );
    }

    public function testPrintsEmptyMessageWhenNoProvidersTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $controller = $this->makeController();

        $controller->actionIndex();

        self::assertStringContainsString(
            'No providers tracked in scaffold-lock.json',
            $controller->stdoutBuffer,
            'Empty-lock case must emit the user-facing no-providers message instead of an empty table.',
        );
    }

    public function testReturnsOkExitCode(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        self::assertSame(
            ExitCode::OK,
            $this->makeController()->actionIndex(),
            'Providers command must always return ExitCode::OK regardless of whether any providers are tracked.',
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

    private function makeController(): ProvidersControllerSpy
    {
        $module = Yii::$app->getModule('scaffold');

        assert($module instanceof Module);

        return new ProvidersControllerSpy('providers', $module);
    }
}
