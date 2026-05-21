<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Recipes\ShoppingList\Aggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShoppingListController extends Controller
{
    /**
     * Shopping list page shell. The aggregated content loads via JS — Alpine
     * reads sessionStorage (or the URL hash), then POSTs to /shopping-list/calculate.
     */
    public function show()
    {
        return view('shopping-list.index');
    }

    /**
     * POST /shopping-list/calculate
     *
     * Body: { recipes: [{ slug: string, scale: number }, ...] }
     * Returns the AggregatedList shape as JSON.
     */
    public function calculate(Request $request, Aggregator $aggregator): JsonResponse
    {
        $validated = $request->validate([
            'recipes' => 'array',
            'recipes.*.slug' => 'required|string|max:255',
            'recipes.*.scale' => 'nullable|numeric|min:0.01|max:50',
        ]);

        $items = [];
        foreach ($validated['recipes'] ?? [] as $r) {
            $items[] = [
                'slug' => (string) $r['slug'],
                'scale' => isset($r['scale']) ? (float) $r['scale'] : 1.0,
            ];
        }

        $list = $aggregator->aggregate($items);

        return response()->json($list->toArray());
    }
}
