<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\MethodStep;
use App\Models\Recipe;
use App\Models\RecipeReference;
use App\Models\RecipeTag;
use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Indexing\SeeAlsoComputer;
use App\Recipes\LLM\IngredientLLMParser;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Parser\UnitClass;
use App\Recipes\Parser\UnitMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-rebuild indexer: parses every markdown file in recipes/<category>/<slug>.md
 * and writes the parsed structure into the SQLite cache.
 *
 * Architecture commitment (per Phase 3 brief): markdown is canonical; the
 * database is a cache. This command truncates and rewrites all five
 * recipe-related tables; the resulting DB state is purely a function of the
 * current markdown corpus.
 *
 * Two-pass algorithm:
 *   Pass 1: insert every recipe + its ingredients/method/tags/references,
 *           leaving resolved_recipe_id null on cross-refs.
 *   Pass 2: walk the recipe_references table and look up each
 *           referenced_slug; populate resolved_recipe_id where a match exists.
 *
 * This avoids the chicken-and-egg of "recipe A references recipe B, which
 * hasn't been inserted yet."
 */
final class ReindexRecipes extends Command
{
    protected $signature = 'recipes:reindex
        {--path=recipes : Root directory to walk}
        {--print-progress : Print a line per file as it indexes}
        {--with-llm : After rules-based parsing, run the LLM fallback over remaining unparsed ingredient lines}
        {--dry-run : When combined with --with-llm, the LLM pass is a preview only (no API calls, no cache writes, no ingredient row mutations). The rest of the reindex runs normally.}';

    protected $description = 'Truncate the recipe cache and reindex every recipes/**/*.md file.';

    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly UnitMatcher $unitMatcher = new UnitMatcher,
        private readonly IngredientFormatter $ingredientFormatter = new IngredientFormatter,
        private readonly SeeAlsoComputer $seeAlsoComputer = new SeeAlsoComputer,
        private readonly IngredientLLMParser $llmParser = new IngredientLLMParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $root = base_path((string) $this->option('path'));
        $verbose = (bool) $this->option('print-progress');

        if (! is_dir($root)) {
            $this->error("Recipe root not found: {$root}");
            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $this->truncateAll();

        $files = $this->findRecipeFiles($root);
        sort($files);

        $totals = [
            'recipes' => 0,
            'ingredients' => 0,
            'method_steps' => 0,
            'tags' => 0,
            'refs' => 0,
            'skipped' => 0,
        ];

        DB::transaction(function () use ($files, $verbose, &$totals) {
            foreach ($files as $file) {
                try {
                    $parsed = $this->parser->parseFile($file);
                } catch (\Throwable $e) {
                    $this->error("PARSE FAIL: {$file}: {$e->getMessage()}");
                    $totals['skipped']++;
                    continue;
                }

                $recipe = $this->insertRecipe($file, $parsed);
                $totals['recipes']++;
                $totals['ingredients'] += $this->insertIngredients($recipe, $parsed);
                $totals['method_steps'] += $this->insertMethodSteps($recipe, $parsed);
                $totals['tags'] += $this->insertTags($recipe, $parsed);
                $totals['refs'] += $this->insertReferences($recipe, $parsed);

                if ($verbose) {
                    $this->line(sprintf(
                        '  indexed %-50s — %2d ing, %2d steps, %2d refs',
                        $recipe->slug,
                        $recipe->ingredients()->count(),
                        $recipe->methodSteps()->count(),
                        $recipe->references()->count(),
                    ));
                }
            }
        });

        // Pass 2: resolve references.
        $resolved = 0;
        $unresolved = 0;
        RecipeReference::query()->orderBy('id')->chunk(200, function ($refs) use (&$resolved, &$unresolved) {
            foreach ($refs as $ref) {
                $target = Recipe::query()->where('slug', $ref->referenced_slug)->value('id');
                if ($target !== null) {
                    $ref->resolved_recipe_id = $target;
                    $ref->save();
                    $resolved++;
                } else {
                    $unresolved++;
                }
            }
        });

        // Pass 3: populate the FTS5 search index (skipped on non-SQLite drivers).
        $searchRows = 0;
        if (DB::connection()->getDriverName() === 'sqlite') {
            $searchRows = $this->rebuildSearchIndex();
        }

        // Pass 4 (Phase 8): compute see-also relationships from the
        // freshly indexed ingredient sets. Cheap (~milliseconds for 30 recipes).
        $seeAlsoRows = $this->seeAlsoComputer->recompute();

        // Pass 5 (Phase 9, opt-in): LLM fallback for unparsed ingredient
        // lines. Skipped unless --with-llm AND the feature is enabled in
        // config. With --dry-run, this pass becomes a no-side-effects
        // preview — no API calls, no cache writes, no row mutations.
        $llmStats = null;
        $llmPreview = null;
        if ((bool) $this->option('with-llm')) {
            $unparsed = \App\Models\Ingredient::query()->where('parsed', false)->get();
            $rawLines = $unparsed->pluck('raw')->unique()->values()->all();
            if ((bool) $this->option('dry-run')) {
                $llmPreview = $this->llmParser->previewBatch($rawLines, sampleSize: 5);
            } else {
                $llmStats = $this->llmParser->applyToUnparsedRows($unparsed);
            }
        }

        $elapsed = microtime(true) - $startedAt;

        $this->line('');
        $this->line(sprintf(
            'Indexed %d recipes · %d ingredients · %d method steps · %d resolved refs · %d unresolved refs · %d search rows · %d see-also links · %.2fs',
            $totals['recipes'],
            $totals['ingredients'],
            $totals['method_steps'],
            $resolved,
            $unresolved,
            $searchRows,
            $seeAlsoRows,
            $elapsed,
        ));
        if ($llmStats !== null) {
            // Disjoint stats (Phase 9.1): parsed + cached_misses + still_unparsed = submitted.
            $this->line(sprintf(
                'LLM fallback: %d lines submitted, %d parsed, %d cached misses, %d still unparsed.',
                $llmStats['submitted'],
                $llmStats['parsed'],
                $llmStats['cached_misses'],
                $llmStats['still_unparsed'],
            ));
        }
        if ($llmPreview !== null) {
            $this->line(sprintf('LLM fallback dry-run: would submit %d unparsed lines.', $llmPreview['total']));
            if ($llmPreview['sample_to_submit'] !== []) {
                $this->line('First '.count($llmPreview['sample_to_submit']).' lines:');
                foreach ($llmPreview['sample_to_submit'] as $line) {
                    $this->line('  - '.$line);
                }
            }
            $cached = $llmPreview['cached_hits'] + $llmPreview['cached_misses'];
            if ($cached > 0) {
                $this->line(sprintf(
                    'Of the %d lines, %d are already in the cache (%d hits, %d misses) and would not trigger API calls. %d lines would be submitted.',
                    $llmPreview['total'], $cached, $llmPreview['cached_hits'], $llmPreview['cached_misses'], $llmPreview['would_submit'],
                ));
            }
            $this->line('Run without --dry-run to actually parse.');
        }
        if ($totals['skipped'] > 0) {
            $this->warn("Skipped {$totals['skipped']} files with parse errors.");
        }

        return self::SUCCESS;
    }

    private function truncateAll(): void
    {
        // SQLite doesn't support TRUNCATE; use DELETE and reset auto-increment.
        Schema::disableForeignKeyConstraints();
        // Drop dependent tables before recipes so FK cascades don't matter.
        if (Schema::hasTable('recipe_see_alsos')) {
            DB::table('recipe_see_alsos')->delete();
        }
        DB::table('recipe_references')->delete();
        DB::table('recipe_tags')->delete();
        DB::table('method_steps')->delete();
        DB::table('ingredients')->delete();
        DB::table('recipes')->delete();
        // Reset auto-increment sequences (SQLite-specific; harmless on other DBs).
        try {
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('recipes','ingredients','method_steps','recipe_tags','recipe_references','recipe_see_alsos')");
        } catch (\Throwable) {
            // Non-SQLite — ignore.
        }
        // Clear the FTS5 search index if present.
        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement('DELETE FROM recipe_search');
            } catch (\Throwable) {
                // Table may not exist yet (first reindex post-migration); ignore.
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    /** @return array<string> */
    private function findRecipeFiles(string $root): array
    {
        $out = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && $info->getExtension() === 'md') {
                $out[] = $info->getPathname();
            }
        }
        return $out;
    }

    private function insertRecipe(string $file, $parsed): Recipe
    {
        $fm = $parsed->frontmatter;
        $slug = $fm->slug ?? basename($file, '.md');
        $relative = ltrim(str_replace(base_path(), '', $file), '/');

        return Recipe::create([
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
        ]);
    }

    private function insertIngredients(Recipe $recipe, $parsed): int
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

    private function insertMethodSteps(Recipe $recipe, $parsed): int
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

    private function insertTags(Recipe $recipe, $parsed): int
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

    private function insertReferences(Recipe $recipe, $parsed): int
    {
        // Frontmatter refs first, then inline refs we haven't already counted.
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
        // Inline [[bracket]] refs come from ParsedRecipe.crossReferences, which
        // already includes frontmatter refs. Only add ones that aren't already counted.
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
     * Build the FTS5 search index from the freshly-written canonical tables.
     *
     * For each recipe, we denormalize ingredient lines and method steps into
     * single text columns. Parsed ingredients use their structured prose form
     * (e.g. "2 cups flour" — what the user actually reads); unparsed lines
     * fall back to raw text so they remain searchable. Ingredient modifiers,
     * notes-on-ingredient, and sub-group names also feed into the index so
     * a query like "minced garlic" or "filling walnuts" matches.
     */
    private function rebuildSearchIndex(): int
    {
        $rows = 0;
        Recipe::with(['ingredients', 'methodSteps'])->orderBy('id')->chunk(50, function ($recipes) use (&$rows) {
            foreach ($recipes as $recipe) {
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
                $rows++;
            }
        });
        return $rows;
    }

    private function buildIngredientsText(Recipe $recipe): string
    {
        $parts = [];
        $seenGroups = [];
        foreach ($recipe->ingredients as $ing) {
            // Sub-group name: include each group label once so queries against
            // group names (e.g. "filling", "glaze", "remoulade") match.
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
        return $recipe->methodSteps
            ->pluck('content')
            ->implode("\n");
    }
}
