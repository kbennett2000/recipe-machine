<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Recipes\Files\RecipeFileWriter;
use App\Recipes\Indexing\RecipeReindexer;
use App\Recipes\Parser\RecipeParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 11D — editor v0. Single textarea, raw markdown.
 *
 * Read flow (GET /recipes/{slug}/edit):
 *   1. Look up recipe by slug.
 *   2. Load the markdown from disk (the .md file is canonical;
 *      DB is just the cache). If the file is missing, treat the
 *      route as 404 — the DB row is stale.
 *   3. Render the textarea pre-filled with that content.
 *
 * Write flow (POST /recipes/{slug}/edit):
 *   1. Re-parse the submitted markdown to validate it.
 *   2. Enforce slug-immutability and category-immutability: this
 *      editor doesn't support renames or category moves. The user
 *      can edit any other field freely.
 *   3. Call RecipeFileWriter::write() to atomically save the new
 *      content.
 *   4. Call RecipeReindexer::reindexOne() to update the DB cache.
 *   5. Redirect to the recipe detail page with a flash success.
 *
 * On any failure (parse error, validation error, writer/reindexer
 * exception), re-render the form with an error banner and the
 * user's unsaved input preserved.
 */
final class RecipeEditController extends Controller
{
    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly RecipeReindexer $reindexer = new RecipeReindexer,
    ) {
        // RecipeFileWriter is built lazily inside update() so base_path()
        // resolves at request time, not at DI registration. Constructor
        // default-instantiation of `new RecipeFileWriter(base_path(...))`
        // is too early — base_path() needs the framework to have booted.
    }

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

        return view('recipes.edit', [
            'recipe' => $recipe,
            'markdown' => $markdown,
        ]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        // Validate the POST body. The textarea is required; everything
        // else (parse correctness, slug match, etc.) is checked below.
        $validated = $request->validate([
            'markdown' => 'required|string',
        ]);
        // Browsers normalize textarea line endings to CRLF on form submit
        // (HTML spec). The on-disk markdown is LF — convert before we
        // hand off to the writer, otherwise every save would silently
        // convert the file to CRLF.
        $markdown = str_replace("\r\n", "\n", $validated['markdown']);

        // 1. Parse — fail closed if the markdown is malformed.
        try {
            $parsed = $this->parser->parseString($markdown);
        } catch (Throwable $e) {
            return $this->failBack($markdown,
                "Couldn't parse the markdown: {$e->getMessage()}");
        }

        // 2. Slug-immutability: if the frontmatter has a slug field,
        //    it must equal the URL slug. Renames aren't supported here.
        $bodySlug = $parsed->frontmatter->slug;
        if ($bodySlug !== null && $bodySlug !== '' && $bodySlug !== $recipe->slug) {
            return $this->failBack($markdown, sprintf(
                "Renaming recipes isn't supported in the editor. The frontmatter slug ('%s') must match the URL slug ('%s'). Either restore the slug or edit the file manually.",
                $bodySlug, $recipe->slug,
            ));
        }

        // 3. Category-immutability: a category change would leave the
        //    old file behind. Out of scope here.
        $bodyCategory = $parsed->frontmatter->category;
        if ($bodyCategory !== $recipe->category) {
            return $this->failBack($markdown, sprintf(
                "Moving a recipe to a different category isn't supported here. The frontmatter category ('%s') must match the recipe's current category ('%s').",
                $bodyCategory, $recipe->category,
            ));
        }

        // 4. Write the file (atomic). The writer's validators are a
        //    second line of defense in case the parsed values somehow
        //    differ from what the URL implies.
        $writer = $this->makeWriter();
        try {
            $writer->write($recipe->slug, $recipe->category, $markdown);
        } catch (Throwable $e) {
            return $this->failBack($markdown, "Save failed: {$e->getMessage()}");
        }

        // 5. Update the DB cache for just this recipe.
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
     * Re-render the edit form with the user's input preserved and an
     * error banner shown.
     */
    private function failBack(string $markdown, string $error): RedirectResponse
    {
        return back()
            ->withInput(['markdown' => $markdown])
            ->withErrors(['save' => $error]);
    }

    /**
     * Build a RecipeFileWriter rooted at the current recipes/ directory.
     * Resolved lazily so base_path() returns the live value at request
     * time rather than at DI registration time.
     */
    private function makeWriter(): RecipeFileWriter
    {
        return new RecipeFileWriter(base_path('recipes'));
    }
}
