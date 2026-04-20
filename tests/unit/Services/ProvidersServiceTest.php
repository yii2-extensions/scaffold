<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\Services\ProvidersService;
use yii\scaffold\tests\support\{BufferedOutputWriter, TempDirectoryTrait};

/**
 * Unit tests for {@see ProvidersService} covering file-count aggregation and empty-state output.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('services')]
final class ProvidersServiceTest extends TestCase
{
    use TempDirectoryTrait;

    public function testGetProvidersAggregatesFileCountsPerProvider(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                    'b.txt' => [
                        'hash' => 'sha256:b',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/b.txt',
                        'mode' => 'replace',
                    ],
                    'c.txt' => [
                        'hash' => 'sha256:c',
                        'provider' => 'pkg/b',
                        'source' => 'stubs/c.txt',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        self::assertSame(
            ['pkg/a' => 2, 'pkg/b' => 1],
            (new ProvidersService())->getProviders($this->tempDir),
            "File counts must aggregate per 'provider' key.",
        );
    }

    public function testGetProvidersIncludesProvidersWithZeroFilesFromLockMetadata(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [
                    'pkg/with-files' => [
                        'version' => '0.1.0',
                        'path' => 'vendor/pkg/with-files',
                    ],
                    'pkg/zero-files' => [
                        'version' => '0.1.0',
                        'path' => 'vendor/pkg/zero-files',
                    ],
                ],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/with-files',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        self::assertSame(
            ['pkg/with-files' => 1, 'pkg/zero-files' => 0],
            (new ProvidersService())->getProviders($this->tempDir),
            "Providers recorded in the lock's top-level metadata must appear with count '0' when they have no files.",
        );
    }

    public function testRunEmptyProvidersMessageEndsWithSinglePhpEolSuffix(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        (new ProvidersService())->run($this->tempDir, $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No providers tracked' message must end with PHP_EOL so shells render it on its own line.",
        );
        self::assertStringEndsNotWith(
            PHP_EOL . PHP_EOL,
            $out->stdoutBuffer,
            "The 'No providers tracked' message must end with exactly one PHP_EOL.",
        );
        self::assertStringStartsNotWith(
            PHP_EOL,
            $out->stdoutBuffer,
            "The 'No providers tracked' message must not be prefixed with PHP_EOL.",
        );
    }

    public function testRunPrintsEmptyMessageWhenNoProvidersTracked(): void
    {
        (new LockFile($this->tempDir))->write(['providers' => [], 'files' => []]);

        $out = new BufferedOutputWriter();
        $exitCode = (new ProvidersService())->run($this->tempDir, $out);

        self::assertSame(
            0,
            $exitCode,
            "Exit code must be '0' when no providers are tracked, indicating successful execution with empty state.",
        );
        self::assertStringContainsString(
            'No providers tracked in scaffold-lock.json',
            $out->stdoutBuffer,
            'When no providers are tracked, the output must indicate the empty state.',
        );
        self::assertStringNotContainsString(
            'Files',
            $out->stdoutBuffer,
            "When the providers map is empty, the service must 'return' before rendering the 'Provider Files' header.",
        );
        self::assertStringNotContainsString(
            str_repeat('-', 52),
            $out->stdoutBuffer,
            'When the providers map is empty, the service must return before rendering the 52-dash separator.',
        );
    }

    public function testRunRendersHeaderAndRowsWhenProvidersExist(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        $exitCode = (new ProvidersService())->run($this->tempDir, $out);

        self::assertSame(
            0,
            $exitCode,
            "Exit code must be '0' when providers exist, indicating successful execution.",
        );
        self::assertStringContainsString(
            'Provider',
            $out->stdoutBuffer,
            "Output must include the 'Provider' header.",
        );
        self::assertStringContainsString(
            'Files',
            $out->stdoutBuffer,
            "Output must include the 'Files' header.",
        );
        self::assertStringContainsString(
            'pkg/a',
            $out->stdoutBuffer,
            "Output must include the provider 'pkg/a'.",
        );
    }

    public function testRunSeparatorRowIsExactly52DashesFollowedByPhpEol(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        (new ProvidersService())->run($this->tempDir, $out);

        self::assertStringContainsString(
            PHP_EOL . str_repeat('-', 52) . PHP_EOL,
            $out->stdoutBuffer,
            'The horizontal separator row must be exactly 52 dashes on its own line.',
        );
        self::assertStringNotContainsString(
            str_repeat('-', 53),
            $out->stdoutBuffer,
            'The separator must not contain 53 consecutive dashes.',
        );
    }

    public function testRunTableOutputEndsWithSinglePhpEolSuffix(): void
    {
        (new LockFile($this->tempDir))->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:a',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $out = new BufferedOutputWriter();
        (new ProvidersService())->run($this->tempDir, $out);

        self::assertStringEndsWith(
            PHP_EOL,
            $out->stdoutBuffer,
            'The rendered providers table must end with PHP_EOL so the final row is on its own terminal line.',
        );
        self::assertStringEndsNotWith(
            PHP_EOL . PHP_EOL,
            $out->stdoutBuffer,
            'The rendered providers table must end with exactly one PHP_EOL.',
        );
        self::assertStringStartsWith(
            'Provider',
            $out->stdoutBuffer,
            "The rendered providers table must start with the 'Provider' header (not a stray PHP_EOL).",
        );
        self::assertStringContainsString(
            'pkg/a' . str_repeat(' ', 44 - strlen('pkg/a')) . ' 1' . PHP_EOL,
            $out->stdoutBuffer,
            'The provider row must end with one PHP_EOL; the provider name must be left-padded to 44 chars.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }
}
