<?php

declare(strict_types=1);

namespace App\Recipes\Files;

/**
 * Phase 11C — result of RecipeFileWriter::delete().
 *
 * `status` is 'deleted' (the file existed and was removed) or
 * 'not_found' (the file wasn't on disk in the first place). `path`
 * is the absolute path that was (or would have been) removed.
 */
final class DeleteResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $path,
    ) {}
}
