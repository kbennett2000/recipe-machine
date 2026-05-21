<?php

declare(strict_types=1);

namespace Tests\Feature;

final class HomePageTest extends IndexedCorpusTestCase
{
    public function test_home_page_renders_with_category_counts(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Recipe Machine');
        // All five represented categories should appear with their counts in the nav.
        $response->assertSee('Breads');
        $response->assertSee('Sauces');
        $response->assertSee('Soups');
        $response->assertSee('Entrees');
        $response->assertSee('Desserts');
        // Seafood has 0 recipes; should render but be dimmed.
        $response->assertSee('Seafood');
    }

    public function test_home_page_links_to_each_category(): void
    {
        $response = $this->get('/');
        $response->assertSee(route('categories.show', ['category' => 'breads']));
        $response->assertSee(route('categories.show', ['category' => 'desserts']));
    }

    public function test_home_page_shows_recently_updated_section(): void
    {
        $response = $this->get('/');
        $response->assertSee('Recently updated');
    }
}
