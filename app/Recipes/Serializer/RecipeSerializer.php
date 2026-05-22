<?php

declare(strict_types=1);

namespace App\Recipes\Serializer;

use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Parser\Frontmatter;
use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\ParsedRecipe;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 11A — inverts RecipeParser. Takes a ParsedRecipe and produces
 * markdown that round-trips back through the parser: parse(serialize(parse(x)))
 * must equal parse(x) on every structural field.
 *
 * Pure: no filesystem, no DB, no side effects.
 *
 * The serialized output is NOT required to be byte-identical to the source
 * markdown. The canonical form normalizes:
 *   - Frontmatter key ordering (per the brief — title first, extras last)
 *   - Body section ordering (Ingredients → Method → Notes → Libation)
 *   - Bullet markers (always `-`)
 *   - Method-step nesting (sub-bullets are concatenated by the parser,
 *     so the serializer emits each step on one line)
 *   - Optional-ingredient marker (always "Optional:" prefix)
 *
 * These normalizations match what the Phase 11 form-mode editor will
 * produce regardless, so the contract is round-trip equality, not
 * source byte-identity.
 */
final class RecipeSerializer
{
    /** Canonical frontmatter key order. */
    private const FRONTMATTER_KEY_ORDER = [
        'title', 'category', 'slug',
        'servings', 'yields',
        'prep_time', 'cook_time', 'total_time',
        'oven_temp', 'difficulty',
        'tags', 'libation', 'source', 'references',
    ];

    public function __construct(
        private readonly IngredientFormatter $ingredientFormatter = new IngredientFormatter,
    ) {}

    public function serialize(ParsedRecipe $recipe): string
    {
        $out = "---\n";
        $out .= $this->serializeFrontmatter($recipe->frontmatter);
        $out .= "---\n\n";

        $out .= "## Ingredients\n\n";
        $out .= $this->serializeIngredients($recipe->ingredients);
        $out .= "\n";

        if ($recipe->method !== []) {
            $out .= "## Method\n\n";
            $out .= $this->serializeMethod($recipe->method);
            $out .= "\n";
        }

        if ($recipe->notes !== null && trim($recipe->notes) !== '') {
            $out .= "## Notes\n\n";
            $out .= rtrim($recipe->notes)."\n";
        }

        if ($recipe->libationProse !== null && trim($recipe->libationProse) !== '') {
            $out .= "\n## Libation\n\n";
            $out .= rtrim($recipe->libationProse)."\n";
        }

        return $out;
    }

    private function serializeFrontmatter(Frontmatter $fm): string
    {
        // Build the canonical-ordered map. Null/empty fields are omitted —
        // EXCEPT empty arrays (`tags: []`) which were explicit in the source.
        $map = [];
        $emitMap = $this->frontmatterFieldMap($fm);

        foreach (self::FRONTMATTER_KEY_ORDER as $key) {
            $value = $emitMap[$key] ?? null;
            if (! $this->shouldEmitFrontmatterValue($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        // Unknown fields (extra) — sorted alphabetically after the known keys.
        $extra = $fm->extra;
        ksort($extra);
        foreach ($extra as $key => $value) {
            if (! $this->shouldEmitFrontmatterValue($value)) {
                continue;
            }
            $map[$key] = $value;
        }

        // Phase 11H.1: when the frontmatter map is entirely empty (the
        // /recipes/new flow before the user has typed anything), Yaml::dump
        // returns `{  }` (flow-style empty mapping, no trailing newline).
        // Concatenated with `---\n` by serialize(), this produces
        // `---\n{  }---\n` — closing delimiter glued onto the same line.
        // The parser regex requires the delimiters to be on their own
        // lines, so the round-trip fails. Emit empty content instead so
        // serialize() yields `---\n---\n\n## Ingredients\n` — still
        // structurally valid markdown, just with an empty frontmatter.
        if ($map === []) {
            return '';
        }

        // Dump with inline=1: top-level is block (one key per line), nested
        // arrays render inline like `tags: [a, b]`. Quoting is symfony/yaml's
        // job — it escapes only when necessary.
        $yaml = Yaml::dump($map, 1, 2, Yaml::DUMP_NULL_AS_TILDE);

        // symfony/yaml renders empty PHP arrays as `{  }` (empty mapping)
        // because it can't tell sequence from map. Both parse back to an
        // empty array in PHP, so the round-trip works either way, but the
        // brief specifies `[]` for empty list-typed fields. Post-process
        // the well-known list-typed fields to use sequence syntax.
        // (Don't consume the trailing newline — \h matches horizontal
        // whitespace only.)
        foreach (['tags', 'references'] as $listField) {
            $yaml = preg_replace(
                '/^('.$listField.':)\h*\{\h*\}\h*$/m',
                '$1 []',
                $yaml,
            );
        }

        return $yaml;
    }

    /**
     * Build the snake_case map of Frontmatter fields the serializer cares about.
     *
     * @return array<string,mixed>
     */
    private function frontmatterFieldMap(Frontmatter $fm): array
    {
        return [
            'title' => $fm->title,
            'category' => $fm->category,
            'slug' => $fm->slug,
            'servings' => $fm->servings,
            'yields' => $fm->yields,
            'prep_time' => $fm->prepTime,
            'cook_time' => $fm->cookTime,
            'total_time' => $fm->totalTime,
            'oven_temp' => $fm->ovenTemp,
            'difficulty' => $fm->difficulty,
            'tags' => $fm->tags,
            'libation' => $fm->libation,
            'source' => $fm->source,
            'references' => $fm->references,
        ];
    }

    private function shouldEmitFrontmatterValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        // Empty arrays ARE emitted — they were explicit in the source.
        return true;
    }

    /**
     * @param  array<ParsedIngredient>  $ingredients
     */
    private function serializeIngredients(array $ingredients): string
    {
        // Walk the flat list; emit `### Group` headers at group transitions.
        // The parser preserves source order, so we trust it: top-level (group=null)
        // ingredients appear in source order; groups follow in the order they
        // were declared.
        $out = '';
        $currentGroup = null;
        $firstInGroup = true;

        foreach ($ingredients as $ing) {
            if ($ing->group !== $currentGroup) {
                if (! $firstInGroup) {
                    $out .= "\n";
                }
                if ($ing->group !== null) {
                    $out .= "### {$ing->group}\n\n";
                }
                $currentGroup = $ing->group;
                $firstInGroup = false;
            }
            $out .= '- '.$this->serializeIngredientLine($ing)."\n";
        }
        return $out;
    }

    /**
     * Render a single ingredient as it should appear on a `-` bullet.
     * Unparsed lines come back verbatim; parsed lines are reconstructed
     * via IngredientFormatter with the "Optional:" prefix prepended when
     * applicable.
     */
    private function serializeIngredientLine(ParsedIngredient $ing): string
    {
        if (! $ing->parsed) {
            return $ing->raw;
        }
        // IngredientFormatter has a SCALING-aware `~` prefix when unit=whole
        // meets a non-integer amount ("3 eggs × 1.5 = ~5 eggs"). That's a
        // presentation choice for scaled values, not a canonical form — the
        // ~-prefixed output can't be re-parsed by RecipeParser. For
        // serialization we want the canonical fraction back ("1/4 tsp. kosher
        // salt", which the parser stored as amount=0.25, unit=whole because
        // it misclassified "tsp."). Hide the 'whole' unit from the formatter
        // in this specific case so the ~ branch never fires; the formatter
        // elides unit=whole at render time anyway, so the output is identical
        // for integer-amount count nouns.
        $unitForFormatter = $ing->unit;
        if ($ing->unit === 'whole' && is_float($ing->amount) && floor($ing->amount) !== $ing->amount) {
            $unitForFormatter = null;
        }
        $line = $this->ingredientFormatter->formatFields([
            'amount' => is_float($ing->amount) ? $ing->amount : null,
            'amount_high' => $ing->amountHigh,
            'unit' => $unitForFormatter,
            'unit_class' => $this->unitClassFor($unitForFormatter),
            'ingredient' => $ing->ingredient,
            'modifier' => $ing->modifier,
            // Optional renders as a leading "Optional:" prefix below, so don't
            // let the formatter append its "(optional)" suffix as well.
            'optional' => false,
        ]);
        if ($ing->note !== null && $ing->note !== '') {
            $line .= ' — '.$ing->note;
        }
        if ($ing->optional) {
            $line = 'Optional: '.$line;
        }
        return $line;
    }

    /**
     * Map a canonical unit to its UnitClass so the formatter knows whether
     * to render "a pinch of salt" vs "salt to taste". We don't have the
     * class on ParsedIngredient (the parser only stores the canonical
     * unit string), so derive it here from a known table.
     */
    private function unitClassFor(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $volume = ['tsp', 'tbsp', 'cup', 'floz', 'pint', 'quart', 'gallon', 'ml', 'l'];
        $weight = ['g', 'kg', 'oz', 'lb'];
        $count = ['whole'];
        $imprecise = ['pinch', 'dash', 'splash', 'drizzle', 'handful', 'sprinkle', 'to-taste', 'as-needed'];

        return match (true) {
            in_array($unit, $volume, true) => 'volume',
            in_array($unit, $weight, true) => 'weight',
            in_array($unit, $count, true) => 'count',
            in_array($unit, $imprecise, true) => 'imprecise',
            default => null,
        };
    }

    /**
     * @param  array<string>  $method
     */
    private function serializeMethod(array $method): string
    {
        $out = '';
        foreach ($method as $i => $step) {
            $out .= ($i + 1).'. '.rtrim($step)."\n";
        }
        return $out;
    }
}
