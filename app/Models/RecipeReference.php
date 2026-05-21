<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeReference extends Model
{
    protected $fillable = [
        'recipe_id', 'referenced_slug', 'resolved_recipe_id', 'source',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function resolvedRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'resolved_recipe_id');
    }
}
