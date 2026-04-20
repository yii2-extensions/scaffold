<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Manifest;

use Composer\Package\PackageInterface;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Manifest\{FileMode, ManifestExpander, ManifestLoader, ManifestSchema};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function file_put_contents;
use function json_encode;

/**
 * Unit tests for {@see ManifestLoader} inline and external manifest loading with the `copy`/`exclude`/`modes` schema.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('manifest')]
final class ManifestLoaderTest extends TestCase
{
    use TempDirectoryTrait;

    public function testLoadAcceptsExternalManifestPathContainingColonInNonLeadingPosition(): void
    {
        // Pins the '^' anchor in '/^[A-Za-z]:/': non-leading colons (stream wrappers, namespaces) must not be rejected.
        $manifest = ['copy' => ['src']];

        $this->seedFile('src/Foo.php');
        $this->ensureTestDirectory("{$this->tempDir}/subdir");

        file_put_contents("{$this->tempDir}/subdir/a:b.json", (string) json_encode($manifest));

        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'subdir/a:b.json']]);
        $package->method('getName')->willReturn('pkg/example');

        $result = $this->loader()->load($package, $this->tempDir);

        self::assertCount(
            1,
            $result,
            "A manifest path whose non-leading segment contains '[A-Za-z]:' must load; the drive-letter check fires only on leading.",
        );
    }

    public function testLoadExpandsInlineManifest(): void
    {
        $this->seedFile('src/Foo.php');
        $this->seedFile('config/params.php');

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(
                [
                    'scaffold' => [
                        'copy' => ['src', 'config'],
                        'modes' => ['config/*.php' => 'preserve'],
                    ],
                ],
            );
        $package->method('getName')->willReturn('pkg/example');

        $result = $this->loader()->load($package, $this->tempDir);

        self::assertCount(
            2,
            $result,
            'Inline manifest must produce one FileMapping per expanded file.',
        );

        $modes = [];

        foreach ($result as $mapping) {
            $modes[$mapping->destination] = $mapping->mode;

            self::assertSame(
                'pkg/example',
                $mapping->providerName,
                'Every FileMapping must be attributed to the provider name.',
            );
        }

        self::assertSame(
            FileMode::Preserve,
            $modes['config/params.php'] ?? null,
            "'config/*.php' glob must resolve to FileMode::Preserve.",
        );
        self::assertSame(
            FileMode::Replace,
            $modes['src/Foo.php'] ?? null,
            'Files with no matching mode must default to FileMode::Replace.',
        );
    }

    public function testLoadExternalManifestPreservesEveryDeclaredKey(): void
    {
        $this->seedFile('src/Foo.php');
        $this->seedFile('config/params.php');
        $this->seedFile('config/web.php');

        $manifest = [
            'copy' => ['src', 'config'],
            'exclude' => ['config/secrets.php'],
            'modes' => ['config/*.php' => 'preserve'],
        ];

        file_put_contents("{$this->tempDir}/scaffold.json", (string) json_encode($manifest));

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('pkg/example');

        $result = $this->loader()->load($package, $this->tempDir);

        $destinations = [];

        foreach ($result as $mapping) {
            $destinations[$mapping->destination] = $mapping->mode;
        }

        self::assertArrayHasKey(
            'src/Foo.php',
            $destinations,
            "The 'src' copy root must survive decoding; losing the second copy entry would hide this destination.",
        );
        self::assertArrayHasKey(
            'config/params.php',
            $destinations,
            "The 'config' copy root must survive decoding; it is declared as the second entry of 'copy[]'.",
        );
        self::assertSame(
            FileMode::Preserve,
            $destinations['config/params.php'] ?? null,
            "The 'modes{}' key must survive decoding so the glob applies the expected mode.",
        );
    }

    public function testLoadReadsExternalManifestRelativeToProviderRoot(): void
    {
        $this->seedFile('src/Foo.php');

        $manifest = ['copy' => ['src']];

        file_put_contents("{$this->tempDir}/scaffold.json", (string) json_encode($manifest));

        $package = self::createStub(PackageInterface::class);

        $package
            ->method('getExtra')
            ->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('pkg/example');

        $result = $this->loader()->load($package, $this->tempDir);

        self::assertCount(
            1,
            $result,
            'External manifest must produce mappings identical to an equivalent inline declaration.',
        );
    }

    public function testLoadReturnsEmptyWhenExtraDoesNotDeclareScaffold(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn([]);

        self::assertSame(
            [],
            $this->loader()->load($package, $this->tempDir),
            'Absence of the "scaffold" key must produce no mappings.',
        );
    }

    public function testLoadReturnsEmptyWhenScaffoldIsNotArray(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => 'invalid']);

        self::assertSame(
            [],
            $this->loader()->load($package, $this->tempDir),
            'Non-array "scaffold" value must produce no mappings.',
        );
    }

    public function testLoadThrowsWhenExternalManifestCannotBeRead(): void
    {
        // Forces 'file_get_contents' to 'false' to cover the race where 'is_file()' succeeds but reading then fails.
        $manifestPath = "{$this->tempDir}/scaffold.json";

        file_put_contents($manifestPath, '{}');

        MockerState::addCondition(
            'yii\\scaffold\\Manifest',
            'file_get_contents',
            [$manifestPath, false, null, 0, null],
            false,
        );

        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('pkg/unreadable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not read manifest file');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestDecodesToNonObject(): void
    {
        file_put_contents("{$this->tempDir}/scaffold.json", '"just-a-string"');

        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decode to an object');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestFileIsMissing(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'missing.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest file not found');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestJsonIsInvalid(): void
    {
        file_put_contents("{$this->tempDir}/scaffold.json", '{ not valid json ');

        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'scaffold.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(JsonException::class);

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathContainsTraversal(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => '../escape.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathIsAbsolute(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => '/etc/malicious.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathIsAbsoluteWindowsBackslash(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => '\\Windows\\scaffold.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathIsAbsoluteWindowsDrive(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 'C:\\malicious.json']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relative path');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathIsEmptyString(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => '']]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenExternalManifestPathIsNotString(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['manifest' => 42]]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->loader()->load($package, $this->tempDir);
    }

    public function testLoadThrowsWhenInlineManifestOmitsCopy(): void
    {
        $package = self::createStub(PackageInterface::class);

        $package->method('getExtra')->willReturn(['scaffold' => ['modes' => ['*.php' => 'replace']]]);
        $package->method('getName')->willReturn('pkg/bad');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"copy"');

        $this->loader()->load($package, $this->tempDir);
    }

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    /**
     * Builds a {@see ManifestLoader} with real dependencies.
     */
    private function loader(): ManifestLoader
    {
        return new ManifestLoader(new ManifestSchema(), new ManifestExpander());
    }

    /**
     * Writes an empty file at `$relative` under the temp directory, creating intermediate directories as needed.
     */
    private function seedFile(string $relative): void
    {
        $absolute = "{$this->tempDir}/{$relative}";

        $this->ensureTestDirectory(dirname($absolute));

        file_put_contents($absolute, '');
    }
}
