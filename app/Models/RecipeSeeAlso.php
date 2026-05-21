<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeSeeAlso extends Model
{
    protected $table = 'recipe_see_alsos';

    protected $fillable = ['recipe_id', 'related_recipe_id', 'score'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'related_recipe_id');
    }
}
