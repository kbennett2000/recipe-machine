<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Recipes\LLM\IngredientLLMParser;
use Illuminate\Console\Command;

/**
 * Phase 9 — run the LLM fallback against the corpus's unparsed ingredient
 * lines without doing a full reindex. Useful for a quick "we added more
 * recipes; let's classify them" pass between reindexes.
 *
 * --dry-run is a TRUE preview (Phase 9.1): no API calls, no cache writes,
 * no row mutations. Just reports what the next live run would do, broken
 * down by current cache state.
 */
final class LLMParseFallbackRecipes extends Command
{
    protected $signature = 'recipes:llm-parse-fallback
        {--dry-run : Preview only — no API calls, no cache writes, no row mutations}
        {--show-lines : Dump each line with its before/after parse (real runs only)}';

    protected $description = 'Send currently-unparsed ingredient lines through the Claude fallback parser.';

    public function __construct(
        private readonly IngredientLLMParser $parser = new IngredientLLMParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = Ingredient::query()->where('parsed', false)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->info('No unparsed ingredient lines in the corpus. Nothing to do.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $showLines = (bool) $this->option('show-lines');
        $rawLines = $rows->pluck('raw')->unique()->values()->all();

        if ($dryRun) {
            $this->renderDryRun($rawLines);
            return self::SUCCESS;
        }

        if (! $this->parser->isEnabled()) {
            $this->warn('LLM fallback is not enabled (set RECIPE_MACHINE_LLM_FALLBACK=true and ANTHROPIC_API_KEY).');
            $this->line('Would have submitted '.count($rawLines).' unparsed line(s) to the LLM. Use --dry-run for a full preview.');
            return self::SUCCESS;
        }

        if ($showLines) {
            $this->line('--- Before ---');
            foreach ($rows as $row) {
                $this->line(sprintf('  [%d] %s', $row->id, $row->raw));
            }
        }

        $stats = $this->parser->applyToUnparsedRows($rows);

        $this->line('');
        $this->line(sprintf(
            'LLM fallback: %d lines submitted, %d parsed, %d cached misses, %d still unparsed.',
            $stats['submitted'],
            $stats['parsed'],
            $stats['cached_misses'],
            $stats['still_unparsed'],
        ));
        if (! $stats['api_called']) {
            $this->line('  (no API requests made — every line resolved from the cache.)');
        }

        if ($showLines) {
            $this->line('--- After ---');
            Ingredient::query()->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->each(function ($r) {
                $this->line(sprintf('  [%d] parsed=%s llm=%s ingredient=%s',
                    $r->id, $r->parsed ? 'true' : 'false', $r->llm_parsed ? 'true' : 'false', (string) $r->ingredient
                ));
            });
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $rawLines
     */
    private function renderDryRun(array $rawLines): void
    {
        $preview = $this->parser->previewBatch($rawLines, sampleSize: 5);
        $this->line(sprintf('Would submit %d unparsed lines to the LLM fallback.', $preview['total']));
        if ($preview['sample_to_submit'] !== []) {
            $this->line('First '.count($preview['sample_to_submit']).' lines:');
            foreach ($preview['sample_to_submit'] as $line) {
                $this->line('  - '.$line);
            }
        }
        $cached = $preview['cached_hits'] + $preview['cached_misses'];
        if ($cached > 0) {
            $this->line(sprintf(
                'Of the %d lines, %d are already in the cache (%d hits, %d misses) and would not trigger API calls. %d lines would be submitted.',
                $preview['total'], $cached, $preview['cached_hits'], $preview['cached_misses'], $preview['would_submit'],
            ));
        }
        $this->line('Run without --dry-run to actually parse.');
    }
}
