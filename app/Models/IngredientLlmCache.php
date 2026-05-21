<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9 — persistent cache for LLM ingredient-line parses.
 *
 * Two row kinds:
 *   - 'hit': the LLM returned a usable parse, stored in parsed_payload.
 *     These are permanent. The user invalidates via recipes:llm-cache-clear.
 *   - 'miss': the LLM was called but returned nothing usable. Stored as
 *     a tombstone with expires_at = created_at + TTL (default 30 days).
 *     When expired, the line is re-attempted (the next round may use a
 *     better model and produce a hit).
 *
 * Lookups go through raw_line_hash (sha1 of raw_line). SQLite can't
 * efficiently index on long TEXT columns, and even short raw lines are
 * 50-200 chars — a fixed-length hash makes the index cheap.
 */
class IngredientLlmCache extends Model
{
    protected $table = 'ingredient_llm_cache';

    protected $fillable = [
        'raw_line', 'raw_line_hash', 'status',
        'parsed_payload', 'model_used', 'expires_at',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'expires_at' => 'datetime',
    ];

    public static function hashFor(string $rawLine): string
    {
        return sha1($rawLine);
    }

    /** True if this row is a non-expired tombstone. */
    public function isLiveMiss(): bool
    {
        if ($this->status !== 'miss') {
            return false;
        }
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function isHit(): bool
    {
        return $this->status === 'hit';
    }
}
