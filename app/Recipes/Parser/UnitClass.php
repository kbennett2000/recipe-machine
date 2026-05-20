<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

enum UnitClass: string
{
    case VOLUME = 'volume';
    case WEIGHT = 'weight';
    case COUNT = 'count';
    case IMPRECISE = 'imprecise';
}
