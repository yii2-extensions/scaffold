<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Security\PathValidator;

/**
 * Unit tests for {@see PathValidator} path traversal and absolute path detection.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
#[Group('scaffold')]
#[Group('lock')]
final class PathValidatorTest extends TestCase
{
    public function testDestinationWithAbsoluteUnixPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('/etc/passwd', sys_get_temp_dir());
    }

    public function testDestinationWithAbsoluteWindowsPathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('C:\\Windows\\System32\\file', sys_get_temp_dir());
    }

    public function testDestinationWithBackslashTraversalThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('config\\..\\..\\etc\\passwd', sys_get_temp_dir());
    }

    public function testDestinationWithDotFileIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('.gitignore', sys_get_temp_dir());
    }

    public function testDestinationWithDoubleDotInFilenameIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        // "foo..bar" has ".." inside a filename segment — not a traversal component.
        (new PathValidator())->validateDestination('foo..bar', sys_get_temp_dir());
    }

    public function testDestinationWithTraversalAtStartThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('../../../etc/passwd', sys_get_temp_dir());
    }

    public function testDestinationWithTraversalInMiddleThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        (new PathValidator())->validateDestination('config/../../../etc/passwd', sys_get_temp_dir());
    }

    public function testNonExistentProjectRootThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new PathValidator())->validateDestination('config/params.php', '/nonexistent/path/' . uniqid());
    }

    public function testNonExistentProviderRootThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new PathValidator())->validateSource('stubs/params.php', '/nonexistent/provider/' . uniqid());
    }

    public function testSourceWithAbsolutePathThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('/etc/shadow', sys_get_temp_dir());
    }

    public function testSourceWithTraversalAtStartThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('../stubs/params.php', sys_get_temp_dir());
    }

    public function testSourceWithTraversalInMiddleThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        (new PathValidator())->validateSource('stubs/../../../etc/shadow', sys_get_temp_dir());
    }

    public function testValidNestedRelativeDestinationPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('resources/js/Pages/Home.vue', sys_get_temp_dir());
    }

    public function testValidRelativeDestinationPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateDestination('config/params.php', sys_get_temp_dir());
    }

    public function testValidRelativeSourcePasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new PathValidator())->validateSource('stubs/params.php', sys_get_temp_dir());
    }
}
