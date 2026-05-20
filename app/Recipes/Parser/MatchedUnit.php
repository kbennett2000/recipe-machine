<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

final class MatchedUnit
{
    /**
     * @param  string  $canonical  The internal canonical form (e.g. "tbsp", "cup", "g", "whole", "pinch").
     * @param  UnitClass  $class  Which lookup table the match came from.
     * @param  string  $input  The matched substring exactly as it appeared in the source — preserves the writer's spelling/case (e.g. "Tablespoons", "fl oz", "cloves").
     */
    public function __construct(
        public readonly string $canonical,
        public readonly UnitClass $class,
        public readonly string $input,
    ) {}
}
