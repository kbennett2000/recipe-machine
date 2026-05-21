<?php

declare(strict_types=1);

namespace App\Recipes\Nav;

/**
 * Category-list helper for navigation.
 *
 * The spec recommends six categories (breads, sauces, soups, entrees,
 * desserts, seafood). They always render in that fixed order whether or not
 * they have any recipes. Any custom categories (per spec section a — the
 * enum is open) get collected into a final "Other" bucket.
 *
 * Each entry exposes:
 *   - slug:    "breads"
 *   - label:   "Breads"
 *   - count:   integer
 *   - is_recommended: bool
 */
final class Categories
{
    public const RECOMMENDED = [
        'breads', 'sauces', 'soups', 'entrees', 'desserts', 'seafood',
    ];

    /**
     * @param  array<string,int>  $countsByCategory
     * @return array<array{slug:string,label:string,count:int,is_recommended:bool}>
     */
    public static function orderedWithCounts(array $countsByCategory): array
    {
        $out = [];
        foreach (self::RECOMMENDED as $slug) {
            $out[] = [
                'slug' => $slug,
                'label' => ucfirst($slug),
                'count' => $countsByCategory[$slug] ?? 0,
                'is_recommended' => true,
            ];
        }
        // Anything else: render under "Other".
        foreach ($countsByCategory as $slug => $count) {
            if (in_array($slug, self::RECOMMENDED, true)) {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'label' => ucfirst($slug),
                'count' => $count,
                'is_recommended' => false,
            ];
        }
        return $out;
    }
}
