<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\MethodStep;
use App\Models\Recipe;
use App\Models\RecipeReference;
use App\Models\RecipeTag;
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
        {--print-progress : Print a line per file as it indexes}';

    protected $description = 'Truncate the recipe cache and reindex every recipes/**/*.md file.';

    public function __construct(
        private readonly RecipeParser $parser = new RecipeParser,
        private readonly UnitMatcher $unitMatcher = new UnitMatcher,
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

        $elapsed = microtime(true) - $startedAt;

        $this->line('');
        $this->line(sprintf(
            'Indexed %d recipes · %d ingredients · %d method steps · %d resolved refs · %d unresolved refs · %.2fs',
            $totals['recipes'],
            $totals['ingredients'],
            $totals['method_steps'],
            $resolved,
            $unresolved,
            $elapsed,
        ));
        if ($totals['skipped'] > 0) {
            $this->warn("Skipped {$totals['skipped']} files with parse errors.");
        }

        return self::SUCCESS;
    }

    private function truncateAll(): void
    {
        // SQLite doesn't support TRUNCATE; use DELETE and reset auto-increment.
        Schema::disableForeignKeyConstraints();
        DB::table('recipe_references')->delete();
        DB::table('recipe_tags')->delete();
        DB::table('method_steps')->delete();
        DB::table('ingredients')->delete();
        DB::table('recipes')->delete();
        // Reset auto-increment sequences (SQLite-specific; harmless on other DBs).
        try {
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('recipes','ingredients','method_steps','recipe_tags','recipe_references')");
        } catch (\Throwable) {
            // Non-SQLite — ignore.
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
}
