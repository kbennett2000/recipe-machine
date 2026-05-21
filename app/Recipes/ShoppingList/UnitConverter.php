<?php

declare(strict_types=1);

namespace App\Recipes\ShoppingList;

/**
 * Converts measurements within a unit class so they can be summed.
 *
 * Strategy:
 *   1. Convert every value to the class's base unit (ml for volume, g for
 *      weight). Sum. Pick the most natural output unit for the total.
 *   2. The thresholds for the output unit follow the Phase 6 brief:
 *        Volume: cups if total >=200ml, tbsp if >=15ml, tsp otherwise.
 *        Weight: kg if total >=1000g, lb if total >=453g, g otherwise.
 *
 * The conversion factors use the rounded values that home cooks expect
 * (1 cup = 240ml, not 236.588ml) so that "1 cup + 2 Tbsp" comes out as
 * a clean 270ml = 1.125 cups rather than 266.16ml = 1.10ish cups.
 */
final class UnitConverter
{
    /**
     * Canonical volume unit → milliliters. Values are the "kitchen-rounded"
     * conversions, matching the conventions used in US recipe writing.
     */
    private const VOLUME_TO_ML = [
        'tsp'    => 5.0,
        'tbsp'   => 15.0,
        'cup'    => 240.0,
        'floz'   => 30.0,
        'pint'   => 480.0,
        'quart'  => 960.0,
        'gallon' => 3840.0,
        'ml'     => 1.0,
        'l'      => 1000.0,
    ];

    /** Canonical weight unit → grams. */
    private const WEIGHT_TO_G = [
        'g'  => 1.0,
        'kg' => 1000.0,
        'oz' => 28.0,
        'lb' => 454.0,
    ];

    /**
     * @return array{amount: float, unit: string}  The total amount in the
     *   chosen output unit. Returns the input unchanged if conversion
     *   isn't possible (e.g. unknown unit, or imprecise unit).
     */
    public function combineSameClass(array $quantities, string $unitClass): array
    {
        if ($quantities === []) {
            return ['amount' => 0.0, 'unit' => ''];
        }

        if ($unitClass === 'volume') {
            return $this->combineVolume($quantities);
        }
        if ($unitClass === 'weight') {
            return $this->combineWeight($quantities);
        }
        if ($unitClass === 'count') {
            // unit=whole everywhere; just sum amounts.
            $total = 0.0;
            foreach ($quantities as $q) {
                $total += (float) $q['amount'];
            }
            return ['amount' => $total, 'unit' => 'whole'];
        }
        // null / imprecise / unknown → fall through to "first wins"
        $first = $quantities[0];
        $total = 0.0;
        foreach ($quantities as $q) {
            if (($q['unit'] ?? null) === ($first['unit'] ?? null)) {
                $total += (float) $q['amount'];
            }
        }
        return ['amount' => $total, 'unit' => (string) ($first['unit'] ?? '')];
    }

    /**
     * @param  array<array{amount: float, unit: string}>  $quantities
     * @return array{amount: float, unit: string}
     */
    private function combineVolume(array $quantities): array
    {
        $totalMl = 0.0;
        foreach ($quantities as $q) {
            $unit = $q['unit'] ?? '';
            $factor = self::VOLUME_TO_ML[$unit] ?? null;
            if ($factor === null) {
                // Unknown volume unit — fall back to summing in-kind.
                return $this->fallbackSum($quantities);
            }
            $totalMl += ((float) $q['amount']) * $factor;
        }
        // Pick the natural output unit per brief thresholds.
        if ($totalMl >= self::VOLUME_TO_ML['cup'] - 1.0) {     // -1 epsilon for 1-cup boundary
            return ['amount' => $totalMl / self::VOLUME_TO_ML['cup'], 'unit' => 'cup'];
        }
        if ($totalMl >= self::VOLUME_TO_ML['tbsp']) {
            return ['amount' => $totalMl / self::VOLUME_TO_ML['tbsp'], 'unit' => 'tbsp'];
        }
        return ['amount' => $totalMl / self::VOLUME_TO_ML['tsp'], 'unit' => 'tsp'];
    }

    /**
     * @param  array<array{amount: float, unit: string}>  $quantities
     * @return array{amount: float, unit: string}
     */
    private function combineWeight(array $quantities): array
    {
        $totalG = 0.0;
        foreach ($quantities as $q) {
            $unit = $q['unit'] ?? '';
            $factor = self::WEIGHT_TO_G[$unit] ?? null;
            if ($factor === null) {
                return $this->fallbackSum($quantities);
            }
            $totalG += ((float) $q['amount']) * $factor;
        }
        if ($totalG >= 1000.0) {
            return ['amount' => $totalG / 1000.0, 'unit' => 'kg'];
        }
        if ($totalG >= 454.0) {
            return ['amount' => $totalG / 454.0, 'unit' => 'lb'];
        }
        return ['amount' => $totalG, 'unit' => 'g'];
    }

    /**
     * @param  array<array{amount: float, unit: string}>  $quantities
     * @return array{amount: float, unit: string}
     */
    private function fallbackSum(array $quantities): array
    {
        $first = $quantities[0];
        $total = 0.0;
        foreach ($quantities as $q) {
            if (($q['unit'] ?? null) === ($first['unit'] ?? null)) {
                $total += (float) $q['amount'];
            }
        }
        return ['amount' => $total, 'unit' => (string) ($first['unit'] ?? '')];
    }
}
