<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function explode;
use function implode;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function substr;

/**
 * Applies a scaffold file by line-merging its content into the destination, or writing it fresh.
 *
 * Idempotent by design: when the destination already exists, provider lines missing from it are inserted at their
 * contextual position relative to shared anchor lines (LCS alignment). Repeated applications produce no duplication,
 * and consumer-specific additions are never removed or reordered.
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

        // Detect the destination EOL style BEFORE normalising so the appended block can be emitted in the same style,
        // avoiding mixed CRLF/LF output on Windows-style files.
        $eol = self::detectEol($consumerContent);

        // Normalise line endings so CRLF/CR destinations diff identically against an LF stub.
        $providerContent = (string) preg_replace('/\r\n|\r/', "\n", $providerContent);
        $consumerContent = (string) preg_replace('/\r\n|\r/', "\n", $consumerContent);

        $consumerLines = self::splitLines($consumerContent);
        $providerLines = self::splitLines($providerContent);

        $differ = new Differ(new DiffOnlyOutputBuilder(''));

        /** @var list<array{0: string, 1: int}> $entries */
        $entries = $differ->diffToArray($providerLines, $consumerLines);

        // LCS-aligned merge: provider-only lines ('REMOVED') buffer until the next shared anchor ('OLD') so they land
        // at their contextual position, while consumer-only lines ('ADDED') stay in place ahead of them. LCS handles
        // duplicate multiplicity natively: a stub with two 'alpha' lines whose destination has one inserts the second.
        $merged = [];
        $pending = [];
        $hasMissing = false;

        foreach ($entries as [$line, $type]) {
            if ($type === Differ::REMOVED) {
                $pending[] = $line;
                $hasMissing = true;

                continue;
            }

            if ($type === Differ::OLD && $pending !== []) {
                $merged = [...$merged, ...$pending];
                $pending = [];
            }

            $merged[] = $line;
        }

        if (!$hasMissing) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $endsWithProviderLine = $pending !== [];
        $merged = [...$merged, ...$pending];

        // Preserve the consumer's trailing-newline choice unless provider lines land at the end of the file, which
        // must be newline-terminated exactly as the former append behavior produced.
        $trailing = $endsWithProviderLine || str_ends_with($consumerContent, "\n") ? $eol : '';

        if (file_put_contents($destination, implode($eol, $merged) . $trailing) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    /**
     * Detects the dominant end-of-line sequence used by `$content`.
     *
     * Returns `"\r\n"` for Windows-style content, `"\r"` for legacy Mac-style content, and `"\n"` otherwise (Unix or
     * empty). The result is used to emit appended lines in the same EOL style as the destination so the file does not
     * end up with mixed line endings.
     *
     * @param string $content Original (un-normalised) destination content.
     *
     * @return string The detected EOL sequence.
     */
    private static function detectEol(string $content): string
    {
        if (str_contains($content, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($content, "\r")) {
            return "\r";
        }

        return "\n";
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
