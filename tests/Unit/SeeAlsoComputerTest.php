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

    public function test_three_bread_corpus_picks_strongest_pair(): void
    {
        // A: 4 ingredients (the baseline lean bread)
        // B: shares all 4 with A plus 2 extras (highly similar to A)
        // C: shares only flour with A and B (distant)
        $a = $this->makeRecipe('bread-a', 'breads', [
            'flour', 'water', 'salt', 'yeast',
        ]);
        $b = $this->makeRecipe('bread-b', 'breads', [
            'flour', 'water', 'salt', 'yeast', 'honey', 'butter',
        ]);
        $c = $this->makeRecipe('bread-c', 'breads', [
            'flour', 'rye', 'caraway',
        ]);

        $computer = new SeeAlsoComputer;
        $written = $computer->recompute();

        $this->assertGreaterThan(0, $written);

        // A ↔ B: Jaccard = 4/6 ≈ 0.67 → score ~67
        $aToB = RecipeSeeAlso::where('recipe_id', $a->id)->where('related_recipe_id', $b->id)->value('score');
        $this->assertNotNull($aToB);
        $this->assertEqualsWithDelta(67, $aToB, 1);

        // A ↔ C: Jaccard = 1/6 ≈ 0.167 → barely over the 0.15 threshold, score ~17
        $aToC = RecipeSeeAlso::where('recipe_id', $a->id)->where('related_recipe_id', $c->id)->value('score');
        $this->assertNotNull($aToC);
        $this->assertLessThan($aToB, $aToC, 'A↔C should score lower than A↔B');

        // B ↔ C: Jaccard = 1/8 = 0.125 → BELOW threshold; no record.
        $bToC = RecipeSeeAlso::where('recipe_id', $b->id)->where('related_recipe_id', $c->id)->value('score');
        $this->assertNull($bToC, 'B↔C similarity is below threshold; no see-also record should exist');
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
