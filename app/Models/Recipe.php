<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'title', 'category', 'servings', 'yields',
        'prep_time', 'cook_time', 'total_time', 'oven_temp',
        'difficulty', 'libation', 'libation_prose', 'notes',
        'source', 'source_path', 'source_mtime', 'parsed_at',
        'parse_warnings',
    ];

    protected $casts = [
        'parse_warnings' => 'array',
        'source_mtime' => 'datetime',
        'parsed_at' => 'datetime',
        'yields' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class)->orderBy('position');
    }

    public function methodSteps(): HasMany
    {
        return $this->hasMany(MethodStep::class)->orderBy('position');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(RecipeTag::class);
    }

    /** Outbound references (this recipe → other recipes). */
    public function references(): HasMany
    {
        return $this->hasMany(RecipeReference::class);
    }

    /** Inbound references (other recipes → this recipe). */
    public function referencedBy(): HasMany
    {
        return $this->hasMany(RecipeReference::class, 'resolved_recipe_id');
    }

    /**
     * Ingredients grouped by group_name. Top-level (no sub-group) keys to ''.
     *
     * @return array<string, array<\App\Models\Ingredient>>
     */
    public function groupedIngredients(): array
    {
        $groups = [];
        foreach ($this->ingredients as $ing) {
            $key = $ing->group_name ?? '';
            $groups[$key][] = $ing;
        }
        return $groups;
    }
}
