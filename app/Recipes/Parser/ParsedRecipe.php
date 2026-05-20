<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

final class ParsedRecipe
{
    /**
     * @param  array<ParsedIngredient>  $ingredients  Flat array; sub-group membership lives on each ingredient's `group` field per the spec.
     * @param  array<string>  $method  Ordered step bodies (one entry per top-level list item).
     * @param  array<string>  $crossReferences  Deduped slug list from frontmatter `references` ∪ inline `[[bracket]]` mentions.
     * @param  array<string>  $parseWarnings  Informational only; never raised as exceptions.
     */
    public function __construct(
        public readonly Frontmatter $frontmatter,
        public readonly array $ingredients,
        public readonly array $method,
        public readonly ?string $notes = null,
        public readonly ?string $libationProse = null,
        public readonly array $crossReferences = [],
        public readonly array $parseWarnings = [],
    ) {}

    public function toArray(): array
    {
        return [
            'frontmatter' => $this->frontmatter->toArray(),
            'ingredients' => array_map(fn (ParsedIngredient $i) => $i->toArray(), $this->ingredients),
            'method' => $this->method,
            'notes' => $this->notes,
            'libation_prose' => $this->libationProse,
            'cross_references' => $this->crossReferences,
            'parse_warnings' => $this->parseWarnings,
        ];
    }
}
