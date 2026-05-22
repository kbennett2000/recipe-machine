<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;

/**
 * Phase 11G — create-new flow feature tests.
 *
 * These tests write into the real recipes/ tree (bind-mounted from host).
 * Each test cleans up its own file in tearDown to avoid corrupting the
 * corpus across runs.
 */
final class RecipeCreateTest extends IndexedCorpusTestCase
{
    /** Files this test created — removed in tearDown to keep the corpus clean. */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function trackCreatedFile(string $slug, string $category): void
    {
        $this->createdFiles[] = base_path("recipes/{$category}/{$slug}.md");
    }

    private function validMarkdown(string $title = 'Test Sandwich Bread', string $category = 'breads'): string
    {
        return "---\ntitle: {$title}\ncategory: {$category}\n---\n\n## Ingredients\n\n- 3 cups flour\n- 1 tsp salt\n\n## Method\n\n1. Mix and bake.\n";
    }

    // === GET /recipes/new ===

    public function test_get_new_returns_200(): void
    {
        $this->get('/recipes/new')->assertStatus(200);
    }

    public function test_get_new_renders_empty_form(): void
    {
        $response = $this->get('/recipes/new');
        $response->assertStatus(200);
        $response->assertSee('New recipe');
        $response->assertSee('Title', escape: false);
        $response->assertSee('Category', escape: false);
        $response->assertSee('— pick a category —');
        // The category dropdown must include the existing on-disk categories.
        $response->assertSee('breads');
        $response->assertSee('desserts');
        // Slug field is editable (not the immutable pill from /edit).
        $response->assertDontSee('🔒 immutable');
    }

    public function test_get_new_includes_csrf_token(): void
    {
        $response = $this->get('/recipes/new');
        $response->assertSee('name="_token"', escape: false);
    }

    public function test_get_new_renders_isnew_flag_on_alpine_component(): void
    {
        $response = $this->get('/recipes/new');
        // The Alpine recipeEditor factory needs the isNew flag so the JS
        // wires up slug derivation and disables save-on-empty-fields.
        $response->assertSee('isNew: true', escape: false);
    }

    public function test_get_new_renders_slug_preview_markup(): void
    {
        $response = $this->get('/recipes/new');
        $response->assertSee('data-testid="slug-preview"', escape: false);
        $response->assertSee('derivedSlug()', escape: false);
    }

    public function test_get_new_includes_save_disabled_binding(): void
    {
        $response = $this->get('/recipes/new');
        // Submit button must be disabled until title AND category are filled.
        $response->assertSee('data-testid="save-new-recipe"', escape: false);
        $response->assertSee(':disabled=', escape: false);
    }

    // === POST /recipes/new happy path ===

    public function test_post_new_with_valid_markdown_creates_file_and_redirects(): void
    {
        $slug = 'create-test-bread';
        $this->trackCreatedFile($slug, 'breads');

        $markdown = $this->validMarkdown('Create Test Bread', 'breads');

        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertRedirect('/recipes/'.$slug);
        $response->assertSessionHas('success');

        // File exists on disk.
        $path = base_path("recipes/breads/{$slug}.md");
        $this->assertFileExists($path);

        // DB has the new recipe.
        $recipe = Recipe::query()->where('slug', $slug)->first();
        $this->assertNotNull($recipe);
        $this->assertSame('Create Test Bread', $recipe->title);
        $this->assertSame('breads', $recipe->category);
    }

    public function test_post_new_with_form_state_creates_file(): void
    {
        $slug = 'create-state-bread';
        $this->trackCreatedFile($slug, 'breads');

        $state = json_encode([
            'frontmatter' => [
                'title' => 'Create State Bread',
                'category' => 'breads',
                'slug' => null,
                'extra' => [],
            ],
            'ingredients' => [
                ['raw' => '3 cups flour', 'parsed' => true, 'amount' => 3, 'unit' => 'cup', 'ingredient' => 'flour', 'optional' => false],
            ],
            'method' => ['Mix and bake.'],
            'notes' => null,
            'libation_prose' => null,
            'cross_references' => [],
        ]);

        $response = $this->post('/recipes/new', ['state' => $state]);
        $response->assertRedirect('/recipes/'.$slug);

        $this->assertFileExists(base_path("recipes/breads/{$slug}.md"));
        $this->assertNotNull(Recipe::query()->where('slug', $slug)->first());
    }

    public function test_post_new_derives_slug_when_not_provided(): void
    {
        $slug = 'apple-cinnamon-loaf';
        $this->trackCreatedFile($slug, 'breads');

        $markdown = $this->validMarkdown('Apple Cinnamon Loaf', 'breads');
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertRedirect('/recipes/'.$slug);
        $this->assertFileExists(base_path("recipes/breads/{$slug}.md"));
    }

    public function test_post_new_writes_slug_into_frontmatter(): void
    {
        $slug = 'slug-written-bread';
        $this->trackCreatedFile($slug, 'breads');

        $markdown = $this->validMarkdown('Slug Written Bread', 'breads');
        $this->post('/recipes/new', ['markdown' => $markdown])->assertRedirect();

        $written = file_get_contents(base_path("recipes/breads/{$slug}.md"));
        $this->assertStringContainsString("slug: {$slug}", $written);
    }

    // === POST /recipes/new error paths ===

    public function test_post_new_with_missing_title_fails(): void
    {
        // Parser catches missing-title before our controller-level check.
        // Either path is fine; the user-visible message just has to mention "title".
        $markdown = "---\ntitle:\ncategory: breads\n---\n\n## Ingredients\n";
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertSessionHasErrors('save');
        $error = strtolower((string) session('errors')->first('save'));
        $this->assertStringContainsString('title', $error);
    }

    public function test_post_new_with_missing_category_fails(): void
    {
        $markdown = "---\ntitle: No Category Recipe\ncategory:\n---\n\n## Ingredients\n";
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertSessionHasErrors('save');
        $error = strtolower((string) session('errors')->first('save'));
        $this->assertStringContainsString('category', $error);
    }

    public function test_post_new_with_form_state_missing_title_fails(): void
    {
        // Form-mode path: state is a JSON object. The parser is bypassed in
        // favor of ParsedRecipe::fromArray, which doesn't enforce required
        // fields — the controller's own validation catches the empty title.
        $state = json_encode([
            'frontmatter' => [
                'title' => '',
                'category' => 'breads',
                'slug' => null,
                'extra' => [],
            ],
            'ingredients' => [],
            'method' => [],
        ]);
        $response = $this->post('/recipes/new', ['state' => $state]);

        $response->assertSessionHasErrors('save');
        $this->assertStringContainsString(
            'Title is required',
            (string) session('errors')->first('save'),
        );
    }

    public function test_post_new_with_form_state_missing_category_fails(): void
    {
        $state = json_encode([
            'frontmatter' => [
                'title' => 'Some Title',
                'category' => '',
                'slug' => null,
                'extra' => [],
            ],
            'ingredients' => [],
            'method' => [],
        ]);
        $response = $this->post('/recipes/new', ['state' => $state]);

        $response->assertSessionHasErrors('save');
        $this->assertStringContainsString(
            'Category is required',
            (string) session('errors')->first('save'),
        );
    }

    public function test_post_new_with_nonexistent_category_fails(): void
    {
        $markdown = "---\ntitle: Phantom\ncategory: not-a-real-category\n---\n\n## Ingredients\n";
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertSessionHasErrors('save');
        $error = (string) session('errors')->first('save');
        $this->assertStringContainsString('not-a-real-category', $error);
    }

    public function test_post_new_with_colliding_slug_fails(): void
    {
        // honey-oat-bread is in the seeded corpus.
        $markdown = "---\ntitle: Conflict\ncategory: breads\nslug: honey-oat-bread\n---\n\n## Ingredients\n";
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);

        $response->assertSessionHasErrors('save');
        $error = (string) session('errors')->first('save');
        $this->assertStringContainsString('honey-oat-bread', $error);
        $this->assertStringContainsString('already', strtolower($error));
    }

    public function test_post_new_with_malformed_yaml_fails(): void
    {
        $markdown = "---\ntitle: Broken: yaml: here\n - invalid\n---\n\nbody\n";
        $response = $this->post('/recipes/new', ['markdown' => $markdown]);
        $response->assertSessionHasErrors('save');
    }

    // === Nav integration ===

    public function test_nav_contains_new_recipe_link(): void
    {
        $response = $this->get('/');
        $response->assertSee('data-testid="nav-new-recipe"', escape: false);
        $response->assertSee('/recipes/new', escape: false);
    }

    public function test_home_page_has_prominent_new_recipe_link(): void
    {
        $response = $this->get('/');
        $response->assertSee('data-testid="home-new-recipe"', escape: false);
    }
}
