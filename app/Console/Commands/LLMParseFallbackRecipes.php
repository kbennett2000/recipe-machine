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
 * --dry-run shows what the LLM would do without writing the cache or
 * mutating any ingredient rows.
 *
 * Like the reindex --with-llm flag, this command no-ops gracefully if
 * the LLM isn't configured (no API key, feature disabled) — it just
 * reports what it would do.
 */
final class LLMParseFallbackRecipes extends Command
{
    protected $signature = 'recipes:llm-parse-fallback
        {--dry-run : Show what would change without writing cache or updating ingredients}
        {--show-lines : Dump each line with its before/after parse}';

    protected $description = 'Send currently-unparsed ingredient lines through the Claude fallback parser.';

    public function __construct(
        private readonly IngredientLLMParser $parser = new IngredientLLMParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = Ingredient::query()->where('parsed', false)->orderBy('id')->get();
        $totalRows = $rows->count();

        if ($totalRows === 0) {
            $this->info('No unparsed ingredient lines in the corpus. Nothing to do.');
            return self::SUCCESS;
        }

        if (! $this->parser->isEnabled()) {
            $this->warn('LLM fallback is not enabled (set RECIPE_MACHINE_LLM_FALLBACK=true and ANTHROPIC_API_KEY).');
            $this->line('Would have submitted '.$totalRows.' unparsed line(s) to the LLM.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $showLines = (bool) $this->option('show-lines');

        if ($dryRun) {
            $this->info('Dry run — no DB writes, no cache writes.');
        }

        if ($showLines) {
            $this->line('--- Before ---');
            foreach ($rows as $row) {
                $this->line(sprintf('  [%d] %s', $row->id, $row->raw));
            }
        }

        $stats = $this->parser->applyToUnparsedRows($rows, dryRun: $dryRun);

        $this->line('');
        $this->line(sprintf(
            'LLM fallback: %d distinct line(s) submitted, %d parsed, %d cached miss(es), %d still unparsed.',
            $stats['submitted'],
            $stats['parsed'],
            $stats['cached_misses'],
            $stats['still_unparsed'],
        ));
        if (! $stats['api_called']) {
            $this->line('  (no API requests made — every line resolved from the cache.)');
        }

        if ($showLines && ! $dryRun) {
            $this->line('--- After ---');
            // Re-fetch so we see the updated state.
            Ingredient::query()->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->each(function ($r) {
                $this->line(sprintf('  [%d] parsed=%s llm=%s ingredient=%s',
                    $r->id, $r->parsed ? 'true' : 'false', $r->llm_parsed ? 'true' : 'false', (string) $r->ingredient
                ));
            });
        }

        return self::SUCCESS;
    }
}
