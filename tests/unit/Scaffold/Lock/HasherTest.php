<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Lock;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xepozz\InternalMocker\MockerState;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\tests\support\TempDirectoryTrait;

use function strlen;

/**
 * Unit tests for {@see Hasher} file hash computation.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('lock')]
final class HasherTest extends TestCase
{
    use TempDirectoryTrait;

    public function testEqualsIsCaseSensitive(): void
    {
        self::assertFalse(
            (new Hasher())->equals('sha256:ABC', 'sha256:abc'),
            'Hash comparison should be case-sensitive',
        );
    }

    public function testEqualsReturnsFalseForDifferentHashes(): void
    {
        self::assertFalse(
            (new Hasher())->equals('sha256:abc', 'sha256:xyz'),
            'Hash comparison should return false for different hashes',
        );
    }

    public function testEqualsReturnsTrueForIdenticalHashes(): void
    {
        self::assertTrue(
            (new Hasher())->equals('sha256:abc', 'sha256:abc'),
            'Hash comparison should return true for identical hashes',
        );
    }

    public function testHashDiffersAfterContentChange(): void
    {
        $file = "{$this->tempDir}/file.txt";

        file_put_contents($file, 'original');

        $hasher = new Hasher();

        $before = $hasher->hash($file);

        file_put_contents($file, 'modified');

        self::assertNotSame(
            $before,
            $hasher->hash($file),
            'Hash should differ after file content changes',
        );
    }

    public function testHashOfNonExistentFileThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new Hasher())->hash("{$this->tempDir}/nonexistent.txt");
    }

    public function testHashOfSameContentIsIdempotent(): void
    {
        $file = "{$this->tempDir}/file.txt";

        file_put_contents($file, 'deterministic content');

        $hasher = new Hasher();

        self::assertSame(
            $hasher->hash($file),
            $hasher->hash($file),
            'Hash should be idempotent for the same content',
        );
    }

    public function testHashReturnsSha256PrefixedString(): void
    {
        $file = $this->tempDir . '/file.txt';

        file_put_contents($file, 'hello');

        $hash = (new Hasher())->hash($file);

        self::assertStringStartsWith(
            'sha256:',
            $hash,
            "Hash should start with 'sha256:' prefix",
        );
        // 'sha256:' (7) + 64 hex chars
        self::assertSame(
            7 + 64,
            strlen($hash),
            "Hash length should be '71' characters",
        );
    }

    public function testHashThrowsWhenFileExistsButIsUnreadable(): void
    {
        $file = "{$this->tempDir}/unreadable.txt";

        file_put_contents($file, 'content');

        // force `is_readable()` inside the Lock namespace to report false while the file still exists.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'is_readable',
            [$file],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file is unreadable or does not exist');

        (new Hasher())->hash($file);
    }

    public function testHashThrowsWhenHashFileReturnsFalse(): void
    {
        $file = "{$this->tempDir}/broken-hash.txt";

        file_put_contents($file, 'content');

        // real file is readable; force `hash_file()` itself to report false to hit the post-read failure branch.
        MockerState::addCondition(
            'yii\\scaffold\\Scaffold\\Lock',
            'hash_file',
            [
                'sha256',
                $file,
                false,
                [],
            ],
            false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file is unreadable or does not exist');

        (new Hasher())->hash($file);
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
