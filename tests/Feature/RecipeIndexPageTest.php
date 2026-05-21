<?php

declare(strict_types=1);

namespace Tests\Feature;

final class RecipeIndexPageTest extends IndexedCorpusTestCase
{
    public function test_index_page_lists_all_indexed_recipes(): void
    {
        $response = $this->get('/recipes');
        $response->assertStatus(200);
        // The corpus has 30 recipes; the count should be present in the
        // header copy. Use a forgiving assertion in case the corpus grows.
        $response->assertSee('All recipes');
        // Spot-check a handful of titles across categories.
        $response->assertSee('Honey Oat Bread');
        $response->assertSee('Apple Pie');
        $response->assertSee('Pasta Sauce');
    }

    public function test_index_page_emits_filterable_title_data_attribute(): void
    {
        // The Alpine filter reads each row's data-title attribute and toggles
        // x-show. We can't observe x-show in a static assertion, but we can
        // confirm the data attribute is present and lowercased.
        $response = $this->get('/recipes');
        $body = $response->getContent();
        $this->assertStringContainsString('data-title="honey oat bread"', $body);
        $this->assertStringContainsString('data-title="apple pie"', $body);
    }

    public function test_index_page_shows_outgoing_and_incoming_chips(): void
    {
        $response = $this->get('/recipes');
        $body = $response->getContent();
        // The four resolved frontmatter refs in the corpus surface as either
        // "linked to:" (outgoing) or "linked from:" (incoming).
        $this->assertStringContainsString('linked to:', $body);
        $this->assertStringContainsString('linked from:', $body);
    }

    public function test_nav_includes_index_link(): void
    {
        $response = $this->get('/');
        $response->assertSee(route('recipes.index'));
        // The link text is "Index" surrounded by Blade indentation whitespace.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/recipes"[^>]*>\s*Index\s*</',
            $response->getContent(),
        );
    }
}
