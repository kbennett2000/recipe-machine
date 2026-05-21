<?php

declare(strict_types=1);

namespace App\Recipes\Indexing;

use App\Models\Ingredient;
use App\Models\MethodStep;
use App\Models\Recipe;
use App\Models\RecipeReference;
use App\Models\RecipeTag;
use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Parser\UnitClass;
use App\Recipes\Parser\UnitMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11B — single-recipe and full-corpus reindex service.
 *
 * Two paths share the same write-a-recipe-to-the-DB primitives:
 *
 *   reindexOne(slug) — parse exactly one .md file and surgically update
 *                      its DB slice (recipes row + children + FTS row +
 *                      see-also relationships involving this recipe +
 *                      cross-references touching this slug). Other
 *                      recipes' rows are NOT touched.
 *
 *   remove(slug)     — drop the recipe + children + FTS row + see-also
 *                      relationships + unresolve cross-refs pointing at it.
 *
 * The full-corpus path (truncate-and-rebuild) still lives in the
 * `recipes:reindex` command itself; that path is unchanged from Phase 10.
 * This service handles the incremental updates the editor relies on.
 *
 * Architectural notes for see-also recomputation are in
 * SeeAlsoComputer::recomputeForSlug() — short version: rows where the
 * changed recipe is involved (on either side) are recomputed; other
 * relationships stay untouched, including their timestamps.
 */
final class RecipeReindexer
{
    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly UnitMatcher $unitMatcher = new UnitMatcher,
        private readonly IngredientFormatter $ingredientFormatter = new IngredientFormatter,
        private readonly SeeAlsoComputer $seeAlsoComputer = new SeeAlsoComputer,
    ) {}

    /**
     * Walk the recipes/ tree and return the path for a given slug, or null.
     */
    public function findRecipeFile(string $slug, ?string $root = null): ?string
    {
        $root ??= base_path('recipes');
        if (! is_dir($root)) {
            return null;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile() || $info->getExtension() !== 'md') {
                continue;
            }
            $candidateSlug = basename($info->getPathname(), '.md');
            if ($candidateSlug === $slug) {
                return $info->getPathname();
            }
            // The frontmatter `slug:` field can override the filename.
            // Check it as a fallback to be safe.
            try {
                $parsed = $this->parser->parseFile($info->getPathname());
                if (($parsed->frontmatter->slug ?? null) === $slug) {
                    return $info->getPathname();
                }
            } catch (\Throwable) {
                // Skip unparseable files when searching by slug.
                continue;
            }
        }
        return null;
    }

    public function reindexOne(string $slug, ?string $root = null): ReindexResult
    {
        $startedAt = microtime(true);

        $file = $this->findRecipeFile($slug, $root);
        if ($file === null) {
            return ReindexResult::notFound($slug, $this->elapsedMs($startedAt));
        }

        try {
            $parsed = $this->parser->parseFile($file);
        } catch (\Throwable $e) {
            // Treat unparseable files like "not found" — the caller (the
            // editor or a CI script) needs to see a clean failure mode.
            return ReindexResult::notFound($slug, $this->elapsedMs($startedAt));
        }

        // Look up the existing recipe BEFORE we mutate anything — we need
        // the old category for see-also recomputation if the recipe just
        // moved categories.
        $existing = Recipe::query()->where('slug', $slug)->first();
        $oldCategory = $existing?->category;
        $status = $existing === null ? 'created' : 'updated';

        $changes = DB::transaction(function () use ($file, $parsed, $existing, $slug) {
            $recipe = $existing === null
                ? $this->insertRecipe($file, $parsed)
                : $this->updateRecipe($existing, $file, $parsed);

            // Replace child rows wholesale — easier than diffing positions.
            DB::table('ingredients')->where('recipe_id', $recipe->id)->delete();
            DB::table('method_steps')->where('recipe_id', $recipe->id)->delete();
            DB::table('recipe_tags')->where('recipe_id', $recipe->id)->delete();
            DB::table('recipe_references')->where('recipe_id', $recipe->id)->delete();

            $ingredientCount = $this->insertIngredients($recipe, $parsed);
            $methodCount = $this->insertMethodSteps($recipe, $parsed);
            $tagCount = $this->insertTags($recipe, $parsed);
            $refCount = $this->insertReferences($recipe, $parsed);

            return [
                'recipe_id' => $recipe->id,
                'ingredients' => $ingredientCount,
                'method_steps' => $methodCount,
                'tags' => $tagCount,
                'references' => $refCount,
            ];
        });

        // === FTS5 row update (SQLite only). ===
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->updateFtsRow($slug);
        }

        // === See-also recomputation involving this slug. ===
        $seeAlsoUpdated = $this->seeAlsoComputer->recomputeForSlug($slug, $oldCategory);

        // === Cross-reference resolution: outgoing AND incoming. ===
        $crossrefChanges = $this->resolveCrossRefs($slug, $changes['recipe_id']);

        return new ReindexResult(
            slug: $slug,
            status: $status,
            changes: [
                'ingredients' => $changes['ingredients'],
                'method_steps' => $changes['method_steps'],
                'tags' => $changes['tags'],
                'references' => $changes['references'],
                'see_also_updated' => $seeAlsoUpdated,
                'crossrefs_resolved' => $crossrefChanges['resolved'],
                'crossrefs_broken' => $crossrefChanges['broken'],
            ],
            elapsedMs: $this->elapsedMs($startedAt),
        );
    }

    public function remove(string $slug): ReindexResult
    {
        $startedAt = microtime(true);

        $existing = Recipe::query()->where('slug', $slug)->first();
        if ($existing === null) {
            return ReindexResult::notFound($slug, $this->elapsedMs($startedAt));
        }

        $oldCategory = $existing->category;
        $recipeId = $existing->id;

        // Snapshot the inbound cross-refs BEFORE deletion so we can report
        // how many flipped to unresolved.
        $brokenCount = (int) RecipeReference::query()
            ->where('resolved_recipe_id', $recipeId)
            ->count();

        DB::transaction(function () use ($existing, $slug) {
            // FK cascades handle ingredients/method_steps/recipe_tags/
            // recipe_references/recipe_see_alsos.
            $existing->delete();

            // FTS row isn't FK-bound — clear it explicitly.
            if (DB::connection()->getDriverName() === 'sqlite') {
                try {
                    DB::statement('DELETE FROM recipe_search WHERE slug = ?', [$slug]);
                } catch (\Throwable) {
                    // FTS table may not exist on bare DBs.
                }
            }
        });

        // Other recipes that referenced this slug: cascade left their
        // recipe_references rows in place but with resolved_recipe_id
        // pointing at a now-deleted row id. SQLite's FK behavior depends
        // on the constraint definition — to be safe, NULL out any
        // stragglers explicitly.
        RecipeReference::query()
            ->where('resolved_recipe_id', $recipeId)
            ->update(['resolved_recipe_id' => null]);

        // See-also recomputation runs the deletion-path branch
        // (recipe doesn't exist anymore).
        $this->seeAlsoComputer->recomputeForSlug($slug, $oldCategory);

        return new ReindexResult(
            slug: $slug,
            status: 'deleted',
            changes: [
                'crossrefs_broken' => $brokenCount,
            ],
            elapsedMs: $this->elapsedMs($startedAt),
        );
    }

    // ---- Internal helpers (extracted from ReindexRecipes command). ----

    public function insertRecipe(string $file, ParsedRecipe $parsed): Recipe
    {
        $fm = $parsed->frontmatter;
        $slug = $fm->slug ?? basename($file, '.md');
        $relative = ltrim(str_replace(base_path(), '', $file), '/');

        return Recipe::create($this->recipeAttributes($file, $relative, $parsed, $slug));
    }

    public function updateRecipe(Recipe $existing, string $file, ParsedRecipe $parsed): Recipe
    {
        $fm = $parsed->frontmatter;
        $slug = $fm->slug ?? basename($file, '.md');
        $relative = ltrim(str_replace(base_path(), '', $file), '/');

        $existing->fill($this->recipeAttributes($file, $relative, $parsed, $slug));
        $existing->save();
        return $existing;
    }

    /**
     * @return array<string,mixed>
     */
    private function recipeAttributes(string $file, string $relative, ParsedRecipe $parsed, string $slug): array
    {
        $fm = $parsed->frontmatter;
        return [
            'slug' => $slug,
            'title' => $fm->title,
            'category' => $fm->category,
            'servings' => $fm->servings,
            'yields' => $fm->yields,
            'prep_time' => $fm->prepTime,
            'cook_time' => $fm->cookTime,
            'total_time' => $fm->totalTime,
            'oven_temp' => $fm->ovenTemp,
            'difficulty' => $fm->difficulty,
            'libation' => $fm->libation,
            'libation_prose' => $parsed->libationProse,
            'notes' => $parsed->notes,
            'source' => $fm->source,
            'source_path' => $relative,
            'source_mtime' => date('Y-m-d H:i:s', filemtime($file) ?: time()),
            'parsed_at' => now(),
            'parse_warnings' => $parsed->parseWarnings,
        ];
    }

    public function insertIngredients(Recipe $recipe, ParsedRecipe $parsed): int
    {
        $rows = [];
        foreach ($parsed->ingredients as $i => $ing) {
            $unitClass = null;
            if ($ing->unit !== null && $ing->unit !== '') {
                $matched = $this->unitMatcher->match($ing->unit);
                $unitClass = $matched?->class->value ?? ($ing->unit === 'whole' ? UnitClass::COUNT->value : null);
            }
            $rows[] = [
                'recipe_id' => $recipe->id,
                'position' => $i + 1,
                'group_name' => $ing->group,
                'raw' => $ing->raw,
                'parsed' => $ing->parsed,
                'amount' => is_numeric($ing->amount) ? $ing->amount : null,
                'amount_high' => $ing->amountHigh,
                'unit' => $ing->unit,
                'unit_class' => $unitClass,
                'ingredient' => $ing->ingredient,
                'modifier' => $ing->modifier,
                'note' => $ing->note,
                'optional' => $ing->optional,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            Ingredient::insert($rows);
        }
        return count($rows);
    }

    public function insertMethodSteps(Recipe $recipe, ParsedRecipe $parsed): int
    {
        $rows = [];
        foreach ($parsed->method as $i => $content) {
            $rows[] = [
                'recipe_id' => $recipe->id,
                'position' => $i + 1,
                'content' => $content,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            MethodStep::insert($rows);
        }
        return count($rows);
    }

    public function insertTags(Recipe $recipe, ParsedRecipe $parsed): int
    {
        $tags = $parsed->frontmatter->tags ?? [];
        $rows = [];
        $seen = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $rows[] = [
                'recipe_id' => $recipe->id,
                'tag' => $tag,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            RecipeTag::insert($rows);
        }
        return count($rows);
    }

    public function insertReferences(Recipe $recipe, ParsedRecipe $parsed): int
    {
        $rows = [];
        $seen = [];
        foreach ($parsed->frontmatter->references ?? [] as $slug) {
            $slug = (string) $slug;
            if ($slug === '' || isset($seen[$slug.':frontmatter'])) {
                continue;
            }
            $seen[$slug.':frontmatter'] = true;
            $rows[] = [
                'recipe_id' => $recipe->id,
                'referenced_slug' => $slug,
                'resolved_recipe_id' => null,
                'source' => 'frontmatter',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach ($parsed->crossReferences as $slug) {
            $slug = (string) $slug;
            if ($slug === '' || isset($seen[$slug.':frontmatter']) || isset($seen[$slug.':inline'])) {
                continue;
            }
            $seen[$slug.':inline'] = true;
            $rows[] = [
                'recipe_id' => $recipe->id,
                'referenced_slug' => $slug,
                'resolved_recipe_id' => null,
                'source' => 'inline',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows !== []) {
            RecipeReference::insert($rows);
        }
        return count($rows);
    }

    /**
     * Resolve cross-references both ways for an incremental update:
     *   - Outgoing: this recipe's own references to other slugs. Look each
     *     up and populate resolved_recipe_id where the target now exists.
     *   - Incoming: other recipes' references whose referenced_slug matches
     *     this slug. Now that this recipe exists, they resolve.
     *
     * @return array{resolved:int, broken:int}
     */
    private function resolveCrossRefs(string $slug, int $recipeId): array
    {
        $resolvedDelta = 0;
        $brokenDelta = 0;

        // Outgoing: this recipe's refs.
        $outgoing = RecipeReference::query()->where('recipe_id', $recipeId)->get();
        foreach ($outgoing as $ref) {
            $targetId = Recipe::query()->where('slug', $ref->referenced_slug)->value('id');
            $prev = $ref->resolved_recipe_id;
            if ($targetId !== $prev) {
                $ref->resolved_recipe_id = $targetId;
                $ref->save();
                if ($targetId !== null) {
                    $resolvedDelta++;
                } else {
                    $brokenDelta++;
                }
            }
        }

        // Incoming: other recipes whose referenced_slug is this recipe's
        // slug. They might have been unresolved before; now they resolve.
        $incoming = RecipeReference::query()
            ->where('referenced_slug', $slug)
            ->where('recipe_id', '!=', $recipeId)
            ->get();
        foreach ($incoming as $ref) {
            if ($ref->resolved_recipe_id !== $recipeId) {
                $ref->resolved_recipe_id = $recipeId;
                $ref->save();
                $resolvedDelta++;
            }
        }

        return ['resolved' => $resolvedDelta, 'broken' => $brokenDelta];
    }

    /**
     * Rebuild the FTS5 row for a single slug. SQLite's FTS5 vtable doesn't
     * support partial column updates — DELETE then INSERT for the row.
     */
    private function updateFtsRow(string $slug): void
    {
        $recipe = Recipe::query()
            ->with(['ingredients', 'methodSteps'])
            ->where('slug', $slug)
            ->first();
        if ($recipe === null) {
            return;
        }

        try {
            DB::statement('DELETE FROM recipe_search WHERE slug = ?', [$slug]);
            DB::insert(
                'INSERT INTO recipe_search (slug, title, ingredients_text, method_text, notes_text, libation_text) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $recipe->slug,
                    $recipe->title,
                    $this->buildIngredientsText($recipe),
                    $this->buildMethodText($recipe),
                    (string) ($recipe->notes ?? ''),
                    trim(((string) ($recipe->libation ?? '')).' '.((string) ($recipe->libation_prose ?? ''))),
                ]
            );
        } catch (\Throwable) {
            // FTS table missing — drop the operation silently so the
            // rest of the reindex isn't blocked.
        }
    }

    private function buildIngredientsText(Recipe $recipe): string
    {
        $parts = [];
        $seenGroups = [];
        foreach ($recipe->ingredients as $ing) {
            if ($ing->group_name !== null && $ing->group_name !== '' && ! isset($seenGroups[$ing->group_name])) {
                $parts[] = $ing->group_name;
                $seenGroups[$ing->group_name] = true;
            }
            if ($ing->parsed) {
                $parts[] = $this->ingredientFormatter->format($ing);
                if ($ing->note !== null && $ing->note !== '') {
                    $parts[] = $ing->note;
                }
            } else {
                $parts[] = $ing->raw;
            }
        }
        return implode("\n", $parts);
    }

    private function buildMethodText(Recipe $recipe): string
    {
        return $recipe->methodSteps->pluck('content')->implode("\n");
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
