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
     * @param  array<string>  $notes  Inline notes that apply across the aggregated line. Populated only when notes are SAME across contributors (or only one contributor had one). When notes DIFFER between contributors, this is empty and the per-source notes get embedded in `$sourceAttribution` instead. Phase 6.2.
     * @param  string  $sourceAttribution  Pre-rendered "(Source A, Source B)" or, when notes differ per recipe, "(Source A — note A; Source B — note B)". The view renders this as the parenthetical after the ingredient.
     * @param  string  $display   Rendered shopping-list line (the prerendered fallback string).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $aisle,
        public readonly array $quantities,
        public readonly bool $optional,
        public readonly array $notes,
        public readonly string $sourceAttribution,
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
            'source_attribution' => $this->sourceAttribution,
            'display' => $this->display,
        ];
    }
}
