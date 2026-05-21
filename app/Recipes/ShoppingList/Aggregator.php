<?php

declare(strict_types=1);

namespace App\Recipes\ShoppingList;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Recipes\Display\IngredientFormatter;

/**
 * Consolidates ingredients across multiple recipes into a shopping list.
 *
 * Input:
 *   [
 *     ['slug' => 'honey-oat-bread', 'scale' => 2.0],
 *     ['slug' => 'potato-soup',     'scale' => 1.0],
 *   ]
 *
 * Algorithm:
 *   1. Load each recipe with its parsed ingredients.
 *   2. Multiply each ingredient's amount by the recipe's scale factor.
 *   3. Bucket ingredients by (canonical_name, unit_class). The bucket key
 *      determines what gets summed.
 *   4. Within each bucket, combine via UnitConverter (same-class units
 *      convert to a common base and sum; cross-class doesn't bucket).
 *   5. Imprecise units (pinch/dash/etc.) skip summation — each contribution
 *      stays as a separate quantity entry on the AggregatedIngredient,
 *      surfaced inline.
 *   6. Optional ingredients aggregate normally; the output line is flagged
 *      optional only when EVERY contributor marked it optional.
 *   7. Unparsed lines flow into the unparsed bucket with their source.
 *   8. Each ingredient is classified into an aisle via Aisles::classify().
 *   9. Within an aisle, ingredients sort alphabetically by canonical name.
 *  10. Aisles render in store-traversal order (Aisles::AISLE_ORDER).
 *
 * Deterministic: same input → same output, regardless of recipe insertion
 * order or DB row order. Aisles are sorted; ingredients within each aisle
 * are sorted; quantity contributions per imprecise ingredient are sorted by
 * source slug.
 */
final class Aggregator
{
    public function __construct(
        private readonly UnitConverter $converter = new UnitConverter,
        private readonly IngredientFormatter $formatter = new IngredientFormatter,
    ) {}

    /**
     * @param  array<array{slug: string, scale?: float|int}>  $items
     */
    public function aggregate(array $items): AggregatedList
    {
        // Sanitize input.
        $sane = [];
        foreach ($items as $it) {
            $slug = (string) ($it['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $scale = (float) ($it['scale'] ?? 1.0);
            if ($scale <= 0) {
                continue;
            }
            $sane[$slug] = $scale; // dedup by slug; last write wins
        }

        if ($sane === []) {
            return new AggregatedList(byAisle: [], unparsed: [], sourceRecipes: [], totalLineCount: 0);
        }

        $recipes = Recipe::with('ingredients')->whereIn('slug', array_keys($sane))->get()->keyBy('slug');

        $sourceRecipes = [];
        $buckets = [];     // key = "name||class" → bucket array
        $unparsed = [];

        // Walk in input order (stable for deterministic output of source_recipes list).
        foreach ($sane as $slug => $scale) {
            $recipe = $recipes->get($slug);
            if ($recipe === null) {
                continue;
            }
            $sourceRecipes[] = [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'scale' => $scale,
            ];
            foreach ($recipe->ingredients as $ing) {
                if (! $ing->parsed) {
                    $unparsed[] = [
                        'raw' => $ing->raw,
                        'source_slug' => $recipe->slug,
                        'source_title' => $recipe->title,
                    ];
                    continue;
                }
                $this->addToBucket($buckets, $ing, $recipe, $scale);
            }
        }

        // Resolve each bucket into an AggregatedIngredient.
        $aggregated = [];
        foreach ($buckets as $key => $bucket) {
            $aggregated[] = $this->resolveBucket($bucket);
        }

        // Group by aisle, sort within each aisle alphabetically.
        $byAisle = [];
        foreach (Aisles::AISLE_ORDER as $aisle) {
            $byAisle[$aisle] = [];
        }
        foreach ($aggregated as $ai) {
            $byAisle[$ai->aisle][] = $ai;
        }
        foreach ($byAisle as $aisle => $list) {
            usort($list, fn (AggregatedIngredient $a, AggregatedIngredient $b) => strcmp($a->name, $b->name));
            $byAisle[$aisle] = $list;
        }
        // Drop empty aisles for a tidier output.
        $byAisle = array_filter($byAisle, fn (array $list) => $list !== []);

        return new AggregatedList(
            byAisle: $byAisle,
            unparsed: $unparsed,
            sourceRecipes: $sourceRecipes,
            totalLineCount: count($aggregated),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function addToBucket(array &$buckets, Ingredient $ing, Recipe $recipe, float $scale): void
    {
        $canonical = $this->canonicalName((string) $ing->ingredient);
        if ($canonical === '') {
            return;
        }
        $unitClass = $ing->unit_class ?? '';
        $key = $canonical.'||'.$unitClass;

        if (! isset($buckets[$key])) {
            $buckets[$key] = [
                'canonical_name' => $canonical,
                'display_name' => $this->titleCase($canonical),
                'unit_class' => $unitClass,
                'contributions' => [],
                'all_optional' => true,
                'notes' => [],
            ];
        }

        $scaledAmount = $ing->amount !== null ? ((float) $ing->amount) * $scale : null;
        $scaledHigh = $ing->amount_high !== null ? ((float) $ing->amount_high) * $scale : null;

        $buckets[$key]['contributions'][] = [
            'amount' => $scaledAmount,
            'amount_high' => $scaledHigh,
            'unit' => $ing->unit,
            'modifier' => $ing->modifier,
            'optional' => (bool) $ing->optional,
            'source_slug' => $recipe->slug,
            'source_title' => $recipe->title,
        ];
        if (! $ing->optional) {
            $buckets[$key]['all_optional'] = false;
        }
        if ($ing->note !== null && $ing->note !== '') {
            $buckets[$key]['notes'][] = $ing->note;
        }
    }

    private function resolveBucket(array $bucket): AggregatedIngredient
    {
        $contributions = $bucket['contributions'];
        $unitClass = $bucket['unit_class'];

        $aisle = Aisles::classify($bucket['canonical_name']);

        // Imprecise: don't sum. Each contribution stays distinct, sorted by source slug.
        if ($unitClass === 'imprecise') {
            usort($contributions, fn ($a, $b) => strcmp($a['source_slug'], $b['source_slug']));
            $quantities = array_map(fn ($c) => [
                'amount' => null,
                'unit' => $c['unit'],
                'source_slug' => $c['source_slug'],
                'source_title' => $c['source_title'],
            ], $contributions);
            $display = $this->renderImpreciseDisplay($bucket['display_name'], $contributions);

            return new AggregatedIngredient(
                name: $bucket['display_name'],
                aisle: $aisle,
                quantities: $quantities,
                optional: $bucket['all_optional'],
                notes: $bucket['notes'],
                display: $display,
            );
        }

        // Convertible / countable: combine via UnitConverter.
        $combined = $this->converter->combineSameClass(
            array_map(fn ($c) => [
                'amount' => $c['amount'] ?? 0.0,
                'unit' => $c['unit'],
            ], $contributions),
            $unitClass,
        );

        // For ranges, sum the high bounds too (assumes all contributors with high bounds use the same unit).
        $combinedHigh = null;
        $anyHigh = false;
        foreach ($contributions as $c) {
            if ($c['amount_high'] !== null) {
                $anyHigh = true;
                break;
            }
        }
        if ($anyHigh) {
            $highs = array_map(fn ($c) => [
                'amount' => $c['amount_high'] ?? $c['amount'] ?? 0.0,
                'unit' => $c['unit'],
            ], $contributions);
            $combinedHighConv = $this->converter->combineSameClass($highs, $unitClass);
            $combinedHigh = $combinedHighConv['amount'];
        }

        $sources = $this->orderedUniqueSources($contributions);

        $quantities = [[
            'amount' => $combined['amount'],
            'amount_high' => $combinedHigh,
            'unit' => $combined['unit'],
            'sources' => $sources,
        ]];

        $display = $this->renderStandardDisplay($bucket['display_name'], $combined, $combinedHigh, $sources);

        return new AggregatedIngredient(
            name: $bucket['display_name'],
            aisle: $aisle,
            quantities: $quantities,
            optional: $bucket['all_optional'],
            notes: $bucket['notes'],
            display: $display,
        );
    }

    /**
     * @param  array<array<string,mixed>>  $contributions
     */
    private function renderImpreciseDisplay(string $name, array $contributions): string
    {
        $parts = [];
        foreach ($contributions as $c) {
            $phrase = $this->imprecisePhrase($c['unit']);
            $parts[] = $phrase.' ('.$c['source_title'].')';
        }
        return $name.': '.implode(', ', $parts);
    }

    private function imprecisePhrase(?string $unit): string
    {
        return match ($unit) {
            'to-taste' => 'to taste',
            'as-needed' => 'as needed',
            null => 'a pinch',
            default => 'a '.$unit,
        };
    }

    /**
     * @param  array{amount: float, unit: string}  $combined
     * @param  array<string>  $sourceTitles
     */
    private function renderStandardDisplay(string $name, array $combined, ?float $combinedHigh, array $sourceTitles): string
    {
        $amountText = $this->formatter->formatAmount($combined['amount'], $combinedHigh, $combined['unit']);
        $unitText = $combined['unit'] !== '' && $combined['unit'] !== 'whole'
            ? ' '.$this->unitDisplay($combined['unit'], $this->amountIsPlural($combined['amount'], $combinedHigh))
            : '';
        $sources = $sourceTitles === [] ? '' : ' ('.implode(', ', $sourceTitles).')';
        return $name.' — '.$amountText.$unitText.$sources;
    }

    private function unitDisplay(string $unit, bool $plural): string
    {
        // Re-use IngredientFormatter's display rules indirectly via its formatFields path:
        // a tiny inline map covering the units we'll commonly hit. (UNIT_DISPLAY is private
        // on the formatter, so we pluralize here directly for output.)
        $map = [
            'tsp' => ['tsp','tsp'], 'tbsp' => ['Tbsp','Tbsp'], 'cup' => ['cup','cups'],
            'floz' => ['fl oz','fl oz'], 'pint' => ['pint','pints'], 'quart' => ['quart','quarts'],
            'gallon' => ['gallon','gallons'], 'ml' => ['ml','ml'], 'l' => ['L','L'],
            'g' => ['g','g'], 'kg' => ['kg','kg'], 'oz' => ['oz','oz'], 'lb' => ['lb','lb'],
        ];
        [$s, $p] = $map[$unit] ?? [$unit, $unit];
        return $plural ? $p : $s;
    }

    private function amountIsPlural(float $amount, ?float $amountHigh): bool
    {
        $ref = $amountHigh ?? $amount;
        return $ref > 1.0;
    }

    /**
     * @param  array<array<string,mixed>>  $contributions
     * @return array<string>
     */
    private function orderedUniqueSources(array $contributions): array
    {
        $titles = [];
        $seen = [];
        // Sort by source slug for determinism.
        usort($contributions, fn ($a, $b) => strcmp($a['source_slug'], $b['source_slug']));
        foreach ($contributions as $c) {
            if (! isset($seen[$c['source_slug']])) {
                $seen[$c['source_slug']] = true;
                $titles[] = $c['source_title'];
            }
        }
        return $titles;
    }

    private function canonicalName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        return $name;
    }

    private function titleCase(string $lowercased): string
    {
        // Standard ucwords; doesn't handle hyphens (e.g. "All-purpose" stays mixed).
        // Acceptable for v1.
        return ucwords($lowercased);
    }
}
