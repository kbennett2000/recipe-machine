<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

/**
 * Recognizes canonical units at the start of a string.
 *
 * The single public entry point is {@see match()}. Pass the post-amount
 * text (or any token-like string) and the matcher returns a MatchedUnit
 * describing the longest valid unit-token prefix, or null if none of the
 * canonical spellings match.
 *
 * Matching rules:
 *   - Case-insensitive. The single-letter shortcuts `T`/`t`/`c`/`#` are
 *     intentionally NOT supported in v1; writers should spell out
 *     `tbsp`, `tsp`, and `cup`.
 *   - Multi-word entries (e.g. `fl oz`, `fluid ounce`) are tried before
 *     their single-word equivalents.
 *   - The matched spelling must be followed by whitespace or end-of-string;
 *     this prevents `cup` from matching the start of `cupcake`.
 *   - For COUNT class entries (cloves, slices, sprigs, ...), the canonical
 *     form is `whole` — the spec's single bucket for unitless counted
 *     items. The `input` field preserves which count noun was used.
 */
final class UnitMatcher
{
    /** @var array<string,string> lower-cased spelling → canonical */
    private const VOLUME = [
        // Multi-word spellings first.
        'fluid ounces' => 'floz',
        'fluid ounce'  => 'floz',
        'fl. oz.'      => 'floz',
        'fl oz.'       => 'floz',
        'fl. oz'       => 'floz',
        'fl oz'        => 'floz',
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
        'gallons'      => 'gallon',
        'gallon'       => 'gallon',
        'quarts'       => 'quart',
        'quart'        => 'quart',
        'pints'        => 'pint',
        'pint'         => 'pint',
        'floz'         => 'floz',
        'tbsp'         => 'tbsp',
        'tbsps'        => 'tbsp',
        'tbs'          => 'tbsp',
        'tsp'          => 'tsp',
        'tsps'         => 'tsp',
        'cups'         => 'cup',
        'cup'          => 'cup',
        'qts'          => 'quart',
        'qt'           => 'quart',
        'pts'          => 'pint',
        'pt'           => 'pint',
        'gal'          => 'gallon',
        'ml'           => 'ml',
        'l'            => 'l',
    ];

    /** @var array<string,string> lower-cased spelling → canonical */
    private const WEIGHT = [
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
     * Count nouns per spec Section c, "Whole / countable items".
     * All map to the single canonical `whole`; the writer's chosen spelling
     * survives on MatchedUnit::$input for downstream display.
     *
     * @var array<string,string>
     */
    private const COUNT = [
        'cloves'   => 'whole',
        'clove'    => 'whole',
        'slices'   => 'whole',
        'slice'    => 'whole',
        'sprigs'   => 'whole',
        'sprig'    => 'whole',
        'heads'    => 'whole',
        'head'     => 'whole',
        'bunches'  => 'whole',
        'bunch'    => 'whole',
        'cans'     => 'whole',
        'can'      => 'whole',
        'jars'     => 'whole',
        'jar'      => 'whole',
        'sticks'   => 'whole',
        'stick'    => 'whole',
    ];

    /**
     * Standalone imprecise tokens (closed list per spec v1.6, Section c).
     * The phrase-level patterns ("a pinch of X", "X to taste") live in
     * IngredientParser — those are sentence shapes, not unit tokens.
     *
     * @var array<string,string>
     */
    private const IMPRECISE = [
        'to taste'  => 'to-taste',
        'to-taste'  => 'to-taste',
        'as needed' => 'as-needed',
        'as-needed' => 'as-needed',
        'pinches'   => 'pinch',
        'pinch'     => 'pinch',
        'dashes'    => 'dash',
        'dash'      => 'dash',
        'splashes'  => 'splash',
        'splash'    => 'splash',
        'drizzles'  => 'drizzle',
        'drizzle'   => 'drizzle',
        'handfuls'  => 'handful',
        'handful'   => 'handful',
        'sprinkles' => 'sprinkle',
        'sprinkle'  => 'sprinkle',
    ];

    /**
     * Returns the longest unit-token match at the START of $token, or null.
     */
    public function match(string $token): ?MatchedUnit
    {
        $token = ltrim($token);
        if ($token === '') {
            return null;
        }

        // Order: VOLUME, WEIGHT, IMPRECISE (with multi-word entries), COUNT.
        // Inside each map, the array order is already longest-first.
        $maps = [
            [self::VOLUME,    UnitClass::VOLUME],
            [self::WEIGHT,    UnitClass::WEIGHT],
            [self::IMPRECISE, UnitClass::IMPRECISE],
            [self::COUNT,     UnitClass::COUNT],
        ];

        $lower = mb_strtolower($token);

        $best = null;
        foreach ($maps as [$map, $class]) {
            foreach ($map as $spelling => $canonical) {
                $len = strlen($spelling);
                if (substr($lower, 0, $len) !== $spelling) {
                    continue;
                }
                // Boundary: next char must be whitespace or end-of-string.
                $next = substr($token, $len, 1);
                if ($next !== '' && ! ctype_space($next)) {
                    continue;
                }
                $candidate = new MatchedUnit(
                    canonical: $canonical,
                    class: $class,
                    input: substr($token, 0, $len),
                );
                if ($best === null || strlen($candidate->input) > strlen($best->input)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }
}
