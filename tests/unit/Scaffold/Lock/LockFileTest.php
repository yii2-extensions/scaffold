<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Lock;

use JsonException;
use PHPUnit\Framework\TestCase;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see LockFile} read/write operations and hash lookup.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class LockFileTest extends TestCase
{
    use TempDirectoryTrait;

    public function testExistsReturnsFalseWhenFileAbsent(): void
    {
        self::assertFalse((new LockFile($this->tempDir))->exists());
    }

    public function testExistsReturnsTrueAfterWrite(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write(['providers' => [], 'files' => []]);

        self::assertTrue($lock->exists());
    }

    public function testGetHashAtScaffoldReturnsNullForUntrackedDestination(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write([
            'providers' => [],
            'files' => [
                'config/params.php' => ['hash' => 'sha256:abc', 'provider' => 'p/n', 'source' => 'stubs/a.php', 'mode' => 'replace'],
            ],
        ]);

        self::assertNull($lock->getHashAtScaffold('config/web.php'));
    }

    public function testGetHashAtScaffoldReturnsNullWhenMissing(): void
    {
        self::assertNull((new LockFile($this->tempDir))->getHashAtScaffold('config/params.php'));
    }

    public function testGetHashAtScaffoldReturnsRecordedHash(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write([
            'providers' => [],
            'files' => [
                'config/params.php' => ['hash' => 'sha256:deadbeef', 'provider' => 'p/n', 'mode' => 'replace'],
            ],
        ]);

        self::assertSame('sha256:deadbeef', $lock->getHashAtScaffold('config/params.php'));
    }

    public function testGetPathReturnsExpectedPath(): void
    {
        self::assertSame(
            $this->tempDir . '/scaffold-lock.json',
            (new LockFile($this->tempDir))->getPath(),
        );
    }

    public function testReadReturnsDefaultStructureWhenMissing(): void
    {
        $data = (new LockFile($this->tempDir))->read();

        self::assertSame([], $data['providers']);
        self::assertSame([], $data['files']);
    }

    public function testReadThrowsOnMalformedJson(): void
    {
        file_put_contents($this->tempDir . '/scaffold-lock.json', '{ invalid json }');

        $this->expectException(JsonException::class);

        (new LockFile($this->tempDir))->read();
    }

    public function testWriteAndReadRoundTrip(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write([
            'providers' => [],
            'files' => [
                'config/params.php' => ['hash' => 'sha256:abc', 'provider' => 'pkg/name', 'mode' => 'preserve'],
            ],
        ]);

        $data = $lock->read();

        $entry = $data['files']['config/params.php'] ?? null;

        if ($entry === null) {
            self::fail('Expected "config/params.php" entry in lock file.');
        }

        self::assertSame('sha256:abc', $entry['hash']);
        self::assertSame('pkg/name', $entry['provider']);
        self::assertSame('preserve', $entry['mode']);
    }

    public function testWriteProducesValidJson(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write(['providers' => [], 'files' => []]);

        $raw = file_get_contents($this->tempDir . '/scaffold-lock.json');

        self::assertNotFalse($raw);

        $decoded = json_decode($raw, associative: true);

        self::assertIsArray($decoded);
    }

    public function testWriteUsesUnescapedSlashes(): void
    {
        $lock = new LockFile($this->tempDir);
        $lock->write([
            'providers' => [],
            'files' => [
                'config/params.php' => ['hash' => 'sha256:abc', 'provider' => 'pkg/name', 'mode' => 'replace'],
            ],
        ]);

        $raw = file_get_contents($this->tempDir . '/scaffold-lock.json');

        self::assertNotFalse($raw);
        self::assertStringContainsString('config/params.php', $raw);
        self::assertStringNotContainsString('config\/params.php', $raw);
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
