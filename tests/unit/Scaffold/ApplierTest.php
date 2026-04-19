<?php

declare(strict_types=1);

namespace yii\scaffold\tests\unit\Scaffold;

use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Applier;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\Modes\{ApplyOutcome, PreserveMode, ReplaceMode};
use yii\scaffold\Security\{PackageAllowlist, PathValidator};
use yii\scaffold\tests\support\TempDirectoryTrait;

use function is_string;

/**
 * Unit tests for {@see Applier} security pre-checks and mode delegation.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1.0
 */
final class ApplierTest extends TestCase
{
    use TempDirectoryTrait;

    public function testDestinationTraversalThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination');

        // traversal is caught before the realpath check; no real project dir needed.
        $this->makeApplier()->apply(
            $this->makeMapping(destination: '../../../etc/passwd'),
            "{$this->tempDir}/project",
            new ReplaceMode(),
            null,
        );
    }

    public function testNoWarningWrittenForSkippedPreserveFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile();
        $io = $this->createMock(IOInterface::class);

        $io->expects(self::never())->method('writeError');

        $applier = new Applier(
            new PackageAllowlist(['yii2-extensions/test']),
            new PathValidator(),
            new Hasher(),
            $io,
        );

        $applier->apply($this->makeMapping(), $projectDir, new PreserveMode(), null);
    }

    public function testPreserveModeSkipsExistingFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'existing');

        $this->makeSourceFile('stub');

        $result = $this->makeApplier()->apply(
            $this->makeMapping(),
            $projectDir,
            new PreserveMode(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'PreserveMode must skip writing the file if it already exists.',
        );
        self::assertSame(
            'existing',
            file_get_contents($projectDir . '/output.txt'),
            'PreserveMode must not overwrite existing files.',
        );
    }

    public function testSourceTraversalThrows(): void
    {
        // validateDestination runs first and needs the project dir to exist.
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source');

        $this->makeApplier()->apply(
            $this->makeMapping(source: '../../../etc/shadow'),
            $projectDir,
            new ReplaceMode(),
            null,
        );
    }

    public function testUnauthorizedProviderThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yii2-extensions/unauthorized');

        $this->makeApplier([])->apply(
            $this->makeMapping(providerName: 'yii2-extensions/unauthorized'),
            "{$this->tempDir}/project",
            new ReplaceMode(),
            null,
        );
    }

    public function testUserModifiedFileForwardsWarningToIo(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);
        file_put_contents($projectDir . '/output.txt', 'user-modified content');

        $this->makeSourceFile('stub content');
        $io = $this->createMock(IOInterface::class);

        $io->expects(self::once())
            ->method('writeError')
            ->with(
                self::callback(
                    static fn(mixed $message): bool => is_string($message)
                        && str_starts_with($message, '[scaffold] ')
                        && str_contains($message, 'User-modified file skipped'),
                ),
            );

        $applier = new Applier(
            new PackageAllowlist(['yii2-extensions/test']),
            new PathValidator(),
            new Hasher(),
            $io,
        );

        $result = $applier->apply(
            $this->makeMapping(),
            $projectDir,
            new ReplaceMode(),
            'sha256:' . hash('sha256', 'completely-different-content'),
        );

        self::assertSame(
            ApplyOutcome::Skipped,
            $result->outcome,
            'ReplaceMode must skip writing the file if it has been modified by the user.',
        );
    }

    public function testValidReplaceModeWritesFile(): void
    {
        $projectDir = "{$this->tempDir}/project";

        mkdir($projectDir, 0777, recursive: true);

        $this->makeSourceFile();
        $result = $this->makeApplier()->apply(
            $this->makeMapping(),
            $projectDir,
            new ReplaceMode(),
            null,
        );

        self::assertSame(
            ApplyOutcome::Written,
            $result->outcome,
            'ReplaceMode must write the file if it does not exist or has not been modified by the user.',
        );
        self::assertFileExists(
            "$projectDir/output.txt",
            'ReplaceMode must create the file if it does not exist.',
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

    /**
     * Creates an instance of Applier with a specified allowlist for testing.
     *
     * @param list<string> $allowedPackages List of allowed package names for the Applier's PackageAllowlist; defaults
     * to ['yii2-extensions/test'].
     *
     * @return Applier Configured Applier instance for testing, with the specified allowlist and a NullIO for output.
     */
    private function makeApplier(array $allowedPackages = ['yii2-extensions/test']): Applier
    {
        return new Applier(
            new PackageAllowlist($allowedPackages),
            new PathValidator(),
            new Hasher(),
            new NullIO(),
        );
    }

    /**
     * Builds a {@see FileMapping} fixture wired to the test provider path for use in the applier scenarios.
     *
     * @param string $destination Destination path relative to the project root.
     * @param string $source Relative source path inside the provider root.
     * @param string $providerName Provider package name for the mapping.
     *
     * @return FileMapping File mapping used for the test, with the specified destination, source, and provider.
     */
    private function makeMapping(
        string $destination = 'output.txt',
        string $source = 'stubs/source.txt',
        string $providerName = 'yii2-extensions/test',
    ): FileMapping {
        return new FileMapping(
            destination: $destination,
            source: $source,
            mode: 'replace',
            providerName: $providerName,
            providerPath: $this->tempDir . '/provider',
        );
    }

    /**
     * Creates the provider's stub file and the project's current file with the specified content.
     *
     * @param string $content Content to write to the provider's stub file.
     */
    private function makeSourceFile(string $content = 'stub content'): void
    {
        $path = "{$this->tempDir}/provider/stubs/source.txt";

        $this->ensureTestDirectory(dirname($path));

        file_put_contents($path, $content);
    }
}
