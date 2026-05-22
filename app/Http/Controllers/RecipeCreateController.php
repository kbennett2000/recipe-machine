<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Display\MethodFormatter;
use App\Recipes\Files\RecipeFileWriter;
use App\Recipes\Indexing\RecipeReindexer;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Serializer\RecipeSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 11G — create-new flow.
 *
 * GET  /recipes/new — render the form with a blank initial state.
 * POST /recipes/new — write a brand-new markdown file, reindex, redirect
 *                     to the new recipe's detail page.
 *
 * Slug, category and title are all required at submit time. Pre-save the
 * slug auto-derives from the title (Str::slug). After the first save the
 * recipe lives at a stable URL and slug-immutability kicks in via the
 * existing edit controller.
 */
final class RecipeCreateController extends Controller
{
    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly RecipeSerializer $serializer = new RecipeSerializer,
        private readonly RecipeReindexer $reindexer = new RecipeReindexer,
        private readonly IngredientFormatter $ingredientFormatter = new IngredientFormatter,
        private readonly MethodFormatter $methodFormatter = new MethodFormatter,
    ) {}

    public function create(): View
    {
        return view('recipes.new', [
            'categories' => $this->availableCategories(),
            'initialState' => $this->blankState(),
            'scaffoldMarkdown' => $this->scaffoldMarkdown(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Form mode posts JSON `state`; raw mode posts `markdown`. We
        // pre-validate the required title + category fields at whichever
        // surface the user posted from, so the error message points back
        // at the form input rather than the parser's internal language.
        $markdown = null;
        if ($request->filled('state')) {
            try {
                $stateArray = json_decode((string) $request->input('state'), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return $this->failBack($request, "Couldn't decode the form state: {$e->getMessage()}");
            }

            // Required-field check before the parser sees it — the parser's
            // missing-frontmatter error message is correct but jargony.
            $title = trim((string) ($stateArray['frontmatter']['title'] ?? ''));
            $category = trim((string) ($stateArray['frontmatter']['category'] ?? ''));
            if ($title === '') {
                return $this->failBack($request, 'Title is required.');
            }
            if ($category === '') {
                return $this->failBack($request, 'Category is required.');
            }

            try {
                $parsed = ParsedRecipe::fromArray((array) $stateArray);
                $markdown = $this->serializer->serialize($parsed);
            } catch (Throwable $e) {
                return $this->failBack($request, "Couldn't serialize the form state: {$e->getMessage()}");
            }
        } else {
            $validated = $request->validate(['markdown' => 'required|string']);
            $markdown = str_replace("\r\n", "\n", $validated['markdown']);
        }

        // Re-parse the resulting markdown so validation runs on the
        // canonical shape, no matter which mode the user submitted from.
        try {
            $parsed = $this->parser->parseString($markdown);
        } catch (Throwable $e) {
            return $this->failBack($request, "Couldn't parse the markdown: {$e->getMessage()}", $markdown);
        }

        $title = trim($parsed->frontmatter->title);
        $category = trim($parsed->frontmatter->category);

        if ($title === '') {
            return $this->failBack($request, 'Title is required.', $markdown);
        }
        if ($category === '') {
            return $this->failBack($request, 'Category is required.', $markdown);
        }

        $slug = $parsed->frontmatter->slug;
        if ($slug === null || $slug === '') {
            $slug = Str::slug($title);
        }
        if ($slug === '') {
            return $this->failBack($request, 'Could not derive a usable slug from the title — pick a slug manually.', $markdown);
        }

        $writer = $this->makeWriter();
        if ($writer->exists($slug)) {
            return $this->failBack($request, "Slug '{$slug}' is already taken. Pick a different one.", $markdown);
        }

        // Inject the resolved slug into the frontmatter and re-serialize so
        // the persisted file has the canonical slug field. (The original
        // markdown may have omitted it, expecting derivation.)
        $withSlug = new ParsedRecipe(
            frontmatter: new \App\Recipes\Parser\Frontmatter(
                title: $parsed->frontmatter->title,
                category: $parsed->frontmatter->category,
                slug: $slug,
                servings: $parsed->frontmatter->servings,
                prepTime: $parsed->frontmatter->prepTime,
                cookTime: $parsed->frontmatter->cookTime,
                totalTime: $parsed->frontmatter->totalTime,
                ovenTemp: $parsed->frontmatter->ovenTemp,
                tags: $parsed->frontmatter->tags,
                libation: $parsed->frontmatter->libation,
                source: $parsed->frontmatter->source,
                difficulty: $parsed->frontmatter->difficulty,
                yields: $parsed->frontmatter->yields,
                references: $parsed->frontmatter->references,
                extra: $parsed->frontmatter->extra,
            ),
            ingredients: $parsed->ingredients,
            method: $parsed->method,
            notes: $parsed->notes,
            libationProse: $parsed->libationProse,
            crossReferences: $parsed->crossReferences,
        );
        $markdown = $this->serializer->serialize($withSlug);

        try {
            $writer->write($slug, $category, $markdown);
        } catch (Throwable $e) {
            return $this->failBack($request, "Save failed: {$e->getMessage()}", $markdown);
        }

        try {
            $this->reindexer->reindexOne($slug);
        } catch (Throwable $e) {
            return $this->failBack($request,
                "File saved, but the search/cache update failed: {$e->getMessage()}. Run `php artisan recipes:reindex` to recover.",
                $markdown,
            );
        }

        return redirect()
            ->route('recipes.show', ['recipe' => $slug])
            ->with('success', "Created {$title}.");
    }

    /**
     * Mirror of RecipeEditController::parse for the new-recipe context —
     * no Recipe binding required.
     */
    public function parse(Request $request): JsonResponse
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

    public function serialize(Request $request): JsonResponse
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

    public function preview(Request $request): JsonResponse
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

        $html = view('recipes._preview', [
            'parsed' => $parsed,
            'ingredientFormatter' => $this->ingredientFormatter,
            'methodFormatter' => $this->methodFormatter,
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * Return the sorted list of categories available on disk —
     * subdirectories of recipes/.
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

    /**
     * Blank state matching ParsedRecipe::toArray() shape. The new-form
     * Alpine component hydrates from this; same shape as edit.
     *
     * @return array<string,mixed>
     */
    private function blankState(): array
    {
        return [
            'frontmatter' => [
                'title' => '',
                'category' => '',
                'slug' => null,
                'servings' => null,
                'prep_time' => null,
                'cook_time' => null,
                'total_time' => null,
                'oven_temp' => null,
                'tags' => null,
                'libation' => null,
                'source' => null,
                'difficulty' => null,
                'yields' => null,
                'references' => null,
                'extra' => [],
            ],
            'ingredients' => [],
            'method' => [],
            'notes' => null,
            'libation_prose' => null,
            'cross_references' => [],
            'parse_warnings' => [],
        ];
    }

    /**
     * Minimal markdown scaffold for the raw editor on a new recipe — gives
     * the user the section headers to fill in.
     */
    private function scaffoldMarkdown(): string
    {
        return "---\ntitle:\ncategory:\n---\n\n## Ingredients\n\n## Method\n";
    }

    private function failBack(Request $request, string $error, ?string $markdown = null): RedirectResponse
    {
        $input = [];
        if ($markdown !== null) {
            $input['markdown'] = $markdown;
        }
        if ($request->filled('state')) {
            $input['state'] = (string) $request->input('state');
        }
        return back()->withInput($input)->withErrors(['save' => $error]);
    }

    private function makeWriter(): RecipeFileWriter
    {
        return new RecipeFileWriter(base_path('recipes'));
    }
}
