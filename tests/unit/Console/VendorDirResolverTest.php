<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Console\VendorDirResolver;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function getenv;
use function putenv;

/**
 * Unit tests for {@see VendorDirResolver} covering environment variable, `composer.json`, and default fallback
 * resolution paths.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('console')]
final class VendorDirResolverTest extends TestCase
{
    use TempDirectoryTrait;

    private string|false $originalEnv = false;

    public function testComposerJsonAbsoluteVendorDirIsHonored(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['config' => ['vendor-dir' => '/opt/shared-vendor']]),
        );

        self::assertSame(
            '/opt/shared-vendor',
            VendorDirResolver::resolve($this->tempDir),
            "Absolute 'config.vendor-dir' paths must be used verbatim.",
        );
    }

    public function testComposerJsonConfigVendorDirIsHonored(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['config' => ['vendor-dir' => 'third-party']]),
        );

        self::assertSame(
            $this->tempDir . '/third-party',
            VendorDirResolver::resolve($this->tempDir),
            "Relative 'config.vendor-dir' must resolve against the project root.",
        );
    }

    public function testEnvVariableRelativePathResolvedAgainstProjectRoot(): void
    {
        putenv('COMPOSER_VENDOR_DIR=custom-vendor');

        self::assertSame(
            $this->tempDir . '/custom-vendor',
            VendorDirResolver::resolve($this->tempDir),
            'A relative env value must be anchored to the project root.',
        );
    }

    public function testEnvVariableTakesPriorityOverComposerJson(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['config' => ['vendor-dir' => 'ignored']]),
        );

        putenv('COMPOSER_VENDOR_DIR=/absolute/env-vendor');

        self::assertSame(
            '/absolute/env-vendor',
            VendorDirResolver::resolve($this->tempDir),
            "'COMPOSER_VENDOR_DIR' environment variable must take priority over 'composer.json'.",
        );
    }

    public function testFallsBackToDefaultWhenComposerJsonHasNoVendorDirConfig(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['name' => 'test/no-vendor-dir-config']),
        );

        self::assertSame(
            $this->tempDir . '/vendor',
            VendorDirResolver::resolve($this->tempDir),
            "When 'composer.json' has no 'config.vendor-dir' entry, the default must be returned.",
        );
    }

    public function testFallsBackToDefaultWhenComposerJsonIsInvalidJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{ invalid json ');

        self::assertSame(
            $this->tempDir . '/vendor',
            VendorDirResolver::resolve($this->tempDir),
            "Malformed 'composer.json' must degrade gracefully to the default vendor directory.",
        );
    }

    public function testFallsBackToDefaultWhenComposerJsonVendorDirIsEmptyString(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['config' => ['vendor-dir' => '']]),
        );

        self::assertSame(
            $this->tempDir . '/vendor',
            VendorDirResolver::resolve($this->tempDir),
            "An empty 'config.vendor-dir' must be treated as unset.",
        );
    }

    public function testFallsBackToDefaultWhenNoComposerJsonExists(): void
    {
        self::assertSame(
            $this->tempDir . '/vendor',
            VendorDirResolver::resolve($this->tempDir),
            "When no 'composer.json' is present, the default '<project-root>/vendor' must be returned.",
        );
    }

    public function testTrimsTrailingSeparatorFromProjectRootAndVendorDir(): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['config' => ['vendor-dir' => 'custom/']]),
        );

        self::assertSame(
            $this->tempDir . '/custom',
            VendorDirResolver::resolve($this->tempDir . '/'),
            'Trailing separators in project root and vendor-dir must not produce a double separator.',
        );
    }

    public function testWindowsAbsoluteDrivePathIsHonoredVerbatim(): void
    {
        putenv('COMPOSER_VENDOR_DIR=C:\\opt\\vendor');

        self::assertSame(
            'C:\\opt\\vendor',
            VendorDirResolver::resolve($this->tempDir),
            "An absolute Windows path ('C:\\\\opt\\\\vendor') must be used verbatim without being joined to the "
            . 'project root.',
        );
    }

    public function testWindowsDriveRelativePathIsTreatedAsRelativeAndJoinedWithProjectRoot(): void
    {
        putenv('COMPOSER_VENDOR_DIR=C:vendor');

        self::assertSame(
            $this->tempDir . '/C:vendor',
            VendorDirResolver::resolve($this->tempDir),
            "Windows drive-relative 'C:vendor' (no separator after the drive letter) is not absolute and must be "
            . 'resolved against the project root.',
        );
    }

    public function testWindowsDriveRootPreservesTrailingSeparator(): void
    {
        putenv('COMPOSER_VENDOR_DIR=C:\\');

        self::assertSame(
            'C:\\',
            VendorDirResolver::resolve($this->tempDir),
            "The drive root 'C:\\\\' must keep its trailing separator so it stays absolute; stripping it would "
            . "turn it into the drive-relative 'C:' token.",
        );
    }

    public function testWindowsDriveRootWithForwardSlashPreservesTrailingSeparator(): void
    {
        putenv('COMPOSER_VENDOR_DIR=D:/');

        self::assertSame(
            'D:/',
            VendorDirResolver::resolve($this->tempDir),
            'Forward-slash drive roots must also keep their trailing separator intact for the same reason.',
        );
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();

        $this->originalEnv = getenv('COMPOSER_VENDOR_DIR');

        putenv('COMPOSER_VENDOR_DIR');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === false) {
            putenv('COMPOSER_VENDOR_DIR');
        } else {
            putenv('COMPOSER_VENDOR_DIR=' . $this->originalEnv);
        }

        $this->tearDownTempDirectory();
    }
}
