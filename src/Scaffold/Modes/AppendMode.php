<?php

declare(strict_types=1);

namespace yii\scaffold\Scaffold\Modes;

use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;
use yii\scaffold\Manifest\FileMapping;
use yii\scaffold\Scaffold\Lock\Hasher;
use yii\scaffold\Scaffold\PathResolver;

use function array_column;
use function array_count_values;
use function count;
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
 * contextual position relative to shared anchor lines (LCS alignment). Presence is decided by order-independent line
 * counting, so a destination holding every provider line in a different order is left untouched. Existing lines keep
 * their original terminators (mixed EOLs included) while inserted lines use the destination's dominant EOL. Repeated
 * applications produce no duplication, and consumer-specific additions are never removed or reordered.
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

        // Dominant EOL style is used for inserted provider lines only; existing lines keep their own terminators.
        $eol = self::detectEol($consumerContent);

        // Normalise the provider only: consumer lines are split with their raw terminators preserved, and the diff
        // compares terminator-free texts, so CRLF/CR destinations still diff identically against an LF stub.
        $providerContent = (string) preg_replace('/\r\n|\r/', "\n", $providerContent);

        $segments = self::splitRawSegments($consumerContent);
        $consumerLines = array_column($segments, 0);
        $providerLines = self::splitLines($providerContent);

        // Count-based multiset decides WHAT is missing (order-independent, multiplicity-preserving): a provider line
        // is missing only when the destination holds fewer copies of it, so reordered destinations never duplicate.
        $consumerCounts = array_count_values($consumerLines);
        $missingCounts = [];

        foreach ($providerLines as $line) {
            if (isset($consumerCounts[$line]) && $consumerCounts[$line] > 0) {
                $consumerCounts[$line]--;

                continue;
            }

            $missingCounts[$line] = ($missingCounts[$line] ?? 0) + 1;
        }

        if ($missingCounts === []) {
            return new ApplyResult(ApplyOutcome::Skipped, $hasher->hash($destination), null);
        }

        $differ = new Differ(new DiffOnlyOutputBuilder(''));

        /** @var list<array{0: string, 1: int}> $entries */
        $entries = $differ->diffToArray($providerLines, $consumerLines);

        // LCS alignment decides WHERE missing lines land: provider-only entries ('REMOVED') buffer until the next
        // shared anchor ('OLD'), recording the insertion point as "before consumer segment N", while consumer-only
        // lines ('ADDED') stay in place ahead of them. 'REMOVED' entries with no remaining missing quota are reorder
        // artifacts: the same line exists elsewhere in the destination, so they are dropped instead of duplicated.
        $pending = [];
        $insertions = [];
        $consumerIndex = 0;

        foreach ($entries as [$line, $type]) {
            if ($type === Differ::REMOVED) {
                if (isset($missingCounts[$line]) && $missingCounts[$line] > 0) {
                    $missingCounts[$line]--;
                    $pending[] = $line;
                }

                continue;
            }

            if ($type === Differ::OLD && $pending !== []) {
                $insertions[$consumerIndex] = $pending;
                $pending = [];
            }

            $consumerIndex++;
        }

        // Consumer segments are re-emitted verbatim (text plus original terminator) so existing line endings are
        // never rewritten; only inserted provider lines use the dominant EOL.
        $output = '';

        foreach ($segments as $index => [$text, $segmentEol]) {
            if (isset($insertions[$index])) {
                $output .= implode($eol, $insertions[$index]) . $eol;
            }

            $output .= $text . $segmentEol;
        }

        if ($pending !== []) {
            $separator = $output === '' || str_ends_with($output, "\n") || str_ends_with($output, "\r") ? '' : $eol;
            $output .= $separator . implode($eol, $pending) . $eol;
        }

        if (file_put_contents($destination, $output) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $destination));
        }

        return new ApplyResult(ApplyOutcome::Written, $hasher->hash($destination), null);
    }

    /**
     * Detects the dominant end-of-line sequence used by `$content`.
     *
     * Returns `"\r\n"` for Windows-style content, `"\r"` for legacy Mac-style content, and `"\n"` otherwise (Unix or
     * empty). The result is used to emit inserted provider lines in the destination's dominant EOL style; existing
     * lines keep their own terminators.
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

    /**
     * Splits raw destination content into `[text, terminator]` segments, preserving each line's original EOL bytes.
     *
     * Recognises `"\r\n"`, `"\r"`, and `"\n"` terminators so the produced texts align one-to-one with the normalised
     * provider lines used for diffing, while re-emission keeps mixed line endings byte-identical. Built on `explode`,
     * which always returns `array<string>`, avoiding the defensive false-branch `preg_split` would have produced.
     *
     * @param string $content Raw (un-normalised) destination content.
     *
     * @return list<array{string, string}> Ordered `[text, terminator]` pairs; the final terminator is empty when the
     * content does not end with a newline.
     */
    private static function splitRawSegments(string $content): array
    {
        $segments = [];

        $chunks = explode("\n", $content);
        $lastChunk = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $chunkEol = $index < $lastChunk ? "\n" : '';

            if ($chunkEol === "\n" && str_ends_with($chunk, "\r")) {
                $chunk = substr($chunk, 0, -1);
                $chunkEol = "\r\n";
            }

            // Legacy Mac CR terminators inside the chunk produce their own segments; the final piece takes the chunk
            // terminator, except for the synthetic empty piece a trailing terminator leaves behind.
            $pieces = explode("\r", $chunk);
            $lastPiece = count($pieces) - 1;

            foreach ($pieces as $pieceIndex => $piece) {
                if ($pieceIndex < $lastPiece) {
                    $segments[] = [$piece, "\r"];
                } elseif ($piece !== '' || $chunkEol !== '') {
                    $segments[] = [$piece, $chunkEol];
                }
            }
        }

        return $segments;
    }
}
