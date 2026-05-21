<?php

declare(strict_types=1);

namespace App\Recipes\Files;

/**
 * Phase 11C — result of RecipeFileWriter::write().
 *
 * `status` is 'created' (the target path didn't exist before) or
 * 'updated' (the target was overwritten in place). `previous_path`
 * is non-null only for category moves — when a recipe slug existed
 * in category A and is being written to category B, the old file at
 * A is removed and `previous_path` records where it was. (The writer
 * doesn't currently support that move in one step; this field is
 * reserved for a future Phase 11D feature.)
 */
final class WriteResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly ?string $previousPath = null,
    ) {}
}
