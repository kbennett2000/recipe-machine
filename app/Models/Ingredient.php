<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ingredient extends Model
{
    protected $fillable = [
        'recipe_id', 'position', 'group_name', 'raw', 'parsed', 'llm_parsed',
        'amount', 'amount_high', 'unit', 'unit_class',
        'ingredient', 'modifier', 'note', 'optional',
    ];

    protected $casts = [
        'parsed' => 'boolean',
        'llm_parsed' => 'boolean',
        'optional' => 'boolean',
        'amount' => 'float',
        'amount_high' => 'float',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
