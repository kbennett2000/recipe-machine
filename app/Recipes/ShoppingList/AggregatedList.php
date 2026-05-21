<?php

declare(strict_types=1);

namespace App\Recipes\ShoppingList;

/**
 * Complete aggregated shopping list — what the Aggregator returns.
 */
final class AggregatedList
{
    /**
     * @param  array<string, array<AggregatedIngredient>>  $byAisle  Aisle key → ingredients in that aisle (alphabetically sorted).
     * @param  array<array{raw: string, source_slug: string, source_title: string}>  $unparsed  Lines that couldn't be auto-parsed by the original recipe parser; surfaced under "Other / verify" in the UI.
     * @param  array<array{slug: string, title: string, scale: float}>  $sourceRecipes  The recipes that fed into this list.
     * @param  int  $totalLineCount  Number of distinct shopping items (parsed only — unparsed not counted).
     */
    public function __construct(
        public readonly array $byAisle,
        public readonly array $unparsed,
        public readonly array $sourceRecipes,
        public readonly int $totalLineCount,
    ) {}

    public function toArray(): array
    {
        $byAisleArr = [];
        foreach ($this->byAisle as $aisle => $items) {
            $byAisleArr[$aisle] = array_map(fn (AggregatedIngredient $i) => $i->toArray(), $items);
        }
        return [
            'by_aisle' => $byAisleArr,
            'unparsed' => $this->unparsed,
            'source_recipes' => $this->sourceRecipes,
            'total_line_count' => $this->totalLineCount,
        ];
    }
}
