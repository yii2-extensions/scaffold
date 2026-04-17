<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold\Lock;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\tests\support\TempDirectoryTrait;

/**
 * Unit tests for {@see Hasher} file hash computation.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class HasherTest extends TestCase
{
    use TempDirectoryTrait;

    public function testEqualsIsCaseSensitive(): void
    {
        self::assertFalse((new Hasher())->equals('sha256:ABC', 'sha256:abc'));
    }

    public function testEqualsReturnsFalseForDifferentHashes(): void
    {
        self::assertFalse((new Hasher())->equals('sha256:abc', 'sha256:xyz'));
    }

    public function testEqualsReturnsTrueForIdenticalHashes(): void
    {
        self::assertTrue((new Hasher())->equals('sha256:abc', 'sha256:abc'));
    }

    public function testHashDiffersAfterContentChange(): void
    {
        $file = $this->tempDir . '/file.txt';
        file_put_contents($file, 'original');

        $hasher = new Hasher();
        $before = $hasher->hash($file);

        file_put_contents($file, 'modified');

        self::assertNotSame($before, $hasher->hash($file));
    }

    public function testHashOfNonExistentFileThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new Hasher())->hash($this->tempDir . '/nonexistent.txt');
    }

    public function testHashOfSameContentIsIdempotent(): void
    {
        $file = $this->tempDir . '/file.txt';
        file_put_contents($file, 'deterministic content');

        $hasher = new Hasher();

        self::assertSame($hasher->hash($file), $hasher->hash($file));
    }

    public function testHashReturnsSha256PrefixedString(): void
    {
        $file = $this->tempDir . '/file.txt';
        file_put_contents($file, 'hello');

        $hash = (new Hasher())->hash($file);

        self::assertStringStartsWith('sha256:', $hash);
        self::assertSame(7 + 64, strlen($hash)); // "sha256:" (7) + 64 hex chars
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
