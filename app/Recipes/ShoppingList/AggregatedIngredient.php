<?php

declare(strict_types=1);

namespace App\Recipes\ShoppingList;

/**
 * One row on the shopping list: an ingredient name + its consolidated quantity
 * (or multiple quantities for imprecise units), the aisle it belongs to, and
 * the recipes that contributed.
 */
final class AggregatedIngredient
{
    /**
     * @param  string  $name      Display name (title-cased canonical name, e.g. "Flour", "Garlic Cloves").
     * @param  string  $aisle     One of Aisles::*.
     * @param  array<array{amount: float|null, unit: string|null, source_slug: string, source_title: string}>  $quantities
     * @param  bool    $optional  True only if EVERY contributor marked this ingredient optional.
     * @param  array<string>  $notes  Inline notes (the ingredient's note field) from contributors.
     * @param  string  $display   Rendered shopping-list line (the prerendered fallback string).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $aisle,
        public readonly array $quantities,
        public readonly bool $optional,
        public readonly array $notes,
        public readonly string $display,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'aisle' => $this->aisle,
            'quantities' => $this->quantities,
            'optional' => $this->optional,
            'notes' => $this->notes,
            'display' => $this->display,
        ];
    }
}
