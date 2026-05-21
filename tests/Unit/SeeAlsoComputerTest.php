<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeSeeAlso;
use App\Recipes\Indexing\SeeAlsoComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixture-corpus test for the see-also similarity computation. Inserts three
 * bread recipes with controlled ingredient overlap, then asserts the
 * pairwise scores match Jaccard expectations.
 */
final class SeeAlsoComputerTest extends TestCase
{
    use RefreshDatabase;

    public function test_jaccard_returns_zero_for_disjoint_sets(): void
    {
        $c = new SeeAlsoComputer;
        $this->assertEqualsWithDelta(0.0, $c->jaccard(['a' => true], ['b' => true]), 0.001);
    }

    public function test_jaccard_returns_one_for_identical_sets(): void
    {
        $c = new SeeAlsoComputer;
        $this->assertEqualsWithDelta(1.0, $c->jaccard(['a' => true, 'b' => true], ['a' => true, 'b' => true]), 0.001);
    }

    public function test_jaccard_partial_overlap(): void
    {
        $c = new SeeAlsoComputer;
        // |A ∩ B| = 2, |A ∪ B| = 4 → 0.5
        $a = ['flour' => true, 'salt' => true, 'water' => true];
        $b = ['flour' => true, 'salt' => true, 'yeast' => true];
        // intersection = {flour, salt} = 2; union = {flour, salt, water, yeast} = 4 → 0.5
        $this->assertEqualsWithDelta(0.5, $c->jaccard($a, $b), 0.001);
    }

    public function test_threshold_keeps_strong_pair_drops_borderline(): void
    {
        // Three breads. A and B both have 6 significant ingredients
        // (source-eligible). C has 3 (target-only). The pairwise Jaccards:
        //
        //   A ↔ B: |∩|=4, |∪|=8 → 0.5    → score 50 (above 0.20 threshold)
        //   A ↔ C: |∩|=1, |∪|=8 → 0.125  → BELOW threshold
        //   B ↔ C: |∩|=1, |∪|=8 → 0.125  → BELOW threshold
        $a = $this->makeRecipe('bread-a', 'breads', [
            'flour', 'water', 'salt', 'yeast', 'butter', 'honey',
        ]);
        $b = $this->makeRecipe('bread-b', 'breads', [
            'flour', 'water', 'salt', 'yeast', 'sugar', 'oil',
        ]);
        $c = $this->makeRecipe('bread-c', 'breads', [
            'flour', 'rye', 'caraway',
        ]);

        (new SeeAlsoComputer)->recompute();

        $aToB = RecipeSeeAlso::where('recipe_id', $a->id)->where('related_recipe_id', $b->id)->value('score');
        $this->assertNotNull($aToB, 'A↔B should be recorded');
        $this->assertEqualsWithDelta(50, $aToB, 1);

        // Borderline pairs that were caught at the old 0.15 threshold drop here.
        $aToC = RecipeSeeAlso::where('recipe_id', $a->id)->where('related_recipe_id', $c->id)->value('score');
        $this->assertNull($aToC, 'A↔C jaccard 0.125 is below the 0.20 threshold');

        $bToC = RecipeSeeAlso::where('recipe_id', $b->id)->where('related_recipe_id', $c->id)->value('score');
        $this->assertNull($bToC, 'B↔C jaccard 0.125 is below the 0.20 threshold');
    }

    public function test_small_fingerprint_recipes_are_targets_not_sources(): void
    {
        // Asymmetric rule: a recipe with fingerprint < 5 doesn't generate
        // its own see-also rows but can still be the TARGET of another
        // recipe's similarity. Big (size 10) and Small (size 4) share 3
        // staples → Jaccard 3/11 ≈ 0.273, above the 0.20 threshold.
        $big = $this->makeRecipe('big-bread', 'breads', [
            'flour', 'water', 'salt', 'yeast', 'butter', 'honey', 'milk', 'eggs', 'sugar', 'oats',
        ]);
        $small = $this->makeRecipe('small-bread', 'breads', [
            'flour', 'water', 'salt', 'yeast',
        ]);

        (new SeeAlsoComputer)->recompute();

        // Big sources a see-also link to Small.
        $bigToSmall = RecipeSeeAlso::where('recipe_id', $big->id)
            ->where('related_recipe_id', $small->id)->value('score');
        $this->assertNotNull($bigToSmall, 'Big (size 10) should source a see-also link to Small (size 4)');
        $this->assertGreaterThanOrEqual(20, $bigToSmall);

        // Small (size 4) does NOT source any see-also rows.
        $smallSourced = RecipeSeeAlso::where('recipe_id', $small->id)->count();
        $this->assertSame(0, $smallSourced, 'Small (size < 5) must not appear as the source of any see-also link');
    }

    public function test_cross_category_recipes_do_not_link(): void
    {
        $bread = $this->makeRecipe('bread-x', 'breads', ['flour', 'water', 'salt']);
        $soup = $this->makeRecipe('soup-x', 'soups', ['flour', 'water', 'salt']);

        (new SeeAlsoComputer)->recompute();

        $this->assertSame(0, RecipeSeeAlso::count(), 'Different-category recipes must not be linked in v1');
    }

    /**
     * Insert a recipe + the supplied parsed ingredient names. All ingredients
     * are created with parsed=true, unit_class=volume so they qualify for
     * the fingerprint.
     *
     * @param  list<string>  $ingredientNames
     */
    private function makeRecipe(string $slug, string $category, array $ingredientNames): Recipe
    {
        $recipe = Recipe::create([
            'slug' => $slug,
            'title' => ucwords(str_replace('-', ' ', $slug)),
            'category' => $category,
            // NOT NULL in the schema — supply a placeholder for the fixture.
            'source_path' => $slug.'.md',
            'source_mtime' => now(),
            'parsed_at' => now(),
        ]);
        foreach ($ingredientNames as $i => $name) {
            Ingredient::create([
                'recipe_id' => $recipe->id,
                'position' => $i + 1,
                'raw' => '1 cup '.$name,
                'parsed' => true,
                'amount' => 1,
                'unit' => 'cup',
                'unit_class' => 'volume',
                'ingredient' => $name,
            ]);
        }
        return $recipe;
    }
}
