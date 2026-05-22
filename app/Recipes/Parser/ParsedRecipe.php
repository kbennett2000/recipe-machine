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

    /**
     * Phase 11E — build a ParsedRecipe from the array representation
     * (the form editor's POST body). Inverse of toArray(); the editor's
     * server-side serialize endpoint takes JSON in this shape and runs
     * it through RecipeSerializer to produce markdown.
     *
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fmData = $data['frontmatter'] ?? [];
        $frontmatter = new Frontmatter(
            title: (string) ($fmData['title'] ?? ''),
            category: (string) ($fmData['category'] ?? ''),
            slug: self::asNullableString($fmData['slug'] ?? null),
            servings: self::asNullableString($fmData['servings'] ?? null),
            prepTime: self::asNullableString($fmData['prep_time'] ?? null),
            cookTime: self::asNullableString($fmData['cook_time'] ?? null),
            totalTime: self::asNullableString($fmData['total_time'] ?? null),
            ovenTemp: self::asNullableString($fmData['oven_temp'] ?? null),
            tags: array_key_exists('tags', $fmData) ? self::asNullableStringArray($fmData['tags']) : null,
            libation: self::asNullableString($fmData['libation'] ?? null),
            source: self::asNullableString($fmData['source'] ?? null),
            difficulty: self::asNullableString($fmData['difficulty'] ?? null),
            yields: isset($fmData['yields']) && $fmData['yields'] !== null && $fmData['yields'] !== '' ? (int) $fmData['yields'] : null,
            references: array_key_exists('references', $fmData) ? self::asNullableStringArray($fmData['references']) : null,
            extra: is_array($fmData['extra'] ?? null) ? $fmData['extra'] : [],
        );

        $ingredients = [];
        foreach (($data['ingredients'] ?? []) as $ing) {
            if (! is_array($ing)) {
                continue;
            }
            $ingredients[] = new ParsedIngredient(
                raw: (string) ($ing['raw'] ?? ''),
                parsed: (bool) ($ing['parsed'] ?? false),
                amount: self::asNullableNumber($ing['amount'] ?? null),
                amountHigh: self::asNullableFloat($ing['amount_high'] ?? null),
                unit: self::asNullableString($ing['unit'] ?? null),
                ingredient: self::asNullableString($ing['ingredient'] ?? null),
                modifier: self::asNullableString($ing['modifier'] ?? null),
                note: self::asNullableString($ing['note'] ?? null),
                optional: (bool) ($ing['optional'] ?? false),
                group: self::asNullableString($ing['group'] ?? null),
            );
        }

        return new self(
            frontmatter: $frontmatter,
            ingredients: $ingredients,
            method: array_values(array_filter(array_map('strval', $data['method'] ?? []), fn ($s) => $s !== '')),
            notes: self::asNullableString($data['notes'] ?? null),
            libationProse: self::asNullableString($data['libation_prose'] ?? null),
            crossReferences: self::asNullableStringArray($data['cross_references'] ?? []) ?? [],
        );
    }

    private static function asNullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = is_string($v) ? trim($v) : (string) $v;
        return $s === '' ? null : $s;
    }

    private static function asNullableNumber(mixed $v): float|string|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    private static function asNullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    /** @return array<string>|null */
    private static function asNullableStringArray(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (! is_array($v)) {
            return null;
        }
        return array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v), fn ($s) => $s !== ''));
    }
}
