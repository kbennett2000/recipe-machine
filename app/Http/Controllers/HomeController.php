<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Recipes\Nav\Categories;

final class HomeController extends Controller
{
    public function index()
    {
        $countsByCategory = Recipe::query()
            ->selectRaw('category, count(*) as cnt')
            ->groupBy('category')
            ->pluck('cnt', 'category')
            ->all();

        $categories = Categories::orderedWithCounts($countsByCategory);

        // "Recently updated" — using source_mtime as the source of truth.
        // (parsed_at moves on every reindex; source_mtime reflects writer activity.)
        $recent = Recipe::query()
            ->orderByDesc('source_mtime')
            ->orderByDesc('parsed_at')
            ->take(6)
            ->get();

        return view('home', [
            'categories' => $categories,
            'recent' => $recent,
            'totalRecipes' => array_sum($countsByCategory),
        ]);
    }
}
