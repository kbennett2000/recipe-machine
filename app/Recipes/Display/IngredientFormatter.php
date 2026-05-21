<?php

declare(strict_types=1);

namespace App\Recipes\Display;

use App\Models\Ingredient;

/**
 * Converts a structured Ingredient row into a natural-prose display string.
 *
 * The renderer reads the parsed structured fields (amount, unit, ingredient,
 * modifier) — NOT the raw line — so the displayed text reflects the parser's
 * understanding of the line. This is intentional per the Phase 3 brief: it
 * proves the parser works end-to-end.
 *
 * Special cases:
 *   - Unparsed lines fall back to the raw text (caller renders italicized).
 *   - Imprecise leading units (pinch/dash/etc.) render as "a pinch of <X>".
 *   - Imprecise trailing units (to-taste/as-needed) render as "<X> to taste".
 *   - The `whole` unit is not shown — "3 eggs", not "3 whole eggs".
 *   - Amounts that match common fractions display as fractions (1/2, not 0.5).
 *   - Volume/weight units pluralize for amounts > 1 where it reads naturally
 *     (cup → cups; tsp, g, ml don't pluralize).
 */
final class IngredientFormatter
{
    /**
     * Canonical → display map. Keys are the parser's canonical forms.
     * Values are arrays [singular, plural]; pluralize when amount > 1.
     */
    private const UNIT_DISPLAY = [
        'tsp'    => ['tsp', 'tsp'],
        'tbsp'   => ['Tbsp', 'Tbsp'],
        'cup'    => ['cup', 'cups'],
        'floz'   => ['fl oz', 'fl oz'],
        'pint'   => ['pint', 'pints'],
        'quart'  => ['quart', 'quarts'],
        'gallon' => ['gallon', 'gallons'],
        'ml'     => ['ml', 'ml'],
        'l'      => ['L', 'L'],
        'g'      => ['g', 'g'],
        'kg'     => ['kg', 'kg'],
        'oz'     => ['oz', 'oz'],
        'lb'     => ['lb', 'lb'],
    ];

    /** Canonical → nice phrase for trailing imprecise. */
    private const IMPRECISE_TRAILING_PHRASE = [
        'to-taste' => 'to taste',
        'as-needed' => 'as needed',
    ];

    public function format(Ingredient $i): string
    {
        if (! $i->parsed) {
            return $i->raw;
        }

        $optional = $i->optional ? ' (optional)' : '';

        // Imprecise trailing: "salt to taste" / "olive oil, as needed".
        if (isset(self::IMPRECISE_TRAILING_PHRASE[$i->unit])) {
            $phrase = self::IMPRECISE_TRAILING_PHRASE[$i->unit];
            $ingredient = $i->ingredient ?? '';
            $sep = $i->unit === 'as-needed' ? ', ' : ' ';
            return trim("{$ingredient}{$sep}{$phrase}").$optional;
        }

        // Imprecise leading: "a pinch of salt".
        if ($i->unit_class === 'imprecise') {
            return "a {$i->unit} of {$i->ingredient}".$optional;
        }

        // Generic: <amount> <unit> <ingredient>[, <modifier>]
        $parts = [];

        if ($i->amount !== null) {
            $parts[] = $this->formatAmount($i->amount, $i->amount_high);
        }

        if ($i->unit !== null && $i->unit !== 'whole') {
            $isPlural = $this->amountIsPlural($i->amount, $i->amount_high);
            [$singular, $plural] = self::UNIT_DISPLAY[$i->unit] ?? [$i->unit, $i->unit];
            $parts[] = $isPlural ? $plural : $singular;
        }

        if ($i->ingredient !== null) {
            $parts[] = $i->ingredient;
        }

        $line = trim(implode(' ', $parts));
        if ($i->modifier !== null && $i->modifier !== '') {
            $line .= ', '.$i->modifier;
        }

        return $line.$optional;
    }

    /**
     * Display amount as fraction where natural.
     *  - 0.5      → "1/2"
     *  - 1.5      → "1 1/2"
     *  - 0.25     → "1/4"
     *  - 0.333... → "1/3"
     *  - 2        → "2"
     *  - "1-2"    → "1-2"   (range)
     */
    public function formatAmount(float $amount, ?float $amountHigh = null): string
    {
        $lo = $this->formatSingleAmount($amount);
        if ($amountHigh !== null) {
            $hi = $this->formatSingleAmount($amountHigh);
            return "{$lo}–{$hi}";
        }
        return $lo;
    }

    private function formatSingleAmount(float $n): string
    {
        if ($n === floor($n) && $n < 100) {
            return (string) (int) $n;
        }

        $whole = (int) floor($n);
        $frac = $n - $whole;

        // Common fractions: try to snap to a clean string.
        // PHP truncates numeric (float) array keys to ints, so we use a list of
        // [target, label] pairs instead of a [target => label] map.
        $fractions = [
            [0.125, '1/8'],
            [0.25,  '1/4'],
            [0.333, '1/3'],
            [0.375, '3/8'],
            [0.5,   '1/2'],
            [0.625, '5/8'],
            [0.667, '2/3'],
            [0.75,  '3/4'],
            [0.875, '7/8'],
        ];
        foreach ($fractions as [$target, $label]) {
            if (abs($frac - $target) < 0.01) {
                return $whole > 0 ? "{$whole} {$label}" : $label;
            }
        }
        // Fall back to decimal.
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function amountIsPlural(?float $amount, ?float $amountHigh): bool
    {
        $ref = $amountHigh ?? $amount;
        return $ref !== null && $ref > 1.0;
    }
}
