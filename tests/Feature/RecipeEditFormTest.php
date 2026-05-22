<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Serializer\RecipeSerializer;

/**
 * Phase 11E — form-mode editor tests.
 *
 * Covers:
 *   - GET /edit renders form mode by default with the recipe state
 *     serialized into a data attribute for Alpine to hydrate.
 *   - POST /edit accepts a JSON `state` body in addition to `markdown`
 *     (the form-mode save path).
 *   - POST /edit/parse round-trips a markdown body to ParsedRecipe JSON.
 *   - POST /edit/serialize round-trips state JSON to markdown.
 *   - POST /edit/preview returns an HTML partial from either body shape.
 *
 * As with RecipeEditTest, the corpus markdown is snapshotted in setUp
 * and restored in tearDown so cross-test contamination is impossible.
 */
final class RecipeEditFormTest extends IndexedCorpusTestCase
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
        file_put_contents($this->sourcePath, $this->originalMarkdown);
        parent::tearDown();
    }

    // === GET /edit (form mode hydration) ===

    public function test_edit_page_serializes_initial_state_into_data_attr(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertStatus(200);
        // The Alpine x-init call carries the JSON-encoded initial state.
        // We just verify some expected substrings exist; the full JSON
        // is huge and fragile to assert against literally.
        $html = $response->getContent();
        $this->assertStringContainsString('recipeEditor(', $html);
        $this->assertStringContainsString('Honey Oat Bread', $html);
    }

    public function test_edit_page_renders_form_and_raw_mode_toggle(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $html = $response->getContent();
        $this->assertStringContainsString('switchMode(\'form\')', $html);
        $this->assertStringContainsString('switchMode(\'raw\')', $html);
    }

    public function test_edit_page_renders_form_sections_and_preview_pane(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $html = $response->getContent();
        // Form section headers
        $this->assertStringContainsString('Title &amp; category', $html);
        $this->assertStringContainsString('Times &amp; metadata', $html);
        $this->assertStringContainsString('>Ingredients<', $html);
        $this->assertStringContainsString('>Method<', $html);
        // Preview pane.
        $this->assertStringContainsString('Live preview', $html);
        // Sortable handle marker.
        $this->assertStringContainsString('drag-handle', $html);
    }

    // === POST /edit/parse ===

    public function test_parse_endpoint_returns_json_state(): void
    {
        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => $this->originalMarkdown,
        ]);
        $r->assertStatus(200);
        $r->assertJsonStructure(['frontmatter', 'ingredients', 'method', 'notes']);
        $r->assertJsonPath('frontmatter.title', 'Honey Oat Bread');
        $r->assertJsonPath('frontmatter.category', 'breads');
    }

    public function test_parse_endpoint_422s_on_malformed_yaml(): void
    {
        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => "---\ntitle: Broken: yaml: here\n - this is invalid\n---\n",
        ]);
        $r->assertStatus(422);
        $r->assertJsonStructure(['error']);
    }

    // === POST /edit/serialize ===

    public function test_serialize_endpoint_returns_markdown_for_state(): void
    {
        // Get a fresh state from /parse, then send it back through /serialize.
        $parsed = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => $this->originalMarkdown,
        ])->json();

        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/serialize', [
            'state' => json_encode($parsed),
        ]);
        $r->assertStatus(200);
        $r->assertJsonStructure(['markdown']);
        $markdown = $r->json('markdown');
        // The serializer should produce something that the parser will
        // accept back without error.
        $reparsed = (new RecipeParser)->parseString($markdown);
        $this->assertSame('Honey Oat Bread', $reparsed->frontmatter->title);
    }

    // === POST /edit/preview ===

    public function test_preview_endpoint_returns_html_from_markdown(): void
    {
        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/preview', [
            'markdown' => $this->originalMarkdown,
        ]);
        $r->assertStatus(200);
        $r->assertJsonStructure(['html']);
        $html = $r->json('html');
        $this->assertStringContainsString('Honey Oat Bread', $html);
        $this->assertStringContainsString('Ingredients', $html);
        $this->assertStringContainsString('Method', $html);
    }

    public function test_preview_endpoint_returns_html_from_state(): void
    {
        $parsed = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => $this->originalMarkdown,
        ])->json();

        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/preview', [
            'state' => json_encode($parsed),
        ]);
        $r->assertStatus(200);
        $html = $r->json('html');
        $this->assertStringContainsString('Honey Oat Bread', $html);
    }

    // === POST /edit save (form mode) ===

    public function test_save_accepts_form_state_and_writes_markdown(): void
    {
        // Parse → mutate one ingredient amount → re-post as state.
        $parsed = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => $this->originalMarkdown,
        ])->json();

        // Replace "3 cups flour" with "4 cups flour" via the structured state.
        foreach ($parsed['ingredients'] as &$ing) {
            if (($ing['ingredient'] ?? null) === 'flour') {
                $ing['amount'] = 4;
            }
        }
        unset($ing);

        $r = $this->post('/recipes/'.self::TEST_SLUG.'/edit', [
            'state' => json_encode($parsed),
        ]);
        $r->assertRedirect('/recipes/'.self::TEST_SLUG);
        $r->assertSessionHas('success');

        // File reflects the change.
        $written = file_get_contents($this->sourcePath);
        $this->assertStringContainsString('4 cups flour', $written);
    }

    public function test_round_trip_through_form_state_preserves_structure(): void
    {
        // Parse, then immediately re-serialize via the controller. The
        // resulting markdown should re-parse to a structurally equivalent
        // recipe. (Some YAML quoting cosmetics may differ but the structured
        // fields must match.)
        $parsed = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/parse', [
            'markdown' => $this->originalMarkdown,
        ])->json();

        $r = $this->postJson('/recipes/'.self::TEST_SLUG.'/edit/serialize', [
            'state' => json_encode($parsed),
        ]);
        $serialized = $r->json('markdown');

        $reparsed = (new RecipeParser)->parseString($serialized);
        $this->assertSame($parsed['frontmatter']['title'], $reparsed->frontmatter->title);
        $this->assertSame($parsed['frontmatter']['category'], $reparsed->frontmatter->category);
        $this->assertCount(count($parsed['ingredients']), $reparsed->ingredients);
        $this->assertSame($parsed['method'], $reparsed->method);
    }
}
