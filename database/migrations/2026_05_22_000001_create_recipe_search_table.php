<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 virtual table that backs the full-text search.
 *
 * Contentless-style design: the FTS table holds tokenized indexes only,
 * not the canonical content (which lives in `recipes`, `ingredients`, etc.).
 * The `slug` column is UNINDEXED — we need it on result rows but don't
 * search it.
 *
 * Tokenizer choice (per the Phase 4 brief):
 *   - porter:                stem English words ("kneading" matches "knead")
 *   - unicode61:             normalize unicode characters
 *   - remove_diacritics 2:   "creme" matches "crème", "cafe" matches "café"
 *
 * Other DB engines won't have this table; this migration is a no-op on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        DB::statement("
            CREATE VIRTUAL TABLE recipe_search USING fts5(
                slug UNINDEXED,
                title,
                ingredients_text,
                method_text,
                notes_text,
                libation_text,
                tokenize = 'porter unicode61 remove_diacritics 2'
            )
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        DB::statement('DROP TABLE IF EXISTS recipe_search');
    }
};
