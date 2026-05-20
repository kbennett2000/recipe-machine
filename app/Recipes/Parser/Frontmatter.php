<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

final class Frontmatter
{
    /**
     * @param  array<string>|null  $tags
     * @param  array<string>|null  $references
     * @param  array<string,mixed>  $extra  Unknown frontmatter keys, preserved verbatim per spec section a.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $category,
        public readonly ?string $slug = null,
        public readonly ?string $servings = null,
        public readonly ?string $prepTime = null,
        public readonly ?string $cookTime = null,
        public readonly ?string $totalTime = null,
        public readonly ?string $ovenTemp = null,
        public readonly ?array $tags = null,
        public readonly ?string $libation = null,
        public readonly ?string $source = null,
        public readonly ?string $difficulty = null,
        public readonly ?int $yields = null,
        public readonly ?array $references = null,
        public readonly array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'category' => $this->category,
            'slug' => $this->slug,
            'servings' => $this->servings,
            'prep_time' => $this->prepTime,
            'cook_time' => $this->cookTime,
            'total_time' => $this->totalTime,
            'oven_temp' => $this->ovenTemp,
            'tags' => $this->tags,
            'libation' => $this->libation,
            'source' => $this->source,
            'difficulty' => $this->difficulty,
            'yields' => $this->yields,
            'references' => $this->references,
            'extra' => $this->extra,
        ];
    }
}
