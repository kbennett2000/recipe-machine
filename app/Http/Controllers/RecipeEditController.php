<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Display\MethodFormatter;
use App\Recipes\Files\RecipeFileWriter;
use App\Recipes\Indexing\RecipeReindexer;
use App\Recipes\LLM\IngredientLLMParser;
use App\Recipes\Parser\IngredientParser;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Serializer\RecipeSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 11D + 11E — recipe editor controller.
 *
 * GET  /recipes/{slug}/edit         — render the editor (form mode default).
 * POST /recipes/{slug}/edit         — save (accepts `markdown` for raw
 *                                     mode OR `state` JSON for form mode).
 * POST /recipes/{slug}/edit/parse   — markdown → JSON state (raw → form).
 * POST /recipes/{slug}/edit/serialize — JSON state → markdown (form → raw).
 * POST /recipes/{slug}/edit/preview — markdown → rendered HTML (live preview).
 *
 * Architectural note (Phase 11E): all state transformations (parse,
 * serialize, render) live server-side. The form-mode JS only collects
 * state into a reactive object and calls these endpoints when it needs
 * markdown or HTML. This avoids a JS twin of RecipeSerializer/Parser
 * — the trade-off is one network round-trip per mode toggle or
 * preview render, which is invisible on LAN.
 */
final class RecipeEditController extends Controller
{
    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly RecipeSerializer $serializer = new RecipeSerializer,
        private readonly RecipeReindexer $reindexer = new RecipeReindexer,
        private readonly IngredientFormatter $ingredientFormatter = new IngredientFormatter,
        private readonly MethodFormatter $methodFormatter = new MethodFormatter,
        private readonly IngredientParser $ingredientParser = new IngredientParser,
        private readonly IngredientLLMParser $llmParser = new IngredientLLMParser,
    ) {}

    public function edit(Recipe $recipe): View
    {
        $absolutePath = base_path((string) $recipe->source_path);
        if (! is_file($absolutePath)) {
            abort(404, "Markdown file missing on disk for recipe {$recipe->slug} (expected at {$absolutePath}).");
        }
        $markdown = file_get_contents($absolutePath);
        if ($markdown === false) {
            abort(500, "Could not read markdown for {$recipe->slug}.");
        }

        // Parse the on-disk markdown into the initial state shape the
        // form mode hydrates from. If parsing fails (the user
        // hand-edited the file into an invalid state), we still render
        // — the form falls back gracefully and the raw textarea remains
        // editable.
        try {
            $parsed = $this->parser->parseString($markdown);
            $initialState = $parsed->toArray();
        } catch (Throwable) {
            $initialState = null;
        }

        $categories = $this->availableCategories();
        // Phase 11H — capture the file's mtime at render time so the
        // editor can poll for out-of-band changes and warn the user.
        $initialMtime = filemtime($absolutePath) ?: 0;

        return view('recipes.edit', [
            'recipe' => $recipe,
            'markdown' => $markdown,
            'initialState' => $initialState,
            'categories' => $categories,
            'initialMtime' => $initialMtime,
        ]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        // Two save paths: form mode posts JSON `state`, raw mode posts
        // `markdown`. Either one ends up as a markdown string that goes
        // through the same writer + reindexer.
        $markdown = null;
        if ($request->filled('state')) {
            try {
                $stateArray = json_decode((string) $request->input('state'), true, flags: JSON_THROW_ON_ERROR);
                $parsed = ParsedRecipe::fromArray((array) $stateArray);
                $markdown = $this->serializer->serialize($parsed);
            } catch (Throwable $e) {
                return $this->failBack($request->input('markdown', ''),
                    "Couldn't serialize the form state: {$e->getMessage()}");
            }
        } else {
            $validated = $request->validate(['markdown' => 'required|string']);
            $markdown = str_replace("\r\n", "\n", $validated['markdown']);
        }

        // Re-parse the resulting markdown (whether it came from the form
        // path or the textarea path) — fail closed if it's malformed.
        try {
            $parsed = $this->parser->parseString($markdown);
        } catch (Throwable $e) {
            return $this->failBack($markdown, "Couldn't parse the markdown: {$e->getMessage()}");
        }

        // Slug-immutability check.
        $bodySlug = $parsed->frontmatter->slug;
        if ($bodySlug !== null && $bodySlug !== '' && $bodySlug !== $recipe->slug) {
            return $this->failBack($markdown, sprintf(
                "Renaming recipes isn't supported in the editor. The frontmatter slug ('%s') must match the URL slug ('%s').",
                $bodySlug, $recipe->slug,
            ));
        }

        // Category-immutability check.
        if ($parsed->frontmatter->category !== $recipe->category) {
            return $this->failBack($markdown, sprintf(
                "Moving a recipe to a different category isn't supported here. The frontmatter category ('%s') must match the recipe's current category ('%s').",
                $parsed->frontmatter->category, $recipe->category,
            ));
        }

        try {
            $this->makeWriter()->write($recipe->slug, $recipe->category, $markdown);
        } catch (Throwable $e) {
            return $this->failBack($markdown, "Save failed: {$e->getMessage()}");
        }

        try {
            $this->reindexer->reindexOne($recipe->slug);
        } catch (Throwable $e) {
            return $this->failBack($markdown,
                "File saved, but the search/cache update failed: {$e->getMessage()}. Run `php artisan recipes:reindex` to recover.");
        }

        return redirect()
            ->route('recipes.show', ['recipe' => $recipe->slug])
            ->with('success', "Saved {$recipe->title}.");
    }

    /**
     * POST /recipes/{slug}/edit/parse — markdown → JSON state.
     * Used when toggling from raw mode to form mode.
     */
    public function parse(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate(['markdown' => 'required|string']);
        $markdown = str_replace("\r\n", "\n", $validated['markdown']);
        try {
            $parsed = $this->parser->parseString($markdown);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json($parsed->toArray());
    }

    /**
     * POST /recipes/{slug}/edit/serialize — JSON state → markdown.
     * Used when toggling from form mode to raw mode.
     */
    public function serialize(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate(['state' => 'required|string']);
        try {
            $stateArray = json_decode($validated['state'], true, flags: JSON_THROW_ON_ERROR);
            $parsed = ParsedRecipe::fromArray((array) $stateArray);
            $markdown = $this->serializer->serialize($parsed);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['markdown' => $markdown]);
    }

    /**
     * POST /recipes/{slug}/edit/preview — markdown → rendered HTML
     * partial. Used by the live preview pane. Accepts either
     * `markdown` (textarea content from raw mode) or `state` (JSON
     * from form mode).
     */
    public function preview(Request $request, Recipe $recipe): JsonResponse
    {
        try {
            if ($request->filled('state')) {
                $stateArray = json_decode((string) $request->input('state'), true, flags: JSON_THROW_ON_ERROR);
                $parsed = ParsedRecipe::fromArray((array) $stateArray);
            } else {
                $request->validate(['markdown' => 'required|string']);
                $markdown = str_replace("\r\n", "\n", (string) $request->input('markdown'));
                $parsed = $this->parser->parseString($markdown);
            }
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Render the preview partial — a stripped-down recipe detail
        // body keyed off the supplied state, not the DB.
        $html = view('recipes._preview', [
            'parsed' => $parsed,
            'ingredientFormatter' => $this->ingredientFormatter,
            'methodFormatter' => $this->methodFormatter,
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * Phase 11H — GET /recipes/{slug}/edit/mtime.
     *
     * Returns the current filesystem mtime as a Unix timestamp. The
     * editor polls this every ~10s and compares against the value
     * captured at page load. A mismatch means the file changed
     * out-of-band (another editor, git pull, etc.) and the user should
     * know before they save and clobber the new version.
     */
    public function mtime(Recipe $recipe): JsonResponse
    {
        $absolutePath = base_path((string) $recipe->source_path);
        if (! is_file($absolutePath)) {
            return response()->json(['error' => 'Recipe file missing on disk.'], 404);
        }
        $mtime = filemtime($absolutePath);
        if ($mtime === false) {
            return response()->json(['error' => 'Could not read mtime.'], 500);
        }
        return response()->json(['mtime' => $mtime]);
    }

    /**
     * Phase 11H — POST /recipes/{slug}/edit/parse-line.
     *
     * Parse a single raw line into structured ingredient fields. Tries
     * the rules-based parser first, then falls back to the LLM cache
     * (no live API call — interactive use shouldn't burn credits), and
     * finally returns a best-effort fallback row with the raw line
     * dropped into the ingredient field.
     *
     * Returns the same shape ParsedIngredient::toArray emits, plus a
     * `source` field the UI uses to show a "✨" / "⚠ best-effort"
     * indicator on the new row.
     */
    public function parseLine(Request $request, Recipe $recipe): JsonResponse
    {
        $validated = $request->validate(['line' => 'required|string']);
        $line = trim((string) $validated['line']);
        if ($line === '') {
            return response()->json(['error' => 'Line is empty.'], 422);
        }

        $rules = $this->ingredientParser->parseLine($line);
        if ($rules->parsed) {
            return response()->json(array_merge($rules->toArray(), ['source' => 'rules']));
        }

        $llm = $this->llmParser->parseLineFromCache($line);
        if ($llm !== null && $llm->parsed) {
            return response()->json(array_merge($llm->toArray(), ['source' => 'llm']));
        }

        // Fallback: surface the line as the ingredient name so the user
        // has a structured row to edit instead of nothing.
        return response()->json([
            'raw' => $line,
            'parsed' => false,
            'amount' => null,
            'amount_high' => null,
            'unit' => null,
            'ingredient' => $line,
            'modifier' => null,
            'note' => null,
            'optional' => false,
            'group' => null,
            'source' => 'fallback',
        ]);
    }

    /**
     * Phase 11G — POST /recipes/{slug}/delete.
     *
     * Removes the .md file from disk, drops the recipe from the index,
     * and redirects to the category listing the recipe lived in. Inbound
     * cross-references become unresolved per RecipeReindexer::remove().
     */
    public function destroy(Recipe $recipe): RedirectResponse
    {
        $title = $recipe->title;
        $category = $recipe->category;
        $slug = $recipe->slug;

        try {
            $this->makeWriter()->delete($slug);
        } catch (Throwable $e) {
            return back()->withErrors(['save' => "Couldn't delete file: {$e->getMessage()}"]);
        }

        try {
            $this->reindexer->remove($slug);
        } catch (Throwable $e) {
            return back()->withErrors(['save' =>
                "File removed, but the search/cache update failed: {$e->getMessage()}. Run `php artisan recipes:reindex` to recover."]);
        }

        return redirect()
            ->route('categories.show', ['category' => $category])
            ->with('success', "Deleted '{$title}'.");
    }

    /**
     * Return the sorted list of categories available on disk —
     * subdirectories of recipes/ that contain at least one .md file.
     * Powers the category dropdown in form mode.
     *
     * @return list<string>
     */
    private function availableCategories(): array
    {
        return Cache::remember('editor:categories', now()->addMinutes(5), function (): array {
            $root = base_path('recipes');
            if (! is_dir($root)) {
                return [];
            }
            $out = [];
            foreach ((array) scandir($root) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($root.'/'.$entry)) {
                    $out[] = $entry;
                }
            }
            sort($out);
            return $out;
        });
    }

    private function failBack(string $markdown, string $error): RedirectResponse
    {
        return back()
            ->withInput(['markdown' => $markdown])
            ->withErrors(['save' => $error]);
    }

    private function makeWriter(): RecipeFileWriter
    {
        return new RecipeFileWriter(base_path('recipes'));
    }
}
