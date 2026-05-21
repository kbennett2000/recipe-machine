<?php

declare(strict_types=1);

namespace App\Recipes\Cooking;

/**
 * Scans a chunk of method-step prose and returns the duration phrases that
 * should become tappable timers in cooking mode.
 *
 * Recognized shapes:
 *
 *   - Single: "35 minutes", "1 hour", "30 sec", "5m", "1h", "30s"
 *   - Range:  "35-40 minutes", "60–90 minutes", "30 to 45 min"
 *   - Cross-unit range: "30 minutes to 1 hour", "45 seconds to 2 minutes"
 *     (unit-A and unit-B differ; each side gets converted to seconds with
 *     its own unit, so the low/high pair can span unit boundaries)
 *   - Compound: "1 hour 30 minutes", "1h30m"  (adjacent unit-bearing pairs;
 *     whitespace between them is optional)
 *   - Half-units: "1½ hours", "1 1/2 hours" (unicode and ASCII fractions)
 *
 * Does NOT match temperatures (`350°F`, `175 C`), bare numbers, or anything
 * not followed by a recognized unit word. The temperature highlighter is a
 * separate concern (see Phase 3's `MethodFormatter` / cooking mode's
 * `CookingStepFormatter`).
 *
 * Returned matches are sorted by their position in the input. When timer
 * phrases overlap (e.g. a single match nested inside a compound), the
 * compound match wins and the inner single is dropped.
 */
final class TimerExtractor
{
    /** Unicode fraction → decimal value. */
    private const UNICODE_FRACTIONS = [
        '¼' => 0.25, '½' => 0.5, '¾' => 0.75,
        '⅓' => 1.0 / 3.0, '⅔' => 2.0 / 3.0,
        '⅕' => 0.2, '⅖' => 0.4, '⅗' => 0.6, '⅘' => 0.8,
        '⅙' => 1.0 / 6.0, '⅚' => 5.0 / 6.0,
        '⅛' => 0.125, '⅜' => 0.375, '⅝' => 0.625, '⅞' => 0.875,
    ];

    /** Unit string → multiplier into seconds. */
    private const UNIT_SECONDS = [
        'second' => 1, 'seconds' => 1, 'sec' => 1, 'secs' => 1, 's' => 1,
        'minute' => 60, 'minutes' => 60, 'min' => 60, 'mins' => 60, 'm' => 60,
        'hour'   => 3600, 'hours' => 3600, 'hr' => 3600, 'hrs' => 3600, 'h' => 3600,
    ];

    /**
     * @return array<TimerMatch>
     */
    public function extract(string $methodText): array
    {
        $numTok = $this->numTokenPattern();
        // Boundary `(?:\b|(?=\d))` lets the unit be followed by either a non-word char
// (the usual word boundary case — "30 minutes ", "5m.") OR another digit, which
// is what makes compound forms like "1h30m" parseable. Without the digit-ahead
// branch, the \b between `h` and `3` would never fire and we'd only match the
// trailing "30m".
$unit = '(?:hours|hour|hrs|hr|h|minutes|minute|mins|min|m|seconds|second|secs|sec|s)(?:\b|(?=\d))';

        // Range pattern: <num> [- or to] <num> <unit>
        $rangePattern = "({$numTok})\\s*(?:[-\x{2013}\x{2014}]|\\s+to\\s+)\\s*({$numTok})\\s*({$unit})";
        // Cross-unit range: <num> <unitA> to <num> <unitB>. Recipes occasionally
        // write durations that cross unit boundaries — "30 minutes to 1 hour",
        // "45 seconds to 2 minutes". The connector is always the word "to"
        // (hyphens don't appear with explicit units on each side in the
        // corpus). Tried BEFORE compound so we don't fall back to matching
        // each side as a separate single timer.
        $crossRangePattern = "({$numTok})\\s*({$unit})\\s+to\\s+({$numTok})\\s*({$unit})";
        // Single pattern: <num> <unit>
        $singlePattern = "({$numTok})\\s*({$unit})";

        // Compound: a chain of single-pattern matches separated by optional whitespace.
        // We capture the WHOLE compound run first; then we split it ourselves.
        $compoundPattern = "(?:{$singlePattern})(?:\\s*(?:{$singlePattern}))*";

        // Master pattern: cross-unit-range, then same-unit range, then compound.
        // Order matters — cross-range must come before compound so "30 minutes
        // to 1 hour" doesn't get split into two singles. Same-unit range comes
        // before compound so "30 to 45 min" wins over a phantom "45 min" single.
        //
        // We use 'u' for Unicode and '\b' boundaries to avoid matching "5 mg" or "5m" inside "5month".
        $master = '/('.$crossRangePattern.'|'.$rangePattern.'|'.$compoundPattern.')/uS';

        if (! preg_match_all($master, $methodText, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $results = [];
        foreach ($matches[0] as $hit) {
            [$raw, $offset] = $hit;
            $length = strlen($raw);
            $tm = $this->parseMatch($raw, $offset, $length);
            if ($tm !== null) {
                $results[] = $tm;
            }
        }

        // Sort by offset and drop overlaps (longest match wins at each starting offset).
        usort($results, fn (TimerMatch $a, TimerMatch $b) => $a->offset <=> $b->offset);
        $deduped = [];
        $cursor = -1;
        foreach ($results as $tm) {
            if ($tm->offset < $cursor) {
                continue;
            }
            $deduped[] = $tm;
            $cursor = $tm->offset + $tm->length;
        }
        return $deduped;
    }

    /**
     * Parse a raw matched substring into a TimerMatch.
     */
    private function parseMatch(string $raw, int $offset, int $length): ?TimerMatch
    {
        $trimmed = trim($raw);
        $numTok = $this->numTokenPattern();
        // Boundary `(?:\b|(?=\d))` lets the unit be followed by either a non-word char
// (the usual word boundary case — "30 minutes ", "5m.") OR another digit, which
// is what makes compound forms like "1h30m" parseable. Without the digit-ahead
// branch, the \b between `h` and `3` would never fire and we'd only match the
// trailing "30m".
$unit = '(?:hours|hour|hrs|hr|h|minutes|minute|mins|min|m|seconds|second|secs|sec|s)(?:\b|(?=\d))';

        // Cross-unit range first: "30 minutes to 1 hour". Each side carries
        // its own unit, so we convert independently into seconds.
        if (preg_match("/^({$numTok})\\s*({$unit})\\s+to\\s+({$numTok})\\s*({$unit})\$/u", $trimmed, $m)) {
            $loVal = $this->parseNum($m[1]);
            $hiVal = $this->parseNum($m[3]);
            $loSecondsPerUnit = self::UNIT_SECONDS[strtolower($m[2])] ?? null;
            $hiSecondsPerUnit = self::UNIT_SECONDS[strtolower($m[4])] ?? null;
            if ($loVal === null || $hiVal === null || $loSecondsPerUnit === null || $hiSecondsPerUnit === null) {
                return null;
            }
            return new TimerMatch(
                raw: $raw,
                offset: $offset,
                length: $length,
                durationSeconds: (int) round($hiVal * $hiSecondsPerUnit),
                durationLowSeconds: (int) round($loVal * $loSecondsPerUnit),
                label: $trimmed,
            );
        }

        // Same-unit range next.
        if (preg_match("/^({$numTok})\\s*(?:[-\x{2013}\x{2014}]|\\s+to\\s+)\\s*({$numTok})\\s*({$unit})\$/u", $trimmed, $m)) {
            $loVal = $this->parseNum($m[1]);
            $hiVal = $this->parseNum($m[2]);
            $unitName = strtolower($m[3]);
            $secondsPerUnit = self::UNIT_SECONDS[$unitName] ?? null;
            if ($secondsPerUnit === null || $loVal === null || $hiVal === null) {
                return null;
            }
            return new TimerMatch(
                raw: $raw,
                offset: $offset,
                length: $length,
                durationSeconds: (int) round($hiVal * $secondsPerUnit),
                durationLowSeconds: (int) round($loVal * $secondsPerUnit),
                label: $trimmed,
            );
        }

        // Compound: walk through the string consuming `<num><unit>` chunks.
        $pos = 0;
        $totalSeconds = 0.0;
        $hadAtLeastOne = false;
        while ($pos < strlen($trimmed)) {
            $rest = substr($trimmed, $pos);
            if (! preg_match("/^\\s*({$numTok})\\s*({$unit})/u", $rest, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $consumed = $m[0][1] + strlen($m[0][0]);
            $val = $this->parseNum($m[1][0]);
            $unitName = strtolower($m[2][0]);
            $secondsPerUnit = self::UNIT_SECONDS[$unitName] ?? null;
            if ($val === null || $secondsPerUnit === null) {
                break;
            }
            $totalSeconds += $val * $secondsPerUnit;
            $hadAtLeastOne = true;
            $pos += $consumed;
            // Skip whitespace before the next iteration; the regex above already
            // consumes leading whitespace via \s*, so this loop continues naturally.
        }
        if (! $hadAtLeastOne) {
            return null;
        }
        return new TimerMatch(
            raw: $raw,
            offset: $offset,
            length: $length,
            durationSeconds: (int) round($totalSeconds),
            durationLowSeconds: null,
            label: $trimmed,
        );
    }

    private function numTokenPattern(): string
    {
        $unicodeFracs = implode('', array_keys(self::UNICODE_FRACTIONS));
        // Slashes escaped so the pattern can be embedded inside a /.../-delimited regex.
        return implode('|', [
            '\d+\s+\d+\/\d+',                    // mixed ASCII "1 1/2"
            '\d+(?:\.\d+)?\s*['.$unicodeFracs.']', // unicode mixed "1½"
            '['.$unicodeFracs.']',              // bare unicode fraction "½"
            '\d+\/\d+',                         // bare ASCII fraction "1/2"
            '\d+(?:\.\d+)?',                    // integer or decimal "1.5"
        ]);
    }

    private function parseNum(string $tok): ?float
    {
        $tok = trim($tok);

        // Mixed ASCII: "1 1/2"
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/u', $tok, $m)) {
            return (float) $m[1] + ((float) $m[2] / max(1.0, (float) $m[3]));
        }
        // Unicode mixed: "1½"
        $unicodeFracs = array_keys(self::UNICODE_FRACTIONS);
        $unicodeClass = implode('', $unicodeFracs);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(['.$unicodeClass.'])$/u', $tok, $m)) {
            return (float) $m[1] + self::UNICODE_FRACTIONS[$m[2]];
        }
        // Bare unicode fraction
        if (mb_strlen($tok) === 1 && isset(self::UNICODE_FRACTIONS[$tok])) {
            return self::UNICODE_FRACTIONS[$tok];
        }
        // ASCII fraction
        if (preg_match('/^(\d+)\/(\d+)$/u', $tok, $m)) {
            return (float) $m[1] / max(1.0, (float) $m[2]);
        }
        // Plain
        if (is_numeric($tok)) {
            return (float) $tok;
        }
        return null;
    }
}
