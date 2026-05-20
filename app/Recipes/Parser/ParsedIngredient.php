<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

final class ParsedIngredient
{
    /**
     * @param  string  $raw  Original line text minus bullet marker, trimmed.
     * @param  bool  $parsed  False when the line didn't match any shape and only `raw` is meaningful.
     * @param  float|string|null  $amount  Float when scalar; string when a non-numeric imprecise-style amount slipped through (rare); null when imprecise-unit-bearing or ranged with high-only.
     * @param  float|null  $amountHigh  Upper bound when amount is a range.
     * @param  string|null  $unit  Canonical unit form, or null.
     * @param  string|null  $ingredient  Ingredient name with count nouns folded per spec.
     * @param  string|null  $modifier  Post-comma preparation state.
     * @param  string|null  $note  Post-em-dash freeform note.
     * @param  bool  $optional  Set by `Optional:` prefix or `(optional)` suffix.
     * @param  string|null  $group  Sub-component group name from `### Header`, null at top level.
     */
    public function __construct(
        public readonly string $raw,
        public readonly bool $parsed,
        public readonly float|string|null $amount = null,
        public readonly ?float $amountHigh = null,
        public readonly ?string $unit = null,
        public readonly ?string $ingredient = null,
        public readonly ?string $modifier = null,
        public readonly ?string $note = null,
        public readonly bool $optional = false,
        public readonly ?string $group = null,
    ) {}

    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'parsed' => $this->parsed,
            'amount' => $this->amount,
            'amount_high' => $this->amountHigh,
            'unit' => $this->unit,
            'ingredient' => $this->ingredient,
            'modifier' => $this->modifier,
            'note' => $this->note,
            'optional' => $this->optional,
            'group' => $this->group,
        ];
    }
}
