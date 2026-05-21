<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Recipes\Migration\MigrationResult;
use App\Recipes\Migration\Migrator;
use Illuminate\Console\Command;

final class MigrateRecipes extends Command
{
    protected $signature = 'recipes:migrate
        {source-file : Path to a source markdown codex file}
        {--dry-run : Print the migration plan without writing any files}
        {--category=auto : Category for all recipes, or "auto" to infer from the source H1}
        {--output-root= : Override the output directory root (defaults to base_path("recipes"))}
        {--corpus-path= : Override the unparsed-corpus output path (defaults to base_path("tests/Fixtures/IngredientLines/unparsed-corpus.txt"))}';

    protected $description = 'Migrate recipes from a source markdown codex into per-recipe files at recipes/<category>/<slug>.md.';

    public function handle(Migrator $migrator): int
    {
        $sourcePath = (string) $this->argument('source-file');
        $dryRun = (bool) $this->option('dry-run');
        $category = (string) ($this->option('category') ?? 'auto');
        $outputRoot = (string) ($this->option('output-root') ?? base_path('recipes'));
        $corpusPath = (string) ($this->option('corpus-path') ?? base_path('tests/Fixtures/IngredientLines/unparsed-corpus.txt'));

        try {
            $outcome = $migrator->migrate($sourcePath, $category, $dryRun, $outputRoot);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->renderReport($outcome, $dryRun);
        $this->writeUnparsedCorpus($outcome['unparsedCorpus'], $corpusPath);

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *   sourcePath:string,
     *   inferredCategory:?string,
     *   results:array<MigrationResult>,
     *   unparsedCorpus:array<string>
     * }  $outcome
     */
    private function renderReport(array $outcome, bool $dryRun): void
    {
        $results = $outcome['results'];
        $bar = str_repeat('=', 80);

        $this->line("Source:           {$outcome['sourcePath']}");
        $this->line('Category (--auto): '.($outcome['inferredCategory'] ?? '(none inferred)'));
        $this->line('Total recipes:    '.count($results));
        $this->line('Dry run:          '.($dryRun ? 'YES (no files written)' : 'no (files written)'));
        $this->newLine();
        $this->line($bar);

        $totalUnparsed = 0;
        $totalWarnings = 0;
        foreach ($results as $i => $r) {
            $this->renderRecipeBlock($i + 1, count($results), $r);
            $totalUnparsed += count($r->unparsedLines);
            $totalWarnings += count($r->warnings);
        }

        $this->line($bar);
        $this->line("Aggregated unparsed lines: {$totalUnparsed}");
        $this->line("Aggregated warnings:       {$totalWarnings}");
    }

    private function renderRecipeBlock(int $i, int $n, MigrationResult $r): void
    {
        $verb = $r->wrote ? 'wrote' : 'would write';
        $idx = sprintf('[%d/%d]', $i, $n);

        $this->newLine();
        $this->line("{$idx} {$r->sourceTitle}");
        $this->line("        → {$verb}: {$r->targetPath}");
        $this->line(sprintf(
            '        ingredients: %d (%d parsed, %d unparsed)',
            $r->ingredientCount, $r->parsedCount, count($r->unparsedLines),
        ));
        $this->line("        method:      {$r->methodStepCount} steps");
        $this->line('        frontmatter: populated = '.implode(', ', $r->frontmatterPopulated));
        if ($r->frontmatterMissing !== []) {
            $this->line('                     missing   = '.implode(', ', $r->frontmatterMissing));
        }
        $this->line('        cross-refs:  '.($r->crossReferences === [] ? '(none)' : implode(', ', $r->crossReferences)));

        if ($r->unparsedLines !== []) {
            $this->line('        unparsed:');
            foreach ($r->unparsedLines as $line) {
                $this->line("          - {$line}");
            }
        }
        if ($r->warnings !== []) {
            $this->line('        warnings:');
            foreach ($r->warnings as $w) {
                $this->line("          ! {$w}");
            }
        }
    }

    /**
     * @param  array<string>  $unparsedLines
     */
    private function writeUnparsedCorpus(array $unparsedLines, string $corpusPath): void
    {
        $dir = dirname($corpusPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $body = "# Unparsed ingredient lines from the most recent recipes:migrate run.\n"
              . "# This file is the test corpus for Phase 10's LLM-fallback hardening.\n"
              . "# Regenerated on every run; committed for visibility.\n"
              . "\n"
              . ($unparsedLines === [] ? '' : implode("\n", $unparsedLines)."\n");
        file_put_contents($corpusPath, $body);
        $this->line("Wrote unparsed corpus → {$corpusPath} (".count($unparsedLines).' lines)');
    }
}
