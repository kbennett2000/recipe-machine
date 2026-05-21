<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Per-recipe migration outcome, used by the report and the round-trip check.
 */
final class MigrationResult
{
    /**
     * @param  array<string>  $unparsedLines  Original line text for any ingredients the parser couldn't structure.
     * @param  array<string>  $warnings  Human-facing issues (suspicious method-step splits, missing canonical sections, etc.).
     * @param  array<string>  $crossReferences  Slug list emitted into the file's frontmatter `references` (or extracted from inline brackets after parse).
     */
    public function __construct(
        public readonly string $sourceTitle,
        public readonly string $slug,
        public readonly string $category,
        public readonly string $targetPath,
        public readonly bool $wrote,
        public readonly int $ingredientCount,
        public readonly int $parsedCount,
        public readonly array $unparsedLines,
        public readonly int $methodStepCount,
        public readonly array $frontmatterPopulated,
        public readonly array $frontmatterMissing,
        public readonly array $crossReferences,
        public readonly array $warnings,
    ) {}
}
