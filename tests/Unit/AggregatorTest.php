<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Recipes\ShoppingList\Aggregator;
use App\Recipes\ShoppingList\Aisles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aggregator unit tests.
 *
 * Builds synthetic recipes via Eloquent so the tests don't depend on the
 * production corpus drifting. Each test creates exactly the recipes it
 * needs, then exercises the Aggregator.
 */
final class AggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new Aggregator;
    }

    public function test_two_recipes_with_same_ingredient_same_unit_sum(): void
    {
        $this->makeRecipe('recipe-a', 'Recipe A', [
            ['amount' => 3, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
        ]);
        $this->makeRecipe('recipe-b', 'Recipe B', [
            ['amount' => 2, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
        ]);

        $list = $this->aggregator->aggregate([
            ['slug' => 'recipe-a'],
            ['slug' => 'recipe-b'],
        ]);

        $pantry = $list->byAisle[Aisles::PANTRY] ?? [];
        $this->assertCount(1, $pantry, 'Flour should aggregate into one line.');
        $this->assertSame('Flour', $pantry[0]->name);
        $this->assertEqualsWithDelta(5.0, $pantry[0]->quantities[0]['amount'], 0.001);
        $this->assertSame('cup', $pantry[0]->quantities[0]['unit']);
    }

    public function test_same_ingredient_different_units_in_same_class_convert_and_sum(): void
    {
        // 1 cup + 2 tbsp = 240 + 30 = 270 ml = 1.125 cups
        $this->makeRecipe('a', 'A', [
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'butter'],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 2, 'unit' => 'tbsp', 'unit_class' => 'volume', 'ingredient' => 'butter'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $items = $this->flatItems($list);
        $butter = $this->itemByName($items, 'Butter');
        $this->assertNotNull($butter);
        $this->assertSame('cup', $butter->quantities[0]['unit']);
        $this->assertEqualsWithDelta(1.125, $butter->quantities[0]['amount'], 0.001);
    }

    public function test_different_unit_classes_stay_separate(): void
    {
        // "3 cloves garlic" → unit=whole, ingredient="garlic cloves" (count class)
        // "1 tbsp garlic"   → unit=tbsp,  ingredient="garlic"        (volume class)
        $this->makeRecipe('a', 'A', [
            ['amount' => 3, 'unit' => 'whole', 'unit_class' => 'count', 'ingredient' => 'garlic cloves'],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 1, 'unit' => 'tbsp', 'unit_class' => 'volume', 'ingredient' => 'garlic'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $items = $this->flatItems($list);
        $names = array_map(fn ($i) => $i->name, $items);
        $this->assertContains('Garlic Cloves', $names);
        $this->assertContains('Garlic', $names);
        $this->assertCount(2, $items, 'Two separate ingredients (count vs volume).');
    }

    public function test_optional_aggregation_required_when_any_contributor_required(): void
    {
        $this->makeRecipe('a', 'A', [
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'vanilla extract', 'optional' => true],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'vanilla extract', 'optional' => false],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $item = $this->itemByName($this->flatItems($list), 'Vanilla Extract');
        $this->assertNotNull($item);
        $this->assertFalse($item->optional, 'If any contributor was required, the aggregated line is required.');
    }

    public function test_optional_remains_when_all_contributors_optional(): void
    {
        $this->makeRecipe('a', 'A', [
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'walnuts', 'optional' => true],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 0.5, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'walnuts', 'optional' => true],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $item = $this->itemByName($this->flatItems($list), 'Walnuts');
        $this->assertNotNull($item);
        $this->assertTrue($item->optional);
    }

    public function test_imprecise_stays_separate_per_recipe(): void
    {
        $this->makeRecipe('a', 'Pasta Sauce', [
            ['amount' => null, 'unit' => 'pinch', 'unit_class' => 'imprecise', 'ingredient' => 'salt'],
        ]);
        $this->makeRecipe('b', 'Potato Soup', [
            ['amount' => null, 'unit' => 'to-taste', 'unit_class' => 'imprecise', 'ingredient' => 'salt'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $item = $this->itemByName($this->flatItems($list), 'Salt');
        $this->assertNotNull($item);
        $this->assertCount(2, $item->quantities, 'Imprecise contributions stay as separate quantity entries.');
        // Display: "Salt: a pinch (Pasta Sauce), to taste (Potato Soup)"
        $this->assertStringContainsString('a pinch (Pasta Sauce)', $item->display);
        $this->assertStringContainsString('to taste (Potato Soup)', $item->display);
    }

    public function test_scale_factor_multiplies_amounts(): void
    {
        $this->makeRecipe('a', 'A', [
            ['amount' => 3, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 2, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
        ]);

        $list = $this->aggregator->aggregate([
            ['slug' => 'a', 'scale' => 2.0],   // 6 cups
            ['slug' => 'b', 'scale' => 1.0],   // 2 cups
        ]);
        $item = $this->itemByName($this->flatItems($list), 'Flour');
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(8.0, $item->quantities[0]['amount'], 0.001);
    }

    public function test_aisle_classification_covers_common_ingredients(): void
    {
        $this->makeRecipe('mix', 'Mix', [
            ['amount' => 2, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'milk'],
            ['amount' => 3, 'unit' => 'whole', 'unit_class' => 'count', 'ingredient' => 'eggs'],
            ['amount' => 4, 'unit' => 'whole', 'unit_class' => 'count', 'ingredient' => 'garlic cloves'],
            ['amount' => 1, 'unit' => 'lb', 'unit_class' => 'weight', 'ingredient' => 'ground beef'],
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'paprika'],
            ['amount' => 2, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'yeast'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'mix']]);
        $aisles = array_keys($list->byAisle);
        $this->assertContains(Aisles::PANTRY, $aisles);          // flour
        $this->assertContains(Aisles::DAIRY, $aisles);           // milk + eggs
        $this->assertContains(Aisles::PRODUCE, $aisles);         // garlic cloves
        $this->assertContains(Aisles::MEAT_SEAFOOD, $aisles);    // ground beef
        $this->assertContains(Aisles::SPICES, $aisles);          // paprika
        $this->assertContains(Aisles::BAKING, $aisles);          // yeast
    }

    public function test_empty_input_returns_empty_list(): void
    {
        $list = $this->aggregator->aggregate([]);
        $this->assertSame([], $list->byAisle);
        $this->assertSame([], $list->unparsed);
        $this->assertSame([], $list->sourceRecipes);
        $this->assertSame(0, $list->totalLineCount);
    }

    public function test_same_note_across_recipes_renders_trailing_once(): void
    {
        // Both recipes contribute salt with the same note. Display should
        // collapse to one trailing "— adjust to taste".
        $this->makeRecipe('a', 'Honey Oat Bread', [
            ['amount' => 1.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);
        $this->makeRecipe('b', 'Potato Soup', [
            ['amount' => 0.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $salt = $this->itemByName($this->flatItems($list), 'Salt');
        $this->assertNotNull($salt);

        // Source attribution is the plain comma list — no inline notes.
        $this->assertSame('(Honey Oat Bread, Potato Soup)', $salt->sourceAttribution);
        // Notes survive as a single trailing entry.
        $this->assertSame(['adjust to taste'], $salt->notes);
        // Display matches the brief's expected format.
        $this->assertSame(
            'Salt — 2 tsp (Honey Oat Bread, Potato Soup) — adjust to taste',
            $salt->display,
        );
    }

    public function test_one_recipe_has_note_other_does_not_renders_as_single_trailing_note(): void
    {
        // One contributor has a note, the other doesn't. This is still "single
        // unique note" mode — render trailing.
        $this->makeRecipe('a', 'Honey Oat Bread', [
            ['amount' => 1.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt'],
        ]);
        $this->makeRecipe('b', 'Potato Soup', [
            ['amount' => 0.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $salt = $this->itemByName($this->flatItems($list), 'Salt');
        $this->assertNotNull($salt);
        $this->assertSame('(Honey Oat Bread, Potato Soup)', $salt->sourceAttribution);
        $this->assertSame(['adjust to taste'], $salt->notes);
    }

    public function test_differing_notes_embed_per_source_in_attribution(): void
    {
        // Two recipes, different notes — per-source attribution kicks in.
        $this->makeRecipe('a', 'Honey Oat Bread', [
            ['amount' => 1.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'Diamond Crystal only'],
        ]);
        $this->makeRecipe('b', 'Potato Soup', [
            ['amount' => 0.5, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']]);
        $salt = $this->itemByName($this->flatItems($list), 'Salt');
        $this->assertNotNull($salt);
        // Per-source attribution embeds the differing notes inline.
        $this->assertSame(
            '(Honey Oat Bread — Diamond Crystal only; Potato Soup — adjust to taste)',
            $salt->sourceAttribution,
        );
        // Trailing notes empty — already inline in attribution.
        $this->assertSame([], $salt->notes);
        // Full display.
        $this->assertSame(
            'Salt — 2 tsp (Honey Oat Bread — Diamond Crystal only; Potato Soup — adjust to taste)',
            $salt->display,
        );
    }

    public function test_three_recipes_two_share_a_note_one_differs(): void
    {
        // A and B share a note, C has a different one. Two unique notes → per-source mode.
        $this->makeRecipe('a', 'A', [
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'adjust to taste'],
        ]);
        $this->makeRecipe('c', 'C', [
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt', 'note' => 'Diamond Crystal only'],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b'], ['slug' => 'c']]);
        $salt = $this->itemByName($this->flatItems($list), 'Salt');
        $this->assertNotNull($salt);
        $this->assertSame(
            '(A — adjust to taste; B — adjust to taste; C — Diamond Crystal only)',
            $salt->sourceAttribution,
            'Three contributors with two distinct notes → all three appear with their notes inline.',
        );
        $this->assertSame([], $salt->notes);
    }

    public function test_unparsed_lines_surfaced_with_source(): void
    {
        $this->makeRecipe('a', 'Recipe A', [
            // One parsed and one unparsed line.
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
            ['raw' => 'fancy raw line', 'parsed' => false],
        ]);

        $list = $this->aggregator->aggregate([['slug' => 'a']]);
        $this->assertCount(1, $list->unparsed);
        $this->assertSame('fancy raw line', $list->unparsed[0]['raw']);
        $this->assertSame('a', $list->unparsed[0]['source_slug']);
        $this->assertSame('Recipe A', $list->unparsed[0]['source_title']);
    }

    public function test_nonexistent_slug_is_skipped(): void
    {
        $this->makeRecipe('real', 'Real', [
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
        ]);

        $list = $this->aggregator->aggregate([
            ['slug' => 'real'],
            ['slug' => 'does-not-exist'],
        ]);
        $this->assertCount(1, $list->sourceRecipes);
        $this->assertSame('real', $list->sourceRecipes[0]['slug']);
    }

    public function test_determinism_same_input_same_output(): void
    {
        $this->makeRecipe('a', 'A', [
            ['amount' => 1, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt'],
        ]);
        $this->makeRecipe('b', 'B', [
            ['amount' => 2, 'unit' => 'cup', 'unit_class' => 'volume', 'ingredient' => 'flour'],
            ['amount' => 1, 'unit' => 'tsp', 'unit_class' => 'volume', 'ingredient' => 'salt'],
        ]);

        $first = $this->aggregator->aggregate([['slug' => 'a'], ['slug' => 'b']])->toArray();
        $second = $this->aggregator->aggregate([['slug' => 'b'], ['slug' => 'a']])->toArray();

        // Source recipes change order (input order is preserved); but the
        // aggregated by_aisle/unparsed/total_line_count parts are identical.
        unset($first['source_recipes'], $second['source_recipes']);
        $this->assertSame($first, $second);
    }

    // -- helpers -----------------------------------------------------------

    /**
     * @param  array<array<string,mixed>>  $ingredients
     */
    private function makeRecipe(string $slug, string $title, array $ingredients): Recipe
    {
        $recipe = Recipe::create([
            'slug' => $slug,
            'title' => $title,
            'category' => 'breads',
            'source_path' => "recipes/breads/{$slug}.md",
            'parsed_at' => now(),
        ]);
        foreach ($ingredients as $i => $row) {
            Ingredient::create(array_merge([
                'recipe_id' => $recipe->id,
                'position' => $i + 1,
                'raw' => $row['raw'] ?? ($row['ingredient'] ?? 'unknown'),
                'parsed' => $row['parsed'] ?? true,
            ], $row));
        }
        return $recipe;
    }

    /** @return array<\App\Recipes\ShoppingList\AggregatedIngredient> */
    private function flatItems(\App\Recipes\ShoppingList\AggregatedList $list): array
    {
        $out = [];
        foreach ($list->byAisle as $items) {
            foreach ($items as $i) {
                $out[] = $i;
            }
        }
        return $out;
    }

    /** @param  array<\App\Recipes\ShoppingList\AggregatedIngredient>  $items */
    private function itemByName(array $items, string $name): ?\App\Recipes\ShoppingList\AggregatedIngredient
    {
        foreach ($items as $i) {
            if ($i->name === $name) {
                return $i;
            }
        }
        return null;
    }
}
