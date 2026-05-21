<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Corpus health check.
 *
 * Walks every recipes/**\/*.md file, parses through RecipeParser, and emits
 * a structured report covering:
 *   A. Per-recipe summary table.
 *   B. Totals across the corpus.
 *   C. Cross-reference graph (resolved vs broken).
 *   D. Frontmatter field completeness.
 *
 * --fail-on-regress is the CI hook. It compares the current run against a
 * pinned baseline (tests/Fixtures/health-check-baseline.json) and exits 1 if:
 *   - Any recipe's unparsed-line count went up.
 *   - The corpus parse rate dropped below the baseline.
 *   - A previously-resolved cross-reference now points at a missing target.
 *
 * --update-baseline rewrites the baseline file to capture the current state.
 * Use this after intentional changes (e.g. adding new recipes that legitimately
 * lower the parse rate, or removing a target whose refs you're cleaning up).
 */
final class HealthCheckRecipes extends Command
{
    protected $signature = 'recipes:health-check
        {--root= : Override recipes/ root (defaults to base_path("recipes"))}
        {--baseline= : Override baseline JSON path}
        {--fail-on-regress : Exit 1 if the corpus regressed vs. the baseline}
        {--update-baseline : Rewrite baseline to capture current state}
        {--quiet-report : Skip the human-facing report (CI use)}';

    protected $description = 'Walk recipes/**/*.md, parse each, and emit a corpus health report.';

    private const PARSE_RATE_FLOOR_DEFAULT = 0.87;

    public function handle(RecipeParser $parser): int
    {
        $root = (string) ($this->option('root') ?? base_path('recipes'));
        $baselinePath = (string) ($this->option('baseline') ?? base_path('tests/Fixtures/health-check-baseline.json'));

        $files = $this->findRecipeFiles($root);
        sort($files);

        $perRecipe = [];   // slug => snapshot
        $allSlugs = [];
        $crossRefs = [];   // [source_slug, target_slug, resolved]
        $fmStats = [];     // field => populated count
        $totalIngredients = 0;
        $totalParsed = 0;
        $totalUnparsed = 0;

        foreach ($files as $file) {
            try {
                $recipe = $parser->parseFile($file);
            } catch (\Throwable $e) {
                $this->line('PARSE FAIL: '.$file.': '.$e->getMessage());
                continue;
            }

            $snapshot = $this->snapshotRecipe($recipe, $file);
            $slug = $snapshot['slug'];
            $perRecipe[$slug] = $snapshot;
            $allSlugs[$slug] = true;

            $totalIngredients += $snapshot['ingredient_count'];
            $totalParsed += $snapshot['parsed_count'];
            $totalUnparsed += $snapshot['unparsed_count'];

            foreach ($snapshot['cross_references'] as $target) {
                $crossRefs[] = ['source' => $slug, 'target' => $target];
            }
            foreach ($snapshot['frontmatter_populated'] as $field) {
                $fmStats[$field] = ($fmStats[$field] ?? 0) + 1;
            }
        }

        $totalFiles = count($perRecipe);
        $parseRate = $totalIngredients > 0 ? $totalParsed / $totalIngredients : 1.0;

        // Resolve cross-refs.
        foreach ($crossRefs as $i => $ref) {
            $crossRefs[$i]['resolved'] = isset($allSlugs[$ref['target']]);
        }

        // Update baseline mode.
        if ($this->option('update-baseline')) {
            $this->writeBaseline($baselinePath, $perRecipe, $parseRate, $crossRefs);
            $this->line('Wrote baseline → '.$baselinePath);
            return self::SUCCESS;
        }

        // Print the human-facing report unless suppressed.
        if (! $this->option('quiet-report')) {
            $this->renderReport($perRecipe, $totalFiles, $totalIngredients, $totalParsed, $totalUnparsed, $parseRate, $crossRefs, $fmStats);
        }

        // Regression check.
        if ($this->option('fail-on-regress')) {
            $exitCode = $this->checkRegress($baselinePath, $perRecipe, $parseRate, $crossRefs);
            return $exitCode;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string>
     */
    private function findRecipeFiles(string $root): array
    {
        if (! is_dir($root)) {
            throw new RuntimeException("recipes/ root not found: {$root}");
        }
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

    /**
     * @return array<string,mixed>
     */
    private function snapshotRecipe(ParsedRecipe $recipe, string $file): array
    {
        $unparsedLines = [];
        $parsedCount = 0;
        foreach ($recipe->ingredients as $ing) {
            /** @var ParsedIngredient $ing */
            if ($ing->parsed) {
                $parsedCount++;
            } else {
                $unparsedLines[] = $ing->raw;
            }
        }
        $fm = $recipe->frontmatter;
        $fmFields = [
            'title' => $fm->title,
            'category' => $fm->category,
            'slug' => $fm->slug,
            'servings' => $fm->servings,
            'yields' => $fm->yields,
            'prep_time' => $fm->prepTime,
            'cook_time' => $fm->cookTime,
            'total_time' => $fm->totalTime,
            'oven_temp' => $fm->ovenTemp,
            'difficulty' => $fm->difficulty,
            'tags' => ($fm->tags !== null && $fm->tags !== []) ? $fm->tags : null,
            'libation' => $fm->libation,
            'source' => $fm->source,
            'references' => $fm->references,
        ];
        $populated = array_keys(array_filter($fmFields, fn ($v) => $v !== null));
        $required = ['title', 'category', 'slug'];
        $nice = ['oven_temp', 'cook_time', 'servings', 'yields', 'libation'];
        $fmComplete = count(array_intersect($required, $populated)) === count($required)
            && count(array_intersect($nice, $populated)) >= 2;

        return [
            'slug' => $fm->slug ?? basename($file, '.md'),
            'category' => $fm->category,
            'path' => $file,
            'ingredient_count' => count($recipe->ingredients),
            'parsed_count' => $parsedCount,
            'unparsed_count' => count($unparsedLines),
            'unparsed_lines' => $unparsedLines,
            'method_step_count' => count($recipe->method),
            'warning_count' => count($recipe->parseWarnings),
            'warnings' => $recipe->parseWarnings,
            'frontmatter_populated' => $populated,
            'frontmatter_complete' => $fmComplete,
            'has_oven_or_cook' => $fm->ovenTemp !== null || $fm->cookTime !== null,
            'has_servings_or_yields' => $fm->servings !== null || $fm->yields !== null,
            'cross_references' => $recipe->crossReferences,
        ];
    }

    private function renderReport(
        array $perRecipe,
        int $totalFiles,
        int $totalIng,
        int $totalParsed,
        int $totalUnparsed,
        float $parseRate,
        array $crossRefs,
        array $fmStats,
    ): void {
        $bar = str_repeat('=', 80);

        // === A. Per-recipe summary table ===
        $this->line($bar);
        $this->line('A. Per-recipe summary');
        $this->line($bar);
        $this->line(sprintf('%-55s %-12s %-7s %-3s %-3s %-3s',
            'slug', 'category', 'ing p/t', 'm', 'fm', 'w'));
        $this->line(str_repeat('-', 80));
        ksort($perRecipe);
        foreach ($perRecipe as $slug => $r) {
            $this->line(sprintf(
                '%-55s %-12s %2d/%-4d %-3d %-3s %-3d',
                $this->truncate($slug, 55),
                $this->truncate((string) ($r['category'] ?? '?'), 12),
                $r['parsed_count'],
                $r['ingredient_count'],
                $r['method_step_count'],
                $r['frontmatter_complete'] ? 'ok' : '—',
                $r['warning_count'],
            ));
        }
        $this->line('');
        $this->line('Legend: ing p/t = ingredients parsed/total · m = method step count · fm = frontmatter complete? · w = warnings');

        // === B. Totals ===
        $this->line('');
        $this->line($bar);
        $this->line('B. Totals');
        $this->line($bar);
        $this->line(sprintf('Recipe files:     %d', $totalFiles));
        $this->line(sprintf('Ingredient lines: %d total (%d parsed, %d unparsed) — %.1f%% parsed',
            $totalIng, $totalParsed, $totalUnparsed, $parseRate * 100));
        $this->line('');

        $clean = []; $mostly = []; $needs = []; $noMethod = []; $noTiming = [];
        foreach ($perRecipe as $slug => $r) {
            if ($r['unparsed_count'] === 0) { $clean[] = $slug; }
            elseif ($r['unparsed_count'] <= 2) { $mostly[] = $slug; }
            else { $needs[] = "$slug ({$r['unparsed_count']})"; }
            if ($r['method_step_count'] === 0) { $noMethod[] = $slug; }
            if (! $r['has_oven_or_cook']) { $noTiming[] = $slug; }
        }
        $this->line(sprintf('Recipes with 0 unparsed lines (clean):       %d', count($clean)));
        $this->line(sprintf('Recipes with 1-2 unparsed lines (mostly):    %d', count($mostly)));
        if ($mostly !== []) {
            $this->line('  '.implode(', ', $mostly));
        }
        $this->line(sprintf('Recipes with 3+ unparsed lines (attention):  %d', count($needs)));
        foreach ($needs as $n) {
            $this->line('  '.$n);
        }
        $this->line(sprintf('Recipes with 0 method steps (RED FLAG):      %d', count($noMethod)));
        foreach ($noMethod as $n) {
            $this->line('  '.$n);
        }
        $this->line(sprintf('Recipes with no oven_temp AND no cook_time:  %d', count($noTiming)));
        foreach ($noTiming as $n) {
            $this->line('  '.$n);
        }

        // === C. Cross-reference graph ===
        $this->line('');
        $this->line($bar);
        $this->line('C. Cross-reference graph');
        $this->line($bar);
        $resolved = array_filter($crossRefs, fn ($r) => $r['resolved']);
        $broken = array_filter($crossRefs, fn ($r) => ! $r['resolved']);
        $this->line(sprintf('Total refs: %d (resolved: %d, broken: %d)',
            count($crossRefs), count($resolved), count($broken)));
        $this->line('');
        if ($resolved !== []) {
            $this->line('Resolved refs:');
            foreach ($resolved as $r) {
                $this->line(sprintf('  %s → %s', $r['source'], $r['target']));
            }
        }
        if ($broken !== []) {
            $this->line('');
            $this->line('Broken refs (target slug not found in corpus):');
            foreach ($broken as $r) {
                $this->line(sprintf('  %s → %s  (UNRESOLVED)', $r['source'], $r['target']));
            }
        }

        // === D-pre. LLM fallback usage (Phase 9) ===
        $this->line('');
        $this->line($bar);
        $this->line('LLM fallback usage');
        $this->line($bar);
        $llmRows = \App\Models\Ingredient::query()->where('llm_parsed', true)->count();
        $cacheHits = 0;
        $cacheMisses = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('ingredient_llm_cache')) {
            $cacheHits = \App\Models\IngredientLlmCache::where('status', 'hit')->count();
            $cacheMisses = \App\Models\IngredientLlmCache::where('status', 'miss')->count();
        }
        $this->line(sprintf('LLM-parsed ingredient rows in corpus:  %d', $llmRows));
        $this->line(sprintf('LLM cache:                              %d hit(s), %d tombstone(s)', $cacheHits, $cacheMisses));
        if ($llmRows === 0 && $cacheHits === 0) {
            $this->line('  (no LLM fallback runs yet — `recipes:reindex --with-llm` to enable)');
        }

        // === D. Frontmatter completeness ===
        $this->line('');
        $this->line($bar);
        $this->line('D. Frontmatter field completeness');
        $this->line($bar);
        $fields = ['title', 'category', 'slug', 'tags', 'oven_temp', 'cook_time',
                   'libation', 'references', 'servings', 'yields',
                   'prep_time', 'total_time', 'difficulty', 'source'];
        // Sort by populated count, most-populated first.
        $stats = [];
        foreach ($fields as $f) {
            $stats[$f] = $fmStats[$f] ?? 0;
        }
        arsort($stats);
        foreach ($stats as $field => $count) {
            $pct = $totalFiles > 0 ? ($count / $totalFiles) * 100 : 0;
            $this->line(sprintf('  %-12s %3d/%-3d  (%.0f%%)', $field, $count, $totalFiles, $pct));
        }

        $this->line('');
        $this->line($bar);
    }

    private function truncate(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }

    private function writeBaseline(string $path, array $perRecipe, float $parseRate, array $crossRefs): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $recipesCondensed = [];
        foreach ($perRecipe as $slug => $r) {
            $recipesCondensed[$slug] = [
                'ingredient_count' => $r['ingredient_count'],
                'unparsed_count' => $r['unparsed_count'],
                'warning_count' => $r['warning_count'],
            ];
        }
        ksort($recipesCondensed);
        $resolvedRefs = [];
        foreach ($crossRefs as $r) {
            if ($r['resolved']) {
                $resolvedRefs[] = $r['source'].' -> '.$r['target'];
            }
        }
        sort($resolvedRefs);
        $payload = [
            'schema_version' => 1,
            'generated_at' => date('c'),
            'parse_rate_floor' => max(self::PARSE_RATE_FLOOR_DEFAULT, $parseRate),
            'recipes' => $recipesCondensed,
            'resolved_refs' => $resolvedRefs,
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    }

    private function checkRegress(string $path, array $perRecipe, float $parseRate, array $crossRefs): int
    {
        if (! is_file($path)) {
            $this->error("No baseline at {$path}. Run with --update-baseline to create one.");
            return self::FAILURE;
        }
        $baseline = json_decode((string) file_get_contents($path), true);
        if (! is_array($baseline)) {
            $this->error("Baseline at {$path} is not valid JSON.");
            return self::FAILURE;
        }

        $regressed = false;

        // Parse-rate check.
        $floor = $baseline['parse_rate_floor'] ?? self::PARSE_RATE_FLOOR_DEFAULT;
        if ($parseRate < $floor) {
            $this->error(sprintf('REGRESS: parse rate %.3f below baseline floor %.3f.', $parseRate, $floor));
            $regressed = true;
        }

        // Per-recipe unparsed/warning checks.
        $baselineRecipes = $baseline['recipes'] ?? [];
        foreach ($perRecipe as $slug => $r) {
            $b = $baselineRecipes[$slug] ?? null;
            if ($b === null) {
                // New recipe — skip; not a regression.
                continue;
            }
            if ($r['unparsed_count'] > ($b['unparsed_count'] ?? 0)) {
                $this->error(sprintf(
                    'REGRESS: %s unparsed_count %d → %d.',
                    $slug, $b['unparsed_count'] ?? 0, $r['unparsed_count']
                ));
                $regressed = true;
            }
            if ($r['warning_count'] > ($b['warning_count'] ?? 0)) {
                $this->error(sprintf(
                    'REGRESS: %s warning_count %d → %d.',
                    $slug, $b['warning_count'] ?? 0, $r['warning_count']
                ));
                $regressed = true;
            }
        }

        // Cross-ref check: refs that were resolved in baseline must still resolve.
        $resolvedBefore = array_flip($baseline['resolved_refs'] ?? []);
        foreach ($crossRefs as $r) {
            $key = $r['source'].' -> '.$r['target'];
            if (isset($resolvedBefore[$key]) && ! $r['resolved']) {
                $this->error("REGRESS: cross-ref {$key} was resolved but is now broken.");
                $regressed = true;
            }
        }

        if ($regressed) {
            $this->error('Regression detected — failing.');
            return self::FAILURE;
        }
        $this->info('No regressions vs. baseline.');
        return self::SUCCESS;
    }
}
