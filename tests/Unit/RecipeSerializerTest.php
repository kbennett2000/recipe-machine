<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Parser\Frontmatter;
use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Serializer\RecipeSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11A — synthetic-input unit tests for RecipeSerializer.
 *
 * Each test constructs a ParsedRecipe by hand, serializes it, re-parses
 * the output, and asserts the structural fields survived the round-trip.
 * This complements the corpus-wide parity test by exercising shapes the
 * existing recipes/ files don't currently cover.
 */
final class RecipeSerializerTest extends TestCase
{
    private RecipeParser $parser;

    private RecipeSerializer $serializer;

    protected function setUp(): void
    {
        $this->parser = new RecipeParser;
        $this->serializer = new RecipeSerializer;
    }

    public function test_minimal_recipe_round_trips(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Toast', category: 'breads'),
            ingredients: [
                new ParsedIngredient(raw: '2 slices bread', parsed: true, amount: 2.0, unit: 'whole', ingredient: 'bread'),
            ],
            method: ['Toast until golden.'],
        );
        $this->assertRoundTrips($recipe);
    }

    public function test_recipe_with_all_known_frontmatter_fields(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(
                title: 'Loaded Recipe',
                category: 'breads',
                slug: 'loaded-recipe',
                servings: '4',
                prepTime: '15m',
                cookTime: '40m',
                totalTime: '1h',
                ovenTemp: '350F',
                tags: ['bread', 'beginner'],
                libation: 'Beer.',
                source: 'Phase 11A test',
                difficulty: 'easy',
                yields: 12,
                references: ['honey-oat-bread'],
            ),
            ingredients: [
                new ParsedIngredient(raw: '3 cups flour', parsed: true, amount: 3.0, unit: 'cup', ingredient: 'flour'),
            ],
            method: ['Mix and bake.'],
        );
        $this->assertRoundTrips($recipe);
    }

    public function test_empty_tags_array_is_preserved(): void
    {
        // tags: [] is meaningful — the source explicitly wrote it. Should
        // survive the round-trip as an empty array, not become null.
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Empty Tags', category: 'breads', tags: []),
            ingredients: [new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour')],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertSame([], $b->frontmatter->tags);
    }

    public function test_sub_grouped_ingredients_round_trip(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Two-Part Recipe', category: 'desserts'),
            ingredients: [
                // Top-level (group = null)
                new ParsedIngredient(raw: '2 cups flour', parsed: true, amount: 2.0, unit: 'cup', ingredient: 'flour'),
                // First sub-group
                new ParsedIngredient(raw: '1 cup milk', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'milk', group: 'Filling'),
                new ParsedIngredient(raw: '3 eggs', parsed: true, amount: 3.0, unit: 'whole', ingredient: 'eggs', group: 'Filling'),
                // Second sub-group
                new ParsedIngredient(raw: '1 Tbsp butter', parsed: true, amount: 1.0, unit: 'tbsp', ingredient: 'butter', group: 'Topping'),
            ],
            method: ['Combine.'],
        );
        $b = $this->roundTrip($recipe);
        $groups = array_map(fn (ParsedIngredient $i) => $i->group, $b->ingredients);
        $this->assertSame([null, 'Filling', 'Filling', 'Topping'], $groups);
    }

    public function test_unparsed_lines_mixed_with_parsed_round_trip(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Mixed Recipe', category: 'entrees'),
            ingredients: [
                new ParsedIngredient(raw: '2 cups flour', parsed: true, amount: 2.0, unit: 'cup', ingredient: 'flour'),
                // Unparsed — a section-header-style line the rules-based parser couldn't structure.
                new ParsedIngredient(raw: 'For the glaze:', parsed: false),
                new ParsedIngredient(raw: '1/4 cup honey', parsed: true, amount: 0.25, unit: 'cup', ingredient: 'honey'),
            ],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        // The middle line survives as parsed=false with its raw text.
        $this->assertFalse($b->ingredients[1]->parsed);
        $this->assertSame('For the glaze:', $b->ingredients[1]->raw);
    }

    public function test_optional_ingredient_round_trips(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Recipe with Optional', category: 'desserts'),
            ingredients: [
                new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour'),
                new ParsedIngredient(raw: '2 Tbsp soy sauce', parsed: true, amount: 2.0, unit: 'tbsp', ingredient: 'soy sauce', optional: true),
            ],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertTrue($b->ingredients[1]->optional);
        $this->assertSame('soy sauce', $b->ingredients[1]->ingredient);
    }

    public function test_range_amounts_round_trip(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Range Recipe', category: 'breads'),
            ingredients: [
                // Same-unit range
                new ParsedIngredient(raw: '2-3 cups water', parsed: true, amount: 2.0, amountHigh: 3.0, unit: 'cup', ingredient: 'water'),
            ],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertSame(2.0, $b->ingredients[0]->amount);
        $this->assertSame(3.0, $b->ingredients[0]->amountHigh);
        $this->assertSame('cup', $b->ingredients[0]->unit);
    }

    public function test_custom_frontmatter_fields_survive_as_extra(): void
    {
        $recipe = $this->make(
            frontmatter: new Frontmatter(
                title: 'Recipe with Extras',
                category: 'breads',
                extra: ['weight_grams' => 500, 'origin' => 'Phase 11A test'],
            ),
            ingredients: [
                new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour'),
            ],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertSame(500, $b->frontmatter->extra['weight_grams']);
        $this->assertSame('Phase 11A test', $b->frontmatter->extra['origin']);
    }

    public function test_body_libation_section_round_trips(): void
    {
        // When the source has a `## Libation` body section (libationProse
        // populated), the serializer must emit that section back.
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Recipe with Body Libation', category: 'desserts'),
            ingredients: [new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour')],
            method: ['Mix.'],
            libationProse: 'A glass of port, with a twist of orange peel.',
        );
        $b = $this->roundTrip($recipe);
        $this->assertSame('A glass of port, with a twist of orange peel.', $b->libationProse);
    }

    public function test_cross_references_in_notes_round_trip(): void
    {
        // Inline [[bracket]] references in the notes section should survive
        // verbatim. The parser collects them into crossReferences as slugs.
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'Recipe with Refs', category: 'desserts'),
            ingredients: [new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour')],
            method: ['Mix.'],
            notes: "Pairs well with [[pie-crust]] for a quick weeknight dessert.",
            crossReferences: ['pie-crust'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertStringContainsString('[[pie-crust]]', (string) $b->notes);
        $this->assertContains('pie-crust', $b->crossReferences);
    }

    public function test_llm_derived_amount_high_only_ingredient_round_trips(): void
    {
        // Phase 11A.1: an ingredient with amount=null + amount_high set is
        // the shape the LLM fallback emits for "Up to N unit X" lines. The
        // IngredientFormatter (Phase 9.2) renders it back as "up to <n> <unit>
        // <ingredient>", and the rules-based parser (Phase 11A.1) now
        // recognizes that prefix on re-parse. End-to-end round-trip.
        $recipe = $this->make(
            frontmatter: new Frontmatter(title: 'LLM-Style Recipe', category: 'entrees'),
            ingredients: [
                new ParsedIngredient(
                    raw: 'Up to 1/4 cup toasted sesame seed oil',
                    parsed: true,
                    amount: null,
                    amountHigh: 0.25,
                    unit: 'cup',
                    ingredient: 'toasted sesame seed oil',
                ),
            ],
            method: ['Drizzle and serve.'],
        );
        $b = $this->roundTrip($recipe);
        $ing = $b->ingredients[0];
        $this->assertTrue($ing->parsed);
        $this->assertNull($ing->amount);
        $this->assertSame(0.25, $ing->amountHigh);
        $this->assertSame('cup', $ing->unit);
        $this->assertSame('toasted sesame seed oil', $ing->ingredient);
    }

    public function test_frontmatter_references_round_trip(): void
    {
        // The frontmatter `references:` field is separate from inline brackets.
        // Both populate ParsedRecipe::crossReferences but only the frontmatter
        // field gets re-emitted to YAML on serialize.
        $recipe = $this->make(
            frontmatter: new Frontmatter(
                title: 'Recipe with Refs in FM',
                category: 'desserts',
                references: ['pie-crust', 'apple-pie'],
            ),
            ingredients: [new ParsedIngredient(raw: '1 cup flour', parsed: true, amount: 1.0, unit: 'cup', ingredient: 'flour')],
            method: ['Mix.'],
        );
        $b = $this->roundTrip($recipe);
        $this->assertSame(['pie-crust', 'apple-pie'], $b->frontmatter->references);
    }

    /**
     * Phase 11H.1 regression — when every frontmatter field is empty
     * (the /recipes/new flow before the user has typed anything), the
     * serializer used to emit `---\n{  }---\n\n## Ingredients\n` because
     * symfony/yaml dumps an empty PHP array as the flow-style `{  }`
     * literal. The closing delimiter ended up on the same line as the
     * empty mapping, and the parser regex couldn't match. The fix is to
     * emit empty content between the delimiters for an empty frontmatter
     * map, so the output is structurally well-formed even if the recipe
     * has no metadata yet.
     */
    public function test_empty_frontmatter_does_not_glue_closing_delimiter(): void
    {
        $recipe = new ParsedRecipe(
            frontmatter: new Frontmatter(title: '', category: ''),
            ingredients: [],
            method: [],
        );
        $markdown = $this->serializer->serialize($recipe);

        // The `{  }` flow-style empty mapping must not appear adjacent to
        // the closing delimiter — that's the substring that broke the
        // round-trip.
        $this->assertStringNotContainsString('{  }---', $markdown);
        $this->assertStringNotContainsString("{  }\n---", $markdown);
        // Delimiters must each occupy their own line.
        $this->assertStringContainsString("---\n---\n", $markdown);
    }

    /**
     * Build a ParsedRecipe in one call with sane defaults.
     *
     * @param  array<ParsedIngredient>  $ingredients
     * @param  array<string>  $method
     * @param  array<string>  $crossReferences
     */
    private function make(
        Frontmatter $frontmatter,
        array $ingredients,
        array $method,
        ?string $notes = null,
        ?string $libationProse = null,
        array $crossReferences = [],
    ): ParsedRecipe {
        return new ParsedRecipe(
            frontmatter: $frontmatter,
            ingredients: $ingredients,
            method: $method,
            notes: $notes,
            libationProse: $libationProse,
            crossReferences: $crossReferences,
        );
    }

    /** Run a recipe through serialize → re-parse and return the parsed result. */
    private function roundTrip(ParsedRecipe $recipe): ParsedRecipe
    {
        $markdown = $this->serializer->serialize($recipe);
        try {
            return $this->parser->parseString($markdown);
        } catch (\Throwable $e) {
            $this->fail("Re-parse failed: {$e->getMessage()}\n\nSerialized output:\n{$markdown}");
        }
    }

    /** Assert that a synthetic recipe survives the parse(serialize) round-trip. */
    private function assertRoundTrips(ParsedRecipe $a): void
    {
        $b = $this->roundTrip($a);
        // Compare a few canonical structural fields.
        $this->assertSame($a->frontmatter->title, $b->frontmatter->title);
        $this->assertSame($a->frontmatter->category, $b->frontmatter->category);
        $this->assertSame(count($a->ingredients), count($b->ingredients));
        $this->assertSame($a->method, $b->method);
    }
}
