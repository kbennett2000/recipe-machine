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
 * Threshold + cap: relationships below SIMILARITY_THRESHOLD Jaccard are
 * discarded; the top MAX_PER_RECIPE per recipe are kept.
 *
 * Tuned from 0.15 to 0.20 in Phase 8.1 — the 0.15-0.20 band over-matched
 * on shared-staple pairs (any two recipes with flour + sugar + butter +
 * egg). 0.20 keeps clear within-family matches (breads, cookies) while
 * dropping the "any sweet baked thing" noise.
 *
 * Asymmetric fingerprint-size rule (Phase 8.1): a recipe with fewer than
 * MIN_FINGERPRINT_AS_SOURCE significant ingredients does not GENERATE
 * see-also links of its own (its small fingerprint inflates Jaccard noise
 * when 1-2 staples overlap), but it CAN appear as a target in another
 * recipe's see-also list. Read: small recipes are recommended, never
 * recommending.
 */
final class SeeAlsoComputer
{
    public const SIMILARITY_THRESHOLD = 0.20;

    public const MAX_PER_RECIPE = 5;

    /** Minimum fingerprint size for a recipe to be treated as a SOURCE. */
    public const MIN_FINGERPRINT_AS_SOURCE = 5;

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
            // Asymmetric source filter (Phase 8.1): small-fingerprint
            // recipes don't generate their own see-also rows. They can
            // still be TARGETS — peers loop below considers every same-
            // category recipe regardless of size.
            if (count($a['ingredients']) < self::MIN_FINGERPRINT_AS_SOURCE) {
                continue;
            }
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
     * Phase 11B — surgical see-also update for a single changed slug.
     *
     * Targeted scope: rows where recipe_id=$slug OR related_recipe_id=$slug
     * are deleted, and new rows are computed only for those relationships.
     * Other recipes' rows (C→D where neither is the changed slug) stay
     * untouched, including their updated_at timestamps.
     *
     * Correctness reasoning: since only one recipe's fingerprint changed,
     * only similarities involving that recipe could have shifted. A peer
     * B's similarity to non-changed peers C/D/E is identical, so their
     * rows in B's top-N list don't need re-evaluating. Only B's
     * relationship to the changed recipe can move.
     *
     * Caveat (documented behavior, not a bug): if a peer B previously had
     * the changed recipe in its top-N and the change pushes the similarity
     * below threshold, B's list shrinks by one. The full reindex would
     * have backfilled with a 6th-ranked peer that's now within top-N.
     * Single-slug doesn't do that — peer B's list might have 4 entries
     * instead of 5. Acceptable because the full reindex is still the
     * authoritative path; single-slug is for incremental updates between
     * full rebuilds.
     *
     * Returns the number of see-also rows the operation wrote.
     */
    public function recomputeForSlug(string $slug, ?string $oldCategory = null): int
    {
        $recipe = Recipe::query()->where('slug', $slug)->first();

        // Pull the changed recipe's id BEFORE we mutate anything, so we
        // know which rows to delete even on the deletion path.
        $recipeId = $recipe?->id;

        // Drop every existing row where the changed recipe is on either
        // side of the relationship. (When the recipe was just deleted,
        // FK cascade already wiped these — the delete here is a no-op
        // safety belt for that case.)
        if ($recipeId !== null) {
            DB::table('recipe_see_alsos')
                ->where('recipe_id', $recipeId)
                ->orWhere('related_recipe_id', $recipeId)
                ->delete();
        }

        // If the recipe is gone (delete path), nothing to rebuild.
        if ($recipe === null) {
            return 0;
        }

        $fingerprints = $this->loadFingerprints();
        $own = $fingerprints[$slug] ?? null;
        if ($own === null) {
            // Recipe exists but has zero significant ingredients — it can
            // still be a TARGET of other recipes' see-also, so check below.
            $ownIngredients = [];
        } else {
            $ownIngredients = $own['ingredients'];
        }

        $rowsWritten = 0;

        // Categories to consider: the current category, plus the old one
        // if the recipe just moved. Same-category-only similarity means a
        // category change can wipe inbound rows in the old category and
        // create new ones in the new category.
        $categories = [$recipe->category];
        if ($oldCategory !== null && $oldCategory !== $recipe->category) {
            $categories[] = $oldCategory;
        }

        // === Outgoing: the changed recipe's own top-N see-also list. ===
        if (count($ownIngredients) >= self::MIN_FINGERPRINT_AS_SOURCE) {
            $candidates = [];
            foreach ($fingerprints as $otherSlug => $b) {
                if ($otherSlug === $slug) {
                    continue;
                }
                if ($b['category'] !== $recipe->category) {
                    continue;
                }
                $sim = $this->jaccard($ownIngredients, $b['ingredients']);
                if ($sim < self::SIMILARITY_THRESHOLD) {
                    continue;
                }
                $candidates[] = ['related_id' => $b['id'], 'score' => (int) round($sim * 100)];
            }
            usort($candidates, fn ($x, $y) => $y['score'] <=> $x['score']);
            $candidates = array_slice($candidates, 0, self::MAX_PER_RECIPE);
            foreach ($candidates as $c) {
                RecipeSeeAlso::create([
                    'recipe_id' => $recipe->id,
                    'related_recipe_id' => $c['related_id'],
                    'score' => $c['score'],
                ]);
                $rowsWritten++;
            }
        }

        // === Incoming: peers whose top-N might now include the changed recipe. ===
        // For each peer in the (now-current) category, check if it should
        // gain a row pointing at the changed recipe. We DON'T touch the
        // peer's other rows — they're correct already.
        foreach ($fingerprints as $peerSlug => $peer) {
            if ($peerSlug === $slug) {
                continue;
            }
            if (! in_array($peer['category'], $categories, true)) {
                continue;
            }
            // The peer must be source-eligible (fingerprint size threshold).
            if (count($peer['ingredients']) < self::MIN_FINGERPRINT_AS_SOURCE) {
                continue;
            }
            // The peer must be in the changed recipe's CURRENT category
            // for the row to be added. (If oldCategory is in the list,
            // those peers won't add a new row — same-category constraint
            // excludes them.)
            if ($peer['category'] !== $recipe->category) {
                continue;
            }
            $sim = $this->jaccard($peer['ingredients'], $ownIngredients);
            if ($sim < self::SIMILARITY_THRESHOLD) {
                continue;
            }
            $score = (int) round($sim * 100);

            // Does this score earn a spot in the peer's top-N? Count
            // existing rows; if < MAX, always add. If already at MAX,
            // add only if the new score beats the peer's lowest existing
            // score (we don't displace existing rows — that would touch
            // them, which we're trying to avoid).
            $existing = RecipeSeeAlso::query()
                ->where('recipe_id', $peer['id'])
                ->orderBy('score')
                ->get();
            if ($existing->count() < self::MAX_PER_RECIPE) {
                RecipeSeeAlso::create([
                    'recipe_id' => $peer['id'],
                    'related_recipe_id' => $recipe->id,
                    'score' => $score,
                ]);
                $rowsWritten++;
                continue;
            }
            // At cap — only add if we beat the lowest. We don't displace
            // the lowest; the peer simply gains a 6th row temporarily.
            // The full reindex cleans this up; in the meantime, the peer
            // surfaces an extra-relevant similar recipe rather than miss
            // it entirely.
            $lowest = (int) $existing->first()->score;
            if ($score > $lowest) {
                RecipeSeeAlso::create([
                    'recipe_id' => $peer['id'],
                    'related_recipe_id' => $recipe->id,
                    'score' => $score,
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
