<?php

declare(strict_types=1);

namespace App\Recipes\Indexing;

use App\Models\Recipe;
use App\Models\RecipeSeeAlso;
use Illuminate\Support\Facades\DB;

/**
 * Computes the recipe_see_alsos table from the indexed ingredient sets.
 *
 * Similarity metric: Jaccard over each recipe's "ingredient fingerprint"
 * (the set of lowercase canonical ingredient names from parsed lines that
 * have a quantitative unit class — volume / weight / count). Imprecise
 * lines like "salt to taste" and unparsed garbage lines are excluded so
 * the noisy lines don't dominate small recipes.
 *
 * Same-category-only for v1: a sauce isn't a "similar recipe" to a bread
 * just because they both use salt. Cross-category similarity (e.g. potato
 * soup ↔ potato bread on shared potatoes) is deferred to Phase 8.1 if a
 * compelling case emerges.
 *
 * Threshold + cap: relationships below 0.15 Jaccard are discarded; the
 * top 5 per recipe are kept. The threshold was eyeballed from the bread
 * corpus — it filters out distant cousins that share only one staple
 * (flour, salt) while keeping genuinely overlapping recipes.
 */
final class SeeAlsoComputer
{
    public const SIMILARITY_THRESHOLD = 0.15;

    public const MAX_PER_RECIPE = 5;

    /** Unit classes worth treating as "real" ingredients for fingerprinting. */
    private const SIGNIFICANT_UNIT_CLASSES = ['volume', 'weight', 'count'];

    /**
     * Recompute see-also relationships for the whole corpus. Truncates the
     * table first so stale rows don't survive a corpus shrink.
     */
    public function recompute(): int
    {
        DB::table('recipe_see_alsos')->delete();

        $fingerprints = $this->loadFingerprints();
        $byCategory = [];
        foreach ($fingerprints as $slug => $data) {
            $byCategory[$data['category']][$slug] = $data;
        }

        $now = now();
        $rowsWritten = 0;
        foreach ($fingerprints as $slug => $a) {
            $candidates = [];
            $peers = $byCategory[$a['category']] ?? [];
            foreach ($peers as $otherSlug => $b) {
                if ($otherSlug === $slug) {
                    continue;
                }
                $sim = $this->jaccard($a['ingredients'], $b['ingredients']);
                if ($sim < self::SIMILARITY_THRESHOLD) {
                    continue;
                }
                $candidates[] = [
                    'related_id' => $b['id'],
                    'score' => (int) round($sim * 100),
                ];
            }
            // Sort by score DESC, keep top N.
            usort($candidates, fn ($x, $y) => $y['score'] <=> $x['score']);
            $candidates = array_slice($candidates, 0, self::MAX_PER_RECIPE);

            foreach ($candidates as $c) {
                RecipeSeeAlso::create([
                    'recipe_id' => $a['id'],
                    'related_recipe_id' => $c['related_id'],
                    'score' => $c['score'],
                ]);
                $rowsWritten++;
            }
        }
        return $rowsWritten;
    }

    /**
     * Build the per-recipe fingerprint map. Returns slug => {id, category,
     * ingredients (set as array<string,bool>)}.
     *
     * @return array<string, array{id:int, category:string, ingredients: array<string,bool>}>
     */
    private function loadFingerprints(): array
    {
        $rows = DB::table('recipes')
            ->leftJoin('ingredients', 'ingredients.recipe_id', '=', 'recipes.id')
            ->whereIn('ingredients.unit_class', self::SIGNIFICANT_UNIT_CLASSES)
            ->where('ingredients.parsed', true)
            ->whereNotNull('ingredients.ingredient')
            ->select('recipes.id', 'recipes.slug', 'recipes.category', 'ingredients.ingredient')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $slug = $r->slug;
            if (! isset($out[$slug])) {
                $out[$slug] = [
                    'id' => (int) $r->id,
                    'category' => (string) $r->category,
                    'ingredients' => [],
                ];
            }
            $name = mb_strtolower(trim((string) $r->ingredient));
            if ($name !== '') {
                $out[$slug]['ingredients'][$name] = true;
            }
        }
        return $out;
    }

    /**
     * Jaccard similarity over two ingredient sets stored as
     * array<string,bool>.
     *
     * @param  array<string,bool>  $a
     * @param  array<string,bool>  $b
     */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.0;
        }
        $intersection = 0;
        foreach ($a as $k => $_) {
            if (isset($b[$k])) {
                $intersection++;
            }
        }
        $union = count($a) + count($b) - $intersection;
        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
