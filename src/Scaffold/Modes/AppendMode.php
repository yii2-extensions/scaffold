<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function array_count_values;
use function explode;
use function implode;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function substr;

/**
 * Applies a scaffold file by line-merging its content into the destination, or writing it fresh.
 *
 * Idempotent by design: when the destination already exists, only provider lines that are not already present in the
 * destination are appended. Repeated applications produce no duplication, and consumer-specific additions are never
 * removed.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class AppendMode implements ModeInterface
{
    public function apply(
        FileMapping $mapping,
        string $projectRoot,
        Hasher $hasher,
        string|null $hashAtScaffold,
    ): ApplyResult {
        $destination = PathResolver::destination($projectRoot, $mapping->destination);
        $source = PathResolver::source($mapping->providerPath, $mapping->source);

        $providerContent = file_get_contents($source);

        if ($providerContent === false) {
            throw new RuntimeException(sprintf('Could not read source file "%s".', $source));
        }

        PathResolver::ensureDirectory($destination);

        if (!file_exists($destination)) {
            if (file_put_contents($destination, $providerContent) === false) {
                throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
            }

            return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
        }

        $consumerContent = file_get_contents($destination);

        if ($consumerContent === false) {
            throw new RuntimeException(sprintf('Could not read destination file "%s".', $destination));
        }

        // Normalise line endings so CRLF/CR destinations diff identically against an LF stub.
        $providerContent = (string) preg_replace('/\r\n|\r/', "\n", $providerContent);
        $consumerContent = (string) preg_replace('/\r\n|\r/', "\n", $consumerContent);

        $consumerLines = self::splitLines($consumerContent);
        $providerLines = self::splitLines($providerContent);

        // Count-based diff preserves multiplicity: a stub with two 'alpha' lines whose destination has only one
        // appends the second occurrence instead of dropping it as set-based 'array_diff' would.
        $consumerCounts = array_count_values($consumerLines);
        $missing = [];

        foreach ($providerLines as $line) {
            if (isset($consumerCounts[$line]) && $consumerCounts[$line] > 0) {
                $consumerCounts[$line]--;

                continue;
            }

            $missing[] = $line;
        }

        if ($missing === []) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $separator = $consumerContent === '' || str_ends_with($consumerContent, "\n") ? '' : "\n";
        $appendData = $separator . implode("\n", $missing) . "\n";

        if (file_put_contents($destination, $appendData, FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    /**
     * Splits a normalised text into its constituent lines, stripping at most one trailing newline.
     *
     * Stripping only the final EOL marker (not every trailing newline) preserves any intentional blank lines at the
     * end of the file. Otherwise repeated runs would re-detect the trailing blank as missing on every full scaffold,
     * defeating idempotency.
     *
     * @param string $content Normalised text whose lines must be extracted.
     *
     * @return list<string> Ordered list of lines, never including a synthetic trailing empty entry from the final EOL.
     */
    private static function splitLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        return explode("\n", $content);
    }
}
