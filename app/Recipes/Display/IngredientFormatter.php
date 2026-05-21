<?php

declare(strict_types=1);

namespace App\Recipes\Display;

use App\Models\Ingredient;

/**
 * Converts a structured Ingredient row into a natural-prose display string.
 *
 * Special cases:
 *   - Unparsed lines fall back to the raw text (caller renders italicized).
 *   - Imprecise leading units (pinch/dash/etc.) render as "a pinch of <X>".
 *   - Imprecise trailing units (to-taste/as-needed) render as "<X> to taste".
 *   - The `whole` unit is not shown — "3 eggs", not "3 whole eggs".
 *   - Amounts that match common fractions display as fractions (1/2, not 0.5).
 *   - Volume/weight units pluralize for amounts > 1 where it reads naturally
 *     (cup → cups; tsp, g, ml don't pluralize).
 *
 * Phase 5: scaling can produce fractional counts of countable items
 * ("3 eggs × 1.5 = 4.5 eggs"). The spec's "Scaling math" subsection
 * dictates: `unit=whole` with a fractional amount renders with a leading
 * `~` prefix and rounds to the nearest 0.5 increment, so the user is
 * reminded that the math doesn't perfectly fit.
 *
 * The JS twin at `resources/js/ingredient-format.js` MUST stay in sync —
 * the parity test asserts byte-identical output for both.
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

    /**
     * Common fractions for amount-snapping. List of [target, label] because
     * PHP truncates numeric float keys to ints.
     */
    private const FRACTIONS = [
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

    public function format(Ingredient $i): string
    {
        if (! $i->parsed) {
            return $i->raw;
        }

        return $this->formatFields([
            'amount'      => $i->amount,
            'amount_high' => $i->amount_high,
            'unit'        => $i->unit,
            'unit_class'  => $i->unit_class,
            'ingredient'  => $i->ingredient,
            'modifier'    => $i->modifier,
            'optional'    => (bool) $i->optional,
        ]);
    }

    /**
     * Format directly from an associative array of fields. The parity test
     * uses this to bypass the Ingredient model and feed identical inputs to
     * the PHP and JS formatters.
     *
     * @param  array{amount: float|null, amount_high?: float|null, unit?: string|null, unit_class?: string|null, ingredient?: string|null, modifier?: string|null, optional?: bool}  $fields
     */
    public function formatFields(array $fields): string
    {
        $amount = $fields['amount'] ?? null;
        $amountHigh = $fields['amount_high'] ?? null;
        $unit = $fields['unit'] ?? null;
        $unitClass = $fields['unit_class'] ?? null;
        $ingredient = $fields['ingredient'] ?? null;
        $modifier = $fields['modifier'] ?? null;
        $optional = ! empty($fields['optional']);

        $optionalTag = $optional ? ' (optional)' : '';

        // Imprecise trailing: "salt to taste" / "olive oil, as needed".
        if ($unit !== null && isset(self::IMPRECISE_TRAILING_PHRASE[$unit])) {
            $phrase = self::IMPRECISE_TRAILING_PHRASE[$unit];
            $sep = $unit === 'as-needed' ? ', ' : ' ';
            return trim(((string) $ingredient).$sep.$phrase).$optionalTag;
        }

        // Imprecise leading: "a pinch of salt".
        if ($unitClass === 'imprecise') {
            return 'a '.$unit.' of '.$ingredient.$optionalTag;
        }

        // Phase 9.2: amount-high-only renders with an "up to" prefix.
        // "Up to 1/4 cup oil" reads naturally; rendering just "cup oil"
        // (the pre-fix behavior) lost the quantity entirely. The LLM
        // fallback produces this shape for "up to N" phrasing; the rules-
        // based parser doesn't, but the formatter handles it for both.
        $upToPrefix = '';
        if ($amount === null && $amountHigh !== null) {
            $upToPrefix = 'up to ';
            $amount = $amountHigh;
            $amountHigh = null;
        }

        // Generic: <amount> <unit> <ingredient>[, <modifier>]
        $parts = [];

        if ($amount !== null) {
            $parts[] = $this->formatAmount((float) $amount, $amountHigh, $unit);
        }

        if ($unit !== null && $unit !== 'whole') {
            $isPlural = $this->amountIsPlural($amount, $amountHigh);
            [$singular, $plural] = self::UNIT_DISPLAY[$unit] ?? [$unit, $unit];
            $parts[] = $isPlural ? $plural : $singular;
        }

        if ($ingredient !== null && $ingredient !== '') {
            $parts[] = $ingredient;
        }

        $line = trim($upToPrefix.implode(' ', $parts));
        if ($modifier !== null && $modifier !== '') {
            $line .= ', '.$modifier;
        }

        return $line.$optionalTag;
    }

    /**
     * Display amount as fraction where natural.
     *
     * Per the spec's "Scaling math" subsection, whole-unit amounts with a
     * non-integer value render with a `~` prefix and round to nearest 0.5.
     */
    public function formatAmount(float $amount, ?float $amountHigh = null, ?string $unit = null): string
    {
        if ($unit === 'whole' && $this->isNonIntegerCount($amount, $amountHigh)) {
            $lo = $this->roundToHalf($amount);
            if ($amountHigh !== null) {
                $hi = $this->roundToHalf($amountHigh);
                return '~'.$this->formatHalfStep($lo).'–'.$this->formatHalfStep($hi);
            }
            return '~'.$this->formatHalfStep($lo);
        }

        $lo = $this->formatSingleAmount($amount);
        if ($amountHigh !== null) {
            $hi = $this->formatSingleAmount($amountHigh);
            return $lo.'–'.$hi;
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

        foreach (self::FRACTIONS as [$target, $label]) {
            if (abs($frac - $target) < 0.01) {
                return $whole > 0 ? $whole.' '.$label : $label;
            }
        }
        // Fall back to decimal.
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * Render a value that is already rounded to a 0.5 increment.
     * Integers render as "5"; halves render as "4.5".
     */
    private function formatHalfStep(float $n): string
    {
        if ($n === floor($n)) {
            return (string) (int) $n;
        }
        return number_format($n, 1, '.', '');
    }

    private function isNonIntegerCount(float $a, ?float $b): bool
    {
        if ($a !== floor($a)) {
            return true;
        }
        if ($b !== null && $b !== floor($b)) {
            return true;
        }
        return false;
    }

    private function roundToHalf(float $n): float
    {
        return round($n * 2) / 2;
    }

    private function amountIsPlural(?float $amount, ?float $amountHigh): bool
    {
        $ref = $amountHigh ?? $amount;
        return $ref !== null && $ref > 1.0;
    }
}
