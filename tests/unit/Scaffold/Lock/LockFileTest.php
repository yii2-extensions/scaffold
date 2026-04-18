<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Lock;

use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\Lock\LockFile;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see LockFile} read/write operations and hash lookup.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('lock')]
final class LockFileTest extends TestCase
{
    use TempDirectoryTrait;

    public function testExistsReturnsFalseWhenFileAbsent(): void
    {
        self::assertFalse(
            (new LockFile($this->tempDir))->exists(),
            'Lock file should not exist before being created',
        );
    }

    public function testExistsReturnsTrueAfterWrite(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(['providers' => [], 'files' => []]);

        self::assertTrue(
            $lock->exists(),
            'Lock file should exist after being created',
        );
    }

    public function testGetHashAtScaffoldReturnsNullForUntrackedDestination(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'p/n',
                        'source' => 'stubs/a.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        self::assertNull(
            $lock->getHashAtScaffold('config/web.php'),
            "Return 'null' for untracked destination",
        );
    }

    public function testGetHashAtScaffoldReturnsNullWhenMissing(): void
    {
        self::assertNull(
            (new LockFile($this->tempDir))->getHashAtScaffold('config/params.php'),
            "Return 'null' when hash is missing",
        );
    }

    public function testGetHashAtScaffoldReturnsRecordedHash(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:deadbeef',
                        'provider' => 'p/n',
                        'source' => 'stubs/a.php',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        self::assertSame(
            'sha256:deadbeef',
            $lock->getHashAtScaffold('config/params.php'),
            'Return the recorded hash for the tracked destination',
        );
    }

    public function testGetPathReturnsExpectedPath(): void
    {
        self::assertSame(
            rtrim($this->tempDir, '/\\') . DIRECTORY_SEPARATOR . 'scaffold-lock.json',
            (new LockFile($this->tempDir))->getPath(),
            'Return the expected lock file path',
        );
    }

    public function testGetPathStripsTrailingSlashFromProjectRoot(): void
    {
        self::assertSame(
            '/tmp/project' . DIRECTORY_SEPARATOR . 'scaffold-lock.json',
            (new LockFile('/tmp/project/'))->getPath(),
            'Trailing separator on the project root must not produce a double separator in the lock file path.',
        );
    }

    public function testReadCachesResultAfterFirstCall(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'a.txt' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/a',
                        'source' => 'stubs/a.txt',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $first = $lock->read();

        // remove the file from disk — subsequent reads must still hit the in-memory cache.
        unlink($this->tempDir . '/scaffold-lock.json');

        self::assertSame(
            $first,
            $lock->read(),
            'Must return the cached data without touching the filesystem again.',
        );
    }

    public function testReadPreservesProvidersEntries(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [
                    'pkg/name' => ['path' => '/vendor/pkg/name'],
                ],
                'files' => [],
            ],
        );

        $data = $lock->read();

        self::assertArrayHasKey(
            'providers',
            $data,
            "Must include the 'providers' key in the returned structure.",
        );
        self::assertSame(
            ['path' => '/vendor/pkg/name'],
            $data['providers']['pkg/name'] ?? null,
            'Provider entries persisted on disk must be preserved by read.',
        );
    }

    public function testReadReturnsDefaultStructureWhenMissing(): void
    {
        $data = (new LockFile($this->tempDir))->read();

        self::assertSame(
            [],
            $data['providers'],
            'Return empty providers array when lock file is missing',
        );
        self::assertSame(
            [],
            $data['files'],
            'Return empty files array when lock file is missing',
        );
    }

    public function testReadThrowsOnMalformedJson(): void
    {
        file_put_contents($this->tempDir . '/scaffold-lock.json', '{ invalid json }');

        $this->expectException(JsonException::class);

        (new LockFile($this->tempDir))->read();
    }

    public function testReadThrowsWhenFileGetContentsFails(): void
    {
        $lock = new LockFile($this->tempDir);

        // ask the LockFile for the exact path so the mocker match honors the platform's DIRECTORY_SEPARATOR.
        $path = $lock->getPath();

        file_put_contents($path, '{"providers":{},"files":{}}');

        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'file_get_contents',
            [$path, false, null, 0, null],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read lock file');

        $lock->read();
    }

    public function testReadThrowsWhenJsonDecodesToNonObject(): void
    {
        // valid JSON, but it decodes to a scalar — `is_array($decoded)` must be `false`, triggering the structural check.
        file_put_contents($this->tempDir . '/scaffold-lock.json', '"just a string"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain a valid JSON object');

        (new LockFile($this->tempDir))->read();
    }

    public function testWriteAndReadRoundTrip(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'mode' => 'preserve',
                    ],
                ],
            ],
        );

        $data = $lock->read();

        $entry = $data['files']['config/params.php'] ?? null;

        if ($entry === null) {
            self::fail("Expected 'config/params.php' entry in lock file.");
        }

        self::assertSame(
            'sha256:abc',
            $entry['hash'],
            'Hash should match the value written to the lock file',
        );
        self::assertSame(
            'pkg/name',
            $entry['provider'],
            'Provider should match the value written to the lock file',
        );
        self::assertSame(
            'preserve',
            $entry['mode'],
            'Mode should match the value written to the lock file',
        );
    }

    public function testWriteProducesValidJson(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(['providers' => [], 'files' => []]);

        $raw = file_get_contents($this->tempDir . '/scaffold-lock.json');

        self::assertNotFalse(
            $raw,
            'Failed to read the lock file',
        );

        $decoded = json_decode($raw, associative: true);

        self::assertIsArray(
            $decoded,
            'Decoded JSON should be an array',
        );
    }

    public function testWriteThrowsWhenPersistenceFails(): void
    {
        $lock = new LockFile($this->tempDir);

        $path = $lock->getPath();

        $tmp = "{$path}.tmp";

        // simulate `file_put_contents` succeeding but `rename` failing, exercising the cleanup + throw branch.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'rename',
            [
                $tmp,
                $path,
                null,
            ],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write lock file');

        $lock->write(['providers' => [], 'files' => []]);
    }

    public function testWriteUsesUnescapedSlashes(): void
    {
        $lock = new LockFile($this->tempDir);

        $lock->write(
            [
                'providers' => [],
                'files' => [
                    'config/params.php' => [
                        'hash' => 'sha256:abc',
                        'provider' => 'pkg/name',
                        'mode' => 'replace',
                    ],
                ],
            ],
        );

        $raw = file_get_contents($this->tempDir . '/scaffold-lock.json');

        self::assertNotFalse(
            $raw,
            'Failed to read the lock file',
        );
        self::assertStringContainsString(
            'config/params.php',
            $raw,
            'File paths should be written with unescaped slashes',
        );
        self::assertStringNotContainsString(
            'config\/params.php',
            $raw,
            'File paths should not contain escaped slashes',
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
