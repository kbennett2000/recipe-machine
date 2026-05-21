<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

/**
 * Ingredient line parser.
 *
 * Conforms to docs/recipe-format.md v1.6.
 *
 * Pipeline order (per Phase 2A brief; the spec implies but does not codify this):
 *
 *   1. Strip leading bullet marker and trim whitespace. Save as `raw`.
 *   2. Detect and strip `Optional:` prefix and/or `(optional)` suffix.
 *      Set `optional` exactly once (idempotence — spec c).
 *   3. Split off note on the FIRST " — " (em-dash with surrounding spaces).
 *   4. Try imprecise quantity shapes (leading "X of …" and trailing
 *      "… [, ] X"). If matched, set unit to the canonical imprecise
 *      form with amount=null and stop.
 *   5. Try generic "amount [unit] ingredient[, modifier]" shape.
 *   6. Fallback: parsed=false, only `raw` is populated.
 *
 * Canonical-unit lookups (volume, weight, count nouns, single-word
 * imprecise tokens) are delegated to UnitMatcher.
 *
 * Spec resolutions inherited from Phase 2A (each cited in the v1.6 spec):
 *
 *   R1. Amount-bearing lines whose post-amount text has no canonical
 *       unit get unit=whole (NOT unit=null). This unifies the spec's
 *       "1 large onion" and "1 box pasta" examples under a single rule.
 *       The spec example for "1 box pasta" was reconciled to match in
 *       Phase 2A.1.
 *
 *   R2. Count-noun folding word order: "3 cloves garlic" folds to
 *       ingredient="garlic cloves" with unit=whole.
 *
 *   R3. Em-dash + comma interaction: split note off em-dash first,
 *       then split modifier off the first comma in the remainder.
 */
final class IngredientParser
{
    /**
     * Imprecise leading phrases. The trigger phrase identifies the canonical;
     * the rest of the line is the ingredient.
     *
     * Phase 2B.3: bare-form variants ("Pinch salt") are accepted in addition
     * to the explicit "of" forms ("Pinch of salt"). The leading-without-of
     * shape applies only to the six volume-imprecise canonicals (pinch,
     * dash, splash, drizzle, handful, sprinkle). The two trailing-only
     * canonicals (to-taste, as-needed) are NOT supported in leading
     * position — they don't make semantic sense there ("to taste salt"
     * is not natural English; writers say "salt to taste"). They live in
     * IMPRECISE_TRAILING only.
     *
     * Order matters: longer phrases must appear first so the longest-match
     * wins. "a pinch of" wins over "pinch of" wins over "a pinch" wins over
     * "pinch" for the input "a pinch of salt".
     *
     * @var array<string,string>
     */
    private const IMPRECISE_LEADING = [
        'a pinch of'    => 'pinch',
        'pinch of'      => 'pinch',
        'a pinch'       => 'pinch',
        'pinch'         => 'pinch',
        'a dash of'     => 'dash',
        'dash of'       => 'dash',
        'a dash'        => 'dash',
        'dash'          => 'dash',
        'a splash of'   => 'splash',
        'splash of'     => 'splash',
        'a splash'      => 'splash',
        'splash'        => 'splash',
        'a drizzle of'  => 'drizzle',
        'drizzle of'    => 'drizzle',
        'a drizzle'     => 'drizzle',
        'drizzle'       => 'drizzle',
        'a handful of'  => 'handful',
        'handful of'    => 'handful',
        'a handful'     => 'handful',
        'handful'       => 'handful',
        'a sprinkle of' => 'sprinkle',
        'sprinkle of'   => 'sprinkle',
        'a sprinkle'    => 'sprinkle',
        'sprinkle'      => 'sprinkle',
    ];

    /**
     * Imprecise trailing phrases. The trigger appears at the end of
     * the line, optionally preceded by a comma.
     *
     * @var array<string,string>
     */
    private const IMPRECISE_TRAILING = [
        'to taste'  => 'to-taste',
        'to-taste'  => 'to-taste',
        'as needed' => 'as-needed',
        'as-needed' => 'as-needed',
    ];

    private const UNICODE_FRACTIONS = [
        '¼' => 0.25,
        '½' => 0.5,
        '¾' => 0.75,
        '⅓' => 1.0 / 3.0,
        '⅔' => 2.0 / 3.0,
        '⅕' => 0.2,
        '⅖' => 0.4,
        '⅗' => 0.6,
        '⅘' => 0.8,
        '⅙' => 1.0 / 6.0,
        '⅚' => 5.0 / 6.0,
        '⅛' => 0.125,
        '⅜' => 0.375,
        '⅝' => 0.625,
        '⅞' => 0.875,
    ];

    public function __construct(
        private readonly UnitMatcher $unitMatcher = new UnitMatcher,
    ) {}

    public function parseLine(string $line, ?string $group = null): ParsedIngredient
    {
        // Step 1: strip bullet marker, trim.
        $raw = $this->stripBulletMarker($line);
        $raw = trim($raw);

        if ($raw === '') {
            return new ParsedIngredient(raw: '', parsed: false, group: $group);
        }

        $working = $raw;
        $optional = false;
        $note = null;

        // Step 2: optional markers (idempotent — both may appear).
        if (preg_match('/^optional\s*:\s*(.+)$/iu', $working, $m)) {
            $optional = true;
            $working = trim($m[1]);
        }
        if (preg_match('/^(.*?)\s*\(optional\)\s*$/iu', $working, $m)) {
            $optional = true;
            $working = trim($m[1]);
        }

        // Step 3: em-dash note split (em dash U+2014 with spaces on both sides).
        if (preg_match('/^(.*?)\s+—\s+(.+)$/u', $working, $m)) {
            $working = trim($m[1]);
            $note = trim($m[2]);
        }

        // Step 4: imprecise quantity shapes.
        $imprecise = $this->tryImprecise($working);
        if ($imprecise !== null) {
            return new ParsedIngredient(
                raw: $raw,
                parsed: true,
                amount: null,
                unit: $imprecise['unit'],
                ingredient: $imprecise['ingredient'],
                note: $note,
                optional: $optional,
                group: $group,
            );
        }

        // Step 5: generic shape "amount [unit] ingredient [, modifier]".
        $generic = $this->tryGeneric($working);
        if ($generic !== null) {
            return new ParsedIngredient(
                raw: $raw,
                parsed: true,
                amount: $generic['amount'],
                amountHigh: $generic['amount_high'],
                unit: $generic['unit'],
                ingredient: $generic['ingredient'],
                modifier: $generic['modifier'],
                note: $note,
                optional: $optional,
                group: $group,
            );
        }

        // Step 6: fallback.
        return new ParsedIngredient(
            raw: $raw,
            parsed: false,
            note: $note,
            optional: $optional,
            group: $group,
        );
    }

    private function stripBulletMarker(string $line): string
    {
        return (string) preg_replace('/^\s*(?:[-*+]|\d+[\.)])\s+/', '', $line);
    }

    /**
     * Try imprecise quantity shapes. Returns null when no match.
     *
     * @return array{unit:string, ingredient:string}|null
     */
    private function tryImprecise(string $text): ?array
    {
        $textLower = mb_strtolower($text);

        // Leading: "<trigger> <ingredient>"
        foreach (self::IMPRECISE_LEADING as $trigger => $canonical) {
            $triggerLen = strlen($trigger);
            if (str_starts_with($textLower, $trigger.' ')) {
                $remainder = trim(substr($text, $triggerLen + 1));
                if ($remainder !== '') {
                    return ['unit' => $canonical, 'ingredient' => $remainder];
                }
            }
        }

        // Trailing: "<ingredient>[,] <trigger>"
        foreach (self::IMPRECISE_TRAILING as $trigger => $canonical) {
            $pattern = '/^(.+?)(?:\s*,)?\s+'.preg_quote($trigger, '/').'\s*$/iu';
            if (preg_match($pattern, $text, $m)) {
                $ingredient = trim($m[1]);
                if ($ingredient !== '') {
                    return ['unit' => $canonical, 'ingredient' => $ingredient];
                }
            }
        }

        return null;
    }

    /**
     * Try "amount [unit] ingredient [, modifier]".
     *
     * @return array{amount:float|null, amount_high:float|null, unit:string|null, ingredient:string|null, modifier:string|null}|null
     */
    private function tryGeneric(string $text): ?array
    {
        $amountPattern = $this->amountPattern();
        $rangeSep = '(?:\s*[-–—]\s*|\s+to\s+)';
        $pattern = '/^('.$amountPattern.')(?:'.$rangeSep.'('.$amountPattern.'))?(?:\s+(.+))?$/u';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $amount = $this->parseAmountToken($m[1]);
        $amountHigh = isset($m[2]) && $m[2] !== '' ? $this->parseAmountToken($m[2]) : null;
        $rest = isset($m[3]) ? trim($m[3]) : '';

        if ($amount === null) {
            return null;
        }
        if ($rest === '') {
            return null;
        }

        // Split modifier off first (post-comma).
        $modifier = null;
        if (preg_match('/^(.+?),\s*(.+)$/u', $rest, $mm)) {
            $rest = trim($mm[1]);
            $modifier = trim($mm[2]);
        }

        // Ask UnitMatcher to identify the unit at the start of $rest.
        $matched = $this->unitMatcher->match($rest);
        if ($matched !== null) {
            $remainder = trim(substr($rest, strlen($matched->input)));
            if ($remainder === '') {
                // Got "1 cup" with no ingredient — not a valid line.
                return null;
            }
            if ($matched->class === UnitClass::COUNT) {
                // Count noun in first slot: fold to "<rest-after-count-noun> <count-noun>"
                // per spec ("3 cloves garlic" → "garlic cloves").
                $ingredient = $remainder.' '.$matched->input;
                $unit = 'whole';
            } else {
                $ingredient = $remainder;
                $unit = $matched->canonical;
            }
        } else {
            // No canonical unit — unit=whole per resolution R1.
            $unit = 'whole';
            $ingredient = $rest;
        }

        if ($ingredient === '') {
            return null;
        }

        return [
            'amount' => $amount,
            'amount_high' => $amountHigh,
            'unit' => $unit,
            'ingredient' => $ingredient,
            'modifier' => $modifier,
        ];
    }

    /**
     * Returns the amount-token regex fragment (no anchors, no capture group).
     * The caller wraps it in a capture group.
     */
    private function amountPattern(): string
    {
        $unicodeFracs = implode('', array_keys(self::UNICODE_FRACTIONS));
        // Slashes are escaped since the caller uses `/` as the regex delimiter.
        // Order matters — longer forms first so the regex doesn't bail early.
        return implode('|', [
            '\d+\s+\d+\/\d+',              // mixed: "1 1/2"
            '\d+\/\d+',                    // fraction: "1/2"
            '\d+\s*['.$unicodeFracs.']',   // unicode mixed: "1½" or "1 ½"
            '['.$unicodeFracs.']',         // unicode fraction alone: "½"
            '\d+(?:\.\d+)?',               // integer or decimal
        ]);
    }

    private function parseAmountToken(string $token): ?float
    {
        $token = trim($token);

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/u', $token, $m)) {
            $denom = (float) $m[3];
            return $denom === 0.0 ? null : (float) $m[1] + (float) $m[2] / $denom;
        }
        $unicodeFracs = array_keys(self::UNICODE_FRACTIONS);
        $unicodeClass = implode('', $unicodeFracs);
        if (preg_match('/^(\d+)\s*(['.$unicodeClass.'])$/u', $token, $m)) {
            return (float) $m[1] + self::UNICODE_FRACTIONS[$m[2]];
        }
        if (mb_strlen($token) === 1 && isset(self::UNICODE_FRACTIONS[$token])) {
            return self::UNICODE_FRACTIONS[$token];
        }
        if (preg_match('/^(\d+)\/(\d+)$/u', $token, $m)) {
            $denom = (float) $m[2];
            return $denom === 0.0 ? null : (float) $m[1] / $denom;
        }
        if (is_numeric($token)) {
            return (float) $token;
        }
        return null;
    }
}
