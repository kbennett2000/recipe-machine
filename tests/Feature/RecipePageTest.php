<?php

declare(strict_types=1);

namespace Tests\Feature;

final class RecipePageTest extends IndexedCorpusTestCase
{
    public function test_recipe_page_renders_honey_oat_bread(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertStatus(200);
        $response->assertSee('Honey Oat Bread');
        $response->assertSee('Ingredients');
        $response->assertSee('Method');
    }

    public function test_recipe_page_uses_parsed_prose_not_raw(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        // The parsed-prose rendering uses the structured fields:
        //  amount=3, unit=cup, ingredient="flour" → "3 cups flour".
        $response->assertSee('3 cups flour');
        // Source line is "1 1/2 tsp salt"; the formatter renders "1 1/2 tsp salt".
        $response->assertSee('1 1/2 tsp salt');
    }

    public function test_recipe_page_highlights_temperature_and_timer(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertSee('metric-temp', escape: false);
        $response->assertSee('metric-timer', escape: false);
    }

    public function test_recipe_page_section_order_is_canonical(): void
    {
        // The canonical render order is title → metadata → ingredients → method → notes → libation.
        $response = $this->get('/recipes/honey-oat-bread');
        $body = $response->getContent();
        $ingredientsPos = strpos($body, '<h2 class="font-display text-xl font-semibold mb-4 text-stone-900 dark:text-stone-100">Ingredients</h2>');
        $methodPos = strpos($body, 'Method</h2>');
        $this->assertNotFalse($ingredientsPos);
        $this->assertNotFalse($methodPos);
        $this->assertLessThan($methodPos, $ingredientsPos, 'Ingredients heading should come before Method heading.');
    }

    public function test_vanilla_ice_cream_renders_ingredients_before_notes_even_though_source_reverses_them(): void
    {
        // vanilla-ice-cream.md has `## Notes` before `## Ingredients` in the source file.
        // The rendered detail page must still show Ingredients first per the canonical order.
        $response = $this->get('/recipes/vanilla-ice-cream');
        $response->assertStatus(200);
        $body = $response->getContent();
        // Find positions of the two section headings.
        $ingredientsPos = strpos($body, '>Ingredients</h2>');
        $notesPos = strpos($body, '>Notes</h2>');
        $this->assertNotFalse($ingredientsPos);
        $this->assertNotFalse($notesPos);
        $this->assertLessThan($notesPos, $ingredientsPos,
            'Vanilla ice cream must render Ingredients before Notes regardless of source-file section order.');
    }

    public function test_unknown_recipe_returns_404(): void
    {
        $this->get('/recipes/no-such-recipe-anywhere')->assertStatus(404);
    }

    public function test_cross_references_render_as_links(): void
    {
        // Apple Pie's frontmatter references: [pie-crust] — resolved at index time.
        // We don't expect inline [[brackets]] in this body, but the references
        // are stored and the "Referenced by" footer of pie-crust should link to it.
        $response = $this->get('/recipes/pie-crust');
        $response->assertStatus(200);
        $response->assertSee('Referenced by');
        $response->assertSee('Apple Pie');
        $response->assertSee('French Silk Pie');
        // The references should be actual links.
        $response->assertSee(route('recipes.show', ['recipe' => 'apple-pie']));
    }

    public function test_zero_method_recipe_shows_placeholder(): void
    {
        // Pasta sauce has 0 method steps in the source codex.
        $response = $this->get('/recipes/pasta-sauce');
        $response->assertStatus(200);
        $response->assertSee('No instructions recorded');
    }

    public function test_libation_renders_when_present(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertSee('Libation');
        $response->assertSee('Semi-sweet mead');
    }
}
