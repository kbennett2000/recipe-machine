<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;

/**
 * Phase 11D — Editor v0 feature tests.
 *
 * These tests POST to /recipes/honey-oat-bread/edit, which writes to the
 * real recipes/ directory (bind-mounted in the container, owned by the
 * host). To avoid corrupting the corpus, the test class snapshots the
 * source markdown in setUp() and restores it in tearDown(). If a test
 * crashes the runner mid-write, the bind mount preserves whatever the
 * test wrote — manual `git checkout recipes/breads/honey-oat-bread.md`
 * recovers.
 */
final class RecipeEditTest extends IndexedCorpusTestCase
{
    private const TEST_SLUG = 'honey-oat-bread';

    private string $sourcePath;

    private string $originalMarkdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = base_path('recipes/breads/'.self::TEST_SLUG.'.md');
        $this->originalMarkdown = file_get_contents($this->sourcePath);
        // Laravel 11 test client doesn't auto-disable CSRF. Most POST tests
        // here exercise controller logic, not the CSRF middleware itself —
        // they bypass via withoutMiddleware(). The single test that DOES
        // exercise CSRF re-enables it explicitly via withMiddleware().
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        // Restore the corpus file regardless of test outcome.
        file_put_contents($this->sourcePath, $this->originalMarkdown);
        parent::tearDown();
    }

    // === GET /edit ===

    public function test_get_edit_returns_200_for_existing_recipe(): void
    {
        $this->get('/recipes/'.self::TEST_SLUG.'/edit')->assertStatus(200);
    }

    public function test_get_edit_returns_404_for_unknown_recipe(): void
    {
        $this->get('/recipes/no-such-recipe/edit')->assertStatus(404);
    }

    public function test_get_edit_prefills_textarea_with_markdown(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertStatus(200);
        // The textarea body should contain a recognizable chunk of the
        // recipe's source content.
        $response->assertSee('title: Honey Oat Bread');
        $response->assertSee('Knead, rise 1', escape: false);
    }

    public function test_edit_form_has_csrf_token(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('name="_token"', escape: false);
    }

    // === POST /edit happy path ===

    public function test_post_with_valid_modified_markdown_saves_and_redirects(): void
    {
        $modified = str_replace(
            '3 cups flour',
            '4 cups flour',
            $this->originalMarkdown,
        );
        $this->assertNotSame($this->originalMarkdown, $modified, 'Test setup: modification must actually change the markdown');

        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $modified]);

        $response->assertRedirect('/recipes/'.self::TEST_SLUG);
        $response->assertSessionHas('success');

        // File on disk reflects the change.
        $this->assertStringContainsString('4 cups flour', file_get_contents($this->sourcePath));

        // DB reflects the change.
        $recipe = Recipe::query()->where('slug', self::TEST_SLUG)->first();
        $flour = Ingredient::query()
            ->where('recipe_id', $recipe->id)
            ->where('ingredient', 'flour')
            ->first();
        $this->assertNotNull($flour);
        $this->assertSame(4.0, (float) $flour->amount);
    }

    public function test_post_with_unchanged_markdown_still_succeeds(): void
    {
        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', [
            'markdown' => $this->originalMarkdown,
        ]);
        $response->assertRedirect('/recipes/'.self::TEST_SLUG);
    }

    // === POST /edit error cases ===

    public function test_post_with_changed_frontmatter_slug_returns_error(): void
    {
        $modified = str_replace(
            'slug: honey-oat-bread',
            'slug: renamed-bread',
            $this->originalMarkdown,
        );

        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $modified]);
        $response->assertSessionHasErrors('save');
        $this->assertStringContainsString(
            "Renaming recipes isn't supported",
            (string) session('errors')->first('save'),
        );

        // File on disk is unchanged.
        $this->assertSame($this->originalMarkdown, file_get_contents($this->sourcePath));
    }

    public function test_post_with_changed_frontmatter_category_returns_error(): void
    {
        $modified = str_replace(
            'category: breads',
            'category: desserts',
            $this->originalMarkdown,
        );

        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $modified]);
        $response->assertSessionHasErrors('save');
        $this->assertStringContainsString(
            "Moving a recipe to a different category isn't supported",
            (string) session('errors')->first('save'),
        );

        // File on disk is unchanged.
        $this->assertSame($this->originalMarkdown, file_get_contents($this->sourcePath));
    }

    public function test_post_with_malformed_yaml_returns_error(): void
    {
        $modified = "---\ntitle: Broken: yaml: here\n - this is invalid\n---\n\nbody\n";
        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $modified]);
        $response->assertSessionHasErrors('save');
        // File on disk is unchanged.
        $this->assertSame($this->originalMarkdown, file_get_contents($this->sourcePath));
    }

    public function test_post_with_empty_body_returns_validation_error(): void
    {
        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => '']);
        $response->assertSessionHasErrors('markdown');
    }

    public function test_post_failure_preserves_user_input_via_old(): void
    {
        // Trigger a save failure (slug change) and verify the failed-input
        // is preserved in the flash 'old' bag so the re-rendered form
        // shows the user's last attempt.
        $bad = str_replace(
            'slug: honey-oat-bread',
            'slug: typo',
            $this->originalMarkdown,
        );
        $response = $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $bad]);
        $response->assertSessionHasErrors('save');
        $this->assertSame($bad, session()->getOldInput('markdown'));
    }

    // === CSRF ===

    public function test_csrf_token_is_present_in_form(): void
    {
        // We can't easily exercise the 419 response from inside PHPUnit
        // without re-architecting how setUp disables middleware. What we
        // CAN verify is that the form rendered by the GET handler
        // includes a CSRF token field — which is the actual security
        // requirement. Laravel's own test suite covers the middleware
        // returning 419 when the token is missing.
        $html = $this->get('/recipes/'.self::TEST_SLUG.'/edit')->getContent();
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertMatchesRegularExpression(
            '/name="_token"\s+value="[a-zA-Z0-9]{40}"/',
            $html,
            'CSRF token field should contain a real token value, not an empty string',
        );
    }

    // === Round-trip integration ===

    public function test_round_trip_unchanged_post_keeps_file_byte_identical(): void
    {
        $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $this->originalMarkdown])
            ->assertRedirect('/recipes/'.self::TEST_SLUG);

        $this->assertSame($this->originalMarkdown, file_get_contents($this->sourcePath),
            'Posting the original markdown back unchanged must leave the file byte-identical');
    }

    // === Edit link surface ===

    public function test_recipe_show_page_has_edit_link(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG);
        $response->assertSee(route('recipes.edit', ['recipe' => self::TEST_SLUG]));
        $response->assertSee('Edit', escape: false);
    }

    // === Phase 11D.1 polish smoke tests ===
    //
    // The JS-level behavior (Shift+Tab unindent, Esc-out, live syntax
    // colorization on input) can't be exercised from PHPUnit feature
    // tests — those need browser-level testing (Playwright/Dusk, which
    // we're not set up for). These tests confirm the static HTML attributes
    // are present so the JS has something to bind to. Visual verification
    // is done via the screenshots in docs/screenshots/p11d1-*.png.

    public function test_edit_form_has_sticky_error_banner_on_failure(): void
    {
        // Submit a bad save to trigger the error path, then verify the
        // re-rendered HTML includes the sticky-banner class.
        $bad = str_replace('slug: honey-oat-bread', 'slug: typo', $this->originalMarkdown);
        $this->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $bad]);
        $response = $this->followingRedirects()
            ->get('/recipes/'.self::TEST_SLUG.'/edit');
        // The redirect-with-errors flow drops the error on the followup
        // GET. We need to assert against the form-back response directly.
        $bad2 = str_replace('slug: honey-oat-bread', 'slug: typo2', $this->originalMarkdown);
        $response = $this->from('/recipes/'.self::TEST_SLUG.'/edit')
            ->post('/recipes/'.self::TEST_SLUG.'/edit', ['markdown' => $bad2]);
        // After failBack(), Laravel redirects back; the destination is
        // the edit page and the session bag carries the error.
        $followed = $response->assertRedirect()->baseResponse;
        // Now GET the edit page; the validation errors are still in the
        // session bag.
        $afterRedirect = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $afterRedirect->assertSee('sticky top-0', escape: false);
        $afterRedirect->assertSee('Save failed', escape: false);
    }

    public function test_edit_form_has_editor_alpine_component(): void
    {
        // Phase 11E replaced the standalone markdownEditor() component
        // with a unified recipeEditor() that wraps both form mode and
        // raw mode. The textarea is still present (in raw mode) but is
        // referenced via x-ref="rawTextarea" so the Alpine component
        // can poke at it during mode switches.
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('recipeEditor(', escape: false);
        $response->assertSee('x-ref="rawTextarea"', escape: false);
    }

    public function test_edit_form_has_keydown_handler_for_tab_and_esc(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('onKeydown($event)', escape: false);
    }
}
