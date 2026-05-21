<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IngredientLlmCache;
use Illuminate\Console\Command;

/**
 * Phase 9 — invalidate entries in ingredient_llm_cache.
 *
 * Three modes:
 *   --all                Drop every cache row.
 *   --line='raw text'    Drop the row keyed to one specific raw line.
 *   --misses-only        Drop only tombstones (status='miss'). Useful when
 *                        a better model is available and you want to retry
 *                        the lines that previously came back empty without
 *                        losing the existing successful parses.
 *
 * Modes are mutually exclusive; passing more than one is an error.
 */
final class LLMCacheClear extends Command
{
    protected $signature = 'recipes:llm-cache-clear
        {--all : Drop every row}
        {--line= : Drop the row for one specific raw line}
        {--misses-only : Drop only tombstone (miss) rows}';

    protected $description = 'Invalidate entries in the LLM ingredient cache.';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $line = $this->option('line');
        $missesOnly = (bool) $this->option('misses-only');

        $modes = array_filter([$all, $line !== null && $line !== '', $missesOnly]);
        if (count($modes) === 0) {
            $this->error('Pick one of --all, --line=..., or --misses-only.');
            return self::INVALID;
        }
        if (count($modes) > 1) {
            $this->error('--all, --line, and --misses-only are mutually exclusive.');
            return self::INVALID;
        }

        if ($all) {
            $count = IngredientLlmCache::count();
            IngredientLlmCache::query()->delete();
            $this->line("Dropped {$count} cache row(s).");
            return self::SUCCESS;
        }

        if ($missesOnly) {
            $count = IngredientLlmCache::where('status', 'miss')->count();
            IngredientLlmCache::where('status', 'miss')->delete();
            $this->line("Dropped {$count} miss/tombstone row(s); hits left intact.");
            return self::SUCCESS;
        }

        // Single-line mode.
        $hash = IngredientLlmCache::hashFor((string) $line);
        $row = IngredientLlmCache::where('raw_line_hash', $hash)->first();
        if ($row === null) {
            $this->line('No cache row found for that line.');
            return self::SUCCESS;
        }
        $row->delete();
        $this->line('Dropped the cache row for that line.');
        return self::SUCCESS;
    }
}
