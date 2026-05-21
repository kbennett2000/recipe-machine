<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    public function show(string $category)
    {
        $recipes = Recipe::query()
            ->where('category', $category)
            ->orderBy('title')
            ->with(['ingredients'])
            ->get();

        if ($recipes->isEmpty()) {
            abort(404, "Category '{$category}' not found or has no recipes.");
        }

        // Compute unparsed counts per recipe for the card footer indicator.
        $recipes = $recipes->each(function (Recipe $r) {
            $r->setAttribute('unparsed_count', $r->ingredients->where('parsed', false)->count());
        });

        return view('categories.show', [
            'category' => $category,
            'categoryLabel' => ucfirst($category),
            'recipes' => $recipes,
        ]);
    }
}
