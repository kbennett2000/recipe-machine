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
 *   1. Strip leading bullet marker (`- `, `* `, or `\d+\. `) and trim whitespace.
 *      The trimmed text becomes `raw`.
 *   2. Detect and strip `Optional:` prefix and/or `(optional)` suffix.
 *      Set `optional` exactly once; both markers may coexist (idempotence — spec c).
 *   3. Split off note on the FIRST ` — ` (em-dash with surrounding spaces).
 *      Everything after the split → `note` (spec section c, "Inline comments").
 *   4. Try imprecise quantity shapes (leading `<imprecise> of …` and trailing
 *      `… [, ] <imprecise>`). If matched, set unit to the canonical imprecise
 *      form with `amount = null` (spec c, "Imprecise quantity line shapes"),
 *      and stop.
 *   5. Try generic `<amount> <unit> <ingredient>[, <modifier>]` shape.
 *      Amount accepts integers, decimals, fractions, mixed fractions,
 *      unicode fractions, and ranges with `-`, `–`, `—`, or ` to `.
 *   6. Fallback: `parsed = false`, only `raw` is populated.
 *
 * Spec resolutions (ambiguities I had to fix to ship):
 *
 *   R1. "Unknown unit-like tokens" vs "unitless counted items"
 *       Spec example "1 box pasta" → unit=null (Section c, "Unknown unit-like
 *       tokens") contradicts spec example "1 large onion" → unit=whole
 *       (Section c, "Whole / countable items"). I resolved by promoting
 *       "1 large onion"'s rule: any amount-bearing line whose post-amount
 *       text doesn't begin with a canonical unit token gets unit=whole,
 *       ingredient=<everything-after-amount>. This means "1 box pasta"
 *       parses to {amount:1, unit:whole, ingredient:"box pasta"}, which
 *       deviates from the spec example. Flagged in the Phase 2A report.
 *
 *   R2. Count-noun folding word order
 *       Spec says `3 cloves garlic` → ingredient="garlic cloves". For
 *       `1 stick butter` the spec count-noun list includes `sticks`, so
 *       same fold applies: ingredient="butter sticks". This contradicts
 *       the Phase 2A brief, which listed "1 stick butter" as
 *       should-fail. I followed the spec; flagged in the report.
 *
 *   R3. Em-dash + comma interaction
 *       Phase 2A brief: "treating em-dash-comma as modifier, post-comma
 *       plain text as modifier, em-dash-prose as a note field instead".
 *       I interpret: split off the em-dash note first, then split the
 *       remaining text on the first comma into ingredient + modifier.
 *       Modifier and note can coexist on one line.
 *
 *   R4. Count-noun in trailing position (preferred form per spec)
 *       Spec says `3 garlic cloves` → unit=whole, ingredient="garlic cloves".
 *       This requires looking at the LAST word of the post-amount text.
 *       I implemented: if the post-amount text ends in a count noun (and
 *       its first token is not itself a canonical unit), unit=whole.
 */
final class IngredientParser
{
    /**
     * Count nouns per spec Section c, "Whole / countable items".
     * Both singular and plural forms accepted in input.
     * Output ingredient preserves writer's pluralization.
     */
    private const COUNT_NOUNS = [
        'clove', 'cloves',
        'slice', 'slices',
        'sprig', 'sprigs',
        'head', 'heads',
        'bunch', 'bunches',
        'can', 'cans',
        'jar', 'jars',
        'stick', 'sticks',
    ];

    /**
     * Imprecise canonicals per spec v1.6 (closed list of 8).
     * Map: lower-cased trigger phrase → canonical unit.
     * Order matters for the trailing-pattern matcher — longer phrases
     * must be tried before shorter ones.
     */
    private const IMPRECISE_LEADING = [
        'a pinch of'   => 'pinch',
        'pinch of'     => 'pinch',
        'a dash of'    => 'dash',
        'dash of'      => 'dash',
        'a splash of'  => 'splash',
        'splash of'    => 'splash',
        'a drizzle of' => 'drizzle',
        'drizzle of'   => 'drizzle',
        'a handful of' => 'handful',
        'handful of'   => 'handful',
        'a sprinkle of'=> 'sprinkle',
        'sprinkle of'  => 'sprinkle',
    ];

    private const IMPRECISE_TRAILING = [
        'to taste'  => 'to-taste',
        'to-taste'  => 'to-taste',
        'as needed' => 'as-needed',
        'as-needed' => 'as-needed',
    ];

    /**
     * Canonical unit lookup. Multi-word entries (e.g. `fl oz`) must be
     * matched before single-word ones. The keys here are lower-case
     * unless case is semantically meaningful (e.g. `T` vs `t`).
     *
     * Listed longest-first within each canonical to give greedy matching
     * a chance to find the right one.
     */
    private const UNIT_MAP = [
        // Volume (multi-word first)
        'fluid ounces' => 'floz',
        'fluid ounce'  => 'floz',
        'fl. oz.'      => 'floz',
        'fl oz.'       => 'floz',
        'fl. oz'       => 'floz',
        'fl oz'        => 'floz',
        'floz'         => 'floz',
        'tablespoons'  => 'tbsp',
        'tablespoon'   => 'tbsp',
        'teaspoons'    => 'tsp',
        'teaspoon'     => 'tsp',
        'milliliters'  => 'ml',
        'millilitres'  => 'ml',
        'milliliter'   => 'ml',
        'millilitre'   => 'ml',
        'liters'       => 'l',
        'litres'       => 'l',
        'liter'        => 'l',
        'litre'        => 'l',
        'tbsp'         => 'tbsp',
        'tbsps'        => 'tbsp',
        'tbs'          => 'tbsp',
        'tsp'          => 'tsp',
        'tsps'         => 'tsp',
        'cups'         => 'cup',
        'cup'          => 'cup',
        'pints'        => 'pint',
        'pint'         => 'pint',
        'pts'          => 'pint',
        'pt'           => 'pint',
        'quarts'       => 'quart',
        'quart'        => 'quart',
        'qts'          => 'quart',
        'qt'           => 'quart',
        'gallons'      => 'gallon',
        'gallon'       => 'gallon',
        'gal'          => 'gallon',
        'ml'           => 'ml',

        // Weight
        'kilograms'    => 'kg',
        'kilogram'     => 'kg',
        'kilos'        => 'kg',
        'kilo'         => 'kg',
        'grams'        => 'g',
        'gram'         => 'g',
        'pounds'       => 'lb',
        'pound'        => 'lb',
        'ounces'       => 'oz',
        'ounce'        => 'oz',
        'kg'           => 'kg',
        'gms'          => 'g',
        'lbs'          => 'lb',
        'lb'           => 'lb',
        'gm'           => 'g',
        'g'            => 'g',
        'oz'           => 'oz',
    ];

    /**
     * Case-sensitive tokens — these are handled separately from the
     * case-insensitive UNIT_MAP above.
     *   - `T` (capital, standalone) → tbsp
     *   - `t` (lower, standalone)  → tsp
     *   - `L` (capital, standalone) → l
     *   - `mL` (mixed)             → ml
     *   - `c` (lower, standalone)  → cup (discouraged)
     *   - `#` (standalone)         → lb
     */
    private const CASE_SENSITIVE_UNITS = [
        'T'  => 'tbsp',
        't'  => 'tsp',
        'L'  => 'l',
        'mL' => 'ml',
        'c'  => 'cup',
        '#'  => 'lb',
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

        // Step 3: em-dash note split.
        // Spec: ` — ` (em dash U+2014 with whitespace on both sides) delimits a note.
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

        // Step 5: generic shape "amount [unit] ingredient [, modifier]"
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
        // Markdown bullets: `- `, `* `, `+ `, or numbered `1. ` / `1) `.
        return (string) preg_replace('/^\s*(?:[-*+]|\d+[\.)])\s+/', '', $line);
    }

    /**
     * Try imprecise quantity shapes. Returns null when no match.
     * Match order: leading `<imprecise> of …` first (longer phrases first),
     * then trailing `…, <imprecise>` and `… <imprecise>` (longer first).
     *
     * @return array{unit:string, ingredient:string}|null
     */
    private function tryImprecise(string $text): ?array
    {
        $textLower = mb_strtolower($text);

        // Leading patterns: "<trigger> <ingredient>"
        foreach (self::IMPRECISE_LEADING as $trigger => $canonical) {
            $triggerLen = strlen($trigger);
            if (str_starts_with($textLower, $trigger.' ')) {
                $remainder = trim(substr($text, $triggerLen + 1));
                if ($remainder !== '') {
                    return ['unit' => $canonical, 'ingredient' => $remainder];
                }
            }
        }

        // Trailing patterns: "<ingredient>[,] <trigger>"
        // Try longer triggers first (they're already ordered in the constant).
        foreach (self::IMPRECISE_TRAILING as $trigger => $canonical) {
            // Accept optional comma+space or just space before trigger.
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
     * Try `<amount> <unit?> <ingredient>[, <modifier>]`.
     *
     * @return array{amount:float|string|null, amount_high:float|null, unit:string|null, ingredient:string|null, modifier:string|null}|null
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
            // "3" with nothing else — no ingredient, can't parse meaningfully.
            return null;
        }

        // Split modifier off first (post-comma).
        $modifier = null;
        if (preg_match('/^(.+?),\s*(.+)$/u', $rest, $mm)) {
            $beforeComma = trim($mm[1]);
            $afterComma = trim($mm[2]);
            $rest = $beforeComma;
            $modifier = $afterComma;
        }

        // Try to match a canonical unit at the start of $rest (greedy, longest first).
        $unit = null;
        $ingredient = $rest;

        $matched = $this->matchUnit($rest);
        if ($matched !== null) {
            $unit = $matched['canonical'];
            $ingredient = $matched['remainder'];
        } else {
            // No canonical unit. Per spec resolution R1 + R4 — use unit=whole
            // for any amount-bearing line with no canonical unit token, and
            // fold count nouns into the ingredient name regardless of
            // input word order.
            $unit = 'whole';
            $ingredient = $this->foldCountNouns($rest);
        }

        if ($ingredient === '') {
            // Got an amount + unit but nothing else — that's not a valid ingredient line.
            return null;
        }

        return [
            'amount' => $amountHigh !== null ? $amount : $amount,
            'amount_high' => $amountHigh,
            'unit' => $unit,
            'ingredient' => $ingredient,
            'modifier' => $modifier,
        ];
    }

    /**
     * Returns the amount-token regex fragment (no anchors, no capture-group around it).
     * The caller wraps it in a capture group.
     */
    private function amountPattern(): string
    {
        $unicodeFracs = implode('', array_keys(self::UNICODE_FRACTIONS));
        // Order matters — longest forms first so the regex doesn't bail early.
        // Slashes are escaped since the caller uses `/` as the regex delimiter.
        return implode('|', [
            '\d+\s+\d+\/\d+',              // mixed: "1 1/2"
            '\d+\/\d+',                    // fraction: "1/2"
            '\d+\s*['.$unicodeFracs.']',   // unicode mixed: "1½" or "1 ½"
            '['.$unicodeFracs.']',         // unicode fraction alone: "½"
            '\d+(?:\.\d+)?',               // integer or decimal
        ]);
    }

    /**
     * Parse a single amount token (already matched by amountPattern).
     */
    private function parseAmountToken(string $token): ?float
    {
        $token = trim($token);

        // Mixed: "1 1/2"
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/u', $token, $m)) {
            $denom = (float) $m[3];
            return $denom === 0.0 ? null : (float) $m[1] + (float) $m[2] / $denom;
        }
        // Unicode mixed: "1½" or "1 ½"
        $unicodeFracs = array_keys(self::UNICODE_FRACTIONS);
        $unicodeClass = implode('', $unicodeFracs);
        if (preg_match('/^(\d+)\s*(['.$unicodeClass.'])$/u', $token, $m)) {
            return (float) $m[1] + self::UNICODE_FRACTIONS[$m[2]];
        }
        // Unicode fraction alone
        if (mb_strlen($token) === 1 && isset(self::UNICODE_FRACTIONS[$token])) {
            return self::UNICODE_FRACTIONS[$token];
        }
        // ASCII fraction: "1/2"
        if (preg_match('/^(\d+)\/(\d+)$/u', $token, $m)) {
            $denom = (float) $m[2];
            return $denom === 0.0 ? null : (float) $m[1] / $denom;
        }
        // Decimal or integer
        if (is_numeric($token)) {
            return (float) $token;
        }
        return null;
    }

    /**
     * Try to match a canonical unit at the start of $text.
     *
     * @return array{canonical:string, remainder:string}|null
     */
    private function matchUnit(string $text): ?array
    {
        // 1) Case-sensitive single-letter shortcuts, only when standalone.
        foreach (self::CASE_SENSITIVE_UNITS as $token => $canonical) {
            $len = strlen($token);
            if (substr($text, 0, $len) === $token) {
                // Standalone check: next char must be whitespace or end-of-string.
                $next = substr($text, $len, 1);
                if ($next === '' || ctype_space($next)) {
                    $remainder = trim(substr($text, $len));
                    return ['canonical' => $canonical, 'remainder' => $remainder];
                }
            }
        }

        // 2) Case-insensitive map (already ordered longest-first).
        $textLower = mb_strtolower($text);
        foreach (self::UNIT_MAP as $spelling => $canonical) {
            $len = strlen($spelling);
            if (substr($textLower, 0, $len) === $spelling) {
                // Boundary: next char must be whitespace or end-of-string.
                $next = substr($text, $len, 1);
                if ($next === '' || ctype_space($next)) {
                    $remainder = trim(substr($text, $len));
                    // Special-case oz disambiguation: "fl oz" / "fluid oz" was
                    // already matched as floz before we got here. Plain "oz"
                    // defaults to weight per spec.
                    return ['canonical' => $canonical, 'remainder' => $remainder];
                }
            }
        }

        return null;
    }

    /**
     * Fold count nouns into the ingredient name per spec Section c.
     *
     *   "cloves garlic"      → "garlic cloves"   (count noun in first slot)
     *   "garlic cloves"      → "garlic cloves"   (already canonical)
     *   "large onion"        → "large onion"     (no count noun, unchanged)
     *   "box pasta"          → "box pasta"       (`box` is not a count noun)
     */
    private function foldCountNouns(string $text): string
    {
        $tokens = preg_split('/\s+/', trim($text));
        if (! is_array($tokens) || count($tokens) < 2) {
            return $text;
        }

        $firstLower = mb_strtolower($tokens[0]);
        if (in_array($firstLower, self::COUNT_NOUNS, true)) {
            // Move first token to the end: "cloves garlic" → "garlic cloves"
            $first = array_shift($tokens);
            $tokens[] = $first;
            return implode(' ', $tokens);
        }

        return $text;
    }
}
