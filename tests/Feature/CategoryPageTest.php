<?php

declare(strict_types=1);

namespace Tests\Feature;

final class CategoryPageTest extends IndexedCorpusTestCase
{
    public function test_breads_category_lists_all_bread_recipes(): void
    {
        $response = $this->get('/categories/breads');
        $response->assertStatus(200);
        $response->assertSee('Breads');
        $response->assertSee('Honey Oat Bread');
        $response->assertSee('Big Soft Pretzels');
        $response->assertSee('Tortillas');
    }

    public function test_breads_recipes_appear_alphabetically_by_title(): void
    {
        $response = $this->get('/categories/breads');
        $body = $response->getContent();
        // "50/50 White…" (digits first) appears before "Big Soft Pretzels".
        $aPos = strpos($body, '50/50 White');
        $bPos = strpos($body, 'Big Soft Pretzels');
        $this->assertNotFalse($aPos);
        $this->assertNotFalse($bPos);
        $this->assertLessThan($bPos, $aPos, 'Recipes should be alphabetically ordered on the category page.');
    }

    public function test_unknown_category_returns_404(): void
    {
        $this->get('/categories/nonexistent')->assertStatus(404);
    }

    public function test_category_card_shows_unparsed_indicator(): void
    {
        $response = $this->get('/categories/breads');
        // Big Soft Pretzels has 6 unparsed lines per the corpus baseline.
        // The apostrophe in "couldn't" is plain template text (not Blade-escaped),
        // so we tell assertSee to skip its default HTML escaping.
        $response->assertSee("6 lines couldn't be auto-parsed", escape: false);
    }
}
