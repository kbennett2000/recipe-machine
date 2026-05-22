<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeReference;

/**
 * Phase 11G — delete flow feature tests.
 *
 * These tests delete from the real bind-mounted corpus, so each test
 * snapshots the markdown of the recipe it's about to remove and restores
 * it in tearDown. The DB delete is automatically rolled back by
 * RefreshDatabase.
 */
final class RecipeDeleteTest extends IndexedCorpusTestCase
{
    private const TEST_SLUG = 'honey-oat-bread';

    private string $sourcePath;

    private string $originalMarkdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = base_path('recipes/breads/'.self::TEST_SLUG.'.md');
        $this->originalMarkdown = file_get_contents($this->sourcePath);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        // Restore the file regardless of test outcome.
        if (! is_file($this->sourcePath)) {
            file_put_contents($this->sourcePath, $this->originalMarkdown);
        }
        parent::tearDown();
    }

    // === Delete link on /edit ===

    public function test_edit_page_shows_delete_link(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('data-testid="delete-recipe-link"', escape: false);
        $response->assertSee('Delete recipe');
    }

    public function test_edit_page_shows_delete_confirm_dialog_markup(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('data-testid="delete-confirm-dialog"', escape: false);
        $response->assertSee('Delete permanently');
        $response->assertSee('cannot be undone except via git');
    }

    // === POST /delete happy path ===

    public function test_post_delete_removes_file_and_redirects(): void
    {
        $this->assertFileExists($this->sourcePath);

        $response = $this->post('/recipes/'.self::TEST_SLUG.'/delete');

        $response->assertRedirect('/categories/breads');
        $response->assertSessionHas('success');

        // File is gone.
        $this->assertFileDoesNotExist($this->sourcePath);

        // DB row is gone.
        $this->assertNull(Recipe::query()->where('slug', self::TEST_SLUG)->first());
    }

    public function test_post_delete_flash_message_includes_title(): void
    {
        $title = Recipe::query()->where('slug', self::TEST_SLUG)->value('title');
        $this->post('/recipes/'.self::TEST_SLUG.'/delete');

        $this->assertStringContainsString($title, (string) session('success'));
    }

    public function test_post_delete_for_unknown_slug_returns_404(): void
    {
        $response = $this->post('/recipes/no-such-recipe/delete');
        $response->assertStatus(404);
    }

    public function test_after_delete_recipe_show_page_returns_404(): void
    {
        $this->post('/recipes/'.self::TEST_SLUG.'/delete')->assertRedirect();
        $this->get('/recipes/'.self::TEST_SLUG)->assertStatus(404);
    }

    public function test_after_delete_inbound_references_become_unresolved(): void
    {
        // Set up an inbound cross-ref: another recipe references honey-oat-bread.
        $referrer = Recipe::query()->where('slug', '!=', self::TEST_SLUG)->first();
        $this->assertNotNull($referrer);

        $target = Recipe::query()->where('slug', self::TEST_SLUG)->first();
        $this->assertNotNull($target);

        RecipeReference::create([
            'recipe_id' => $referrer->id,
            'referenced_slug' => self::TEST_SLUG,
            'resolved_recipe_id' => $target->id,
            'source' => 'inline',
        ]);

        $this->post('/recipes/'.self::TEST_SLUG.'/delete')->assertRedirect();

        // The reference still exists (history preserved) but is now unresolved.
        $ref = RecipeReference::query()
            ->where('recipe_id', $referrer->id)
            ->where('referenced_slug', self::TEST_SLUG)
            ->first();
        $this->assertNotNull($ref);
        $this->assertNull($ref->resolved_recipe_id);
    }
}
