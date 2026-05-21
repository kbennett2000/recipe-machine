<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Display\IngredientFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IngredientFormatterTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_format(array $fields, string $expected): void
    {
        $formatter = new IngredientFormatter;
        $this->assertSame($expected, $formatter->formatFields($fields));
    }

    public static function cases(): array
    {
        return [
            // Whole numbers + pluralization
            'integer with plural cup'           => [['amount' => 2, 'unit' => 'cup', 'ingredient' => 'flour'],            '2 cups flour'],
            'integer with singular cup'         => [['amount' => 1, 'unit' => 'cup', 'ingredient' => 'flour'],            '1 cup flour'],
            'no plural form for tsp'            => [['amount' => 3, 'unit' => 'tsp', 'ingredient' => 'salt'],             '3 tsp salt'],

            // Fractions
            'half cup'                          => [['amount' => 0.5,  'unit' => 'cup', 'ingredient' => 'butter'],         '1/2 cup butter'],
            'quarter cup'                       => [['amount' => 0.25, 'unit' => 'cup', 'ingredient' => 'sugar'],          '1/4 cup sugar'],
            'third cup'                         => [['amount' => 0.333,'unit' => 'cup', 'ingredient' => 'milk'],           '1/3 cup milk'],

            // Mixed numbers
            'one and a half cups'               => [['amount' => 1.5,  'unit' => 'cup', 'ingredient' => 'flour'],          '1 1/2 cups flour'],
            'two and a quarter tsp'             => [['amount' => 2.25, 'unit' => 'tsp', 'ingredient' => 'yeast'],          '2 1/4 tsp yeast'],

            // Ranges
            'range with plural'                 => [['amount' => 2, 'amount_high' => 3, 'unit' => 'cup', 'ingredient' => 'water'], '2–3 cups water'],
            'range with whole singular result'  => [['amount' => 1, 'amount_high' => 2, 'unit' => 'tbsp', 'ingredient' => 'oil'],  '1–2 Tbsp oil'],

            // Imprecise leading
            'pinch of salt'                     => [['amount' => null, 'unit' => 'pinch', 'unit_class' => 'imprecise', 'ingredient' => 'salt'],   'a pinch of salt'],
            'handful of arugula'                => [['amount' => null, 'unit' => 'handful', 'unit_class' => 'imprecise', 'ingredient' => 'arugula'], 'a handful of arugula'],

            // Imprecise trailing
            'salt to taste'                     => [['amount' => null, 'unit' => 'to-taste', 'unit_class' => 'imprecise', 'ingredient' => 'salt'], 'salt to taste'],
            'olive oil as needed'               => [['amount' => null, 'unit' => 'as-needed', 'unit_class' => 'imprecise', 'ingredient' => 'olive oil'], 'olive oil, as needed'],

            // Whole / count items
            'three eggs whole'                  => [['amount' => 3, 'unit' => 'whole', 'ingredient' => 'eggs'], '3 eggs'],
            'one large onion whole'             => [['amount' => 1, 'unit' => 'whole', 'ingredient' => 'large onion'], '1 large onion'],

            // ~ prefix for non-integer whole counts (Phase 5)
            'four-and-a-half eggs gets tilde'   => [['amount' => 4.5, 'unit' => 'whole', 'ingredient' => 'eggs'], '~4.5 eggs'],
            'half-onion gets tilde'             => [['amount' => 1.5, 'unit' => 'whole', 'ingredient' => 'onions'], '~1.5 onions'],
            'range with fractional high gets tilde' => [['amount' => 3, 'amount_high' => 4.5, 'unit' => 'whole', 'ingredient' => 'garlic cloves'], '~3–4.5 garlic cloves'],
            'integer-rounded whole count loses tilde' => [['amount' => 5.0, 'unit' => 'whole', 'ingredient' => 'eggs'], '5 eggs'],

            // Modifier and optional
            'with modifier'                     => [['amount' => 0.5, 'unit' => 'cup', 'ingredient' => 'butter', 'modifier' => 'softened'], '1/2 cup butter, softened'],
            'optional flag'                     => [['amount' => 1, 'unit' => 'whole', 'ingredient' => 'egg', 'optional' => true], '1 egg (optional)'],

            // Decimal fallback (no fraction match)
            'decimal fallback 0.4'              => [['amount' => 0.4, 'unit' => 'cup', 'ingredient' => 'sugar'], '0.4 cup sugar'],
            'decimal fallback 0.35'             => [['amount' => 0.35, 'unit' => 'tsp', 'ingredient' => 'salt'], '0.35 tsp salt'],
        ];
    }

    /**
     * Spot-check the brief's six scaling examples directly: apply scale and
     * verify the formatted output for each.
     */
    public function test_brief_scaling_examples(): void
    {
        $f = new IngredientFormatter;

        // 1.5 cups, scale x2 → 3 cups
        $this->assertSame('3 cups flour', $f->formatFields(['amount' => 1.5 * 2, 'unit' => 'cup', 'ingredient' => 'flour']));

        // 3 eggs, scale x1.5 → ~4.5 eggs
        $this->assertSame('~4.5 eggs', $f->formatFields(['amount' => 3 * 1.5, 'unit' => 'whole', 'ingredient' => 'eggs']));

        // 0.25 tsp, scale x4 → 1 tsp
        $this->assertSame('1 tsp salt', $f->formatFields(['amount' => 0.25 * 4, 'unit' => 'tsp', 'ingredient' => 'salt']));

        // 2-3 cloves garlic, scale x2 → 4-6 garlic cloves (the parser would fold "cloves garlic" → "garlic cloves")
        $this->assertSame(
            '4–6 garlic cloves',
            $f->formatFields(['amount' => 2 * 2, 'amount_high' => 3 * 2, 'unit' => 'whole', 'ingredient' => 'garlic cloves']),
        );

        // a pinch of salt, scale x3 → a pinch of salt (no scaling)
        $this->assertSame(
            'a pinch of salt',
            $f->formatFields(['amount' => null, 'unit' => 'pinch', 'unit_class' => 'imprecise', 'ingredient' => 'salt']),
        );

        // 1.25 lb butter, scale x0.5 → 5/8 lb butter
        $this->assertSame('5/8 lb butter', $f->formatFields(['amount' => 1.25 * 0.5, 'unit' => 'lb', 'ingredient' => 'butter']));
    }
}
