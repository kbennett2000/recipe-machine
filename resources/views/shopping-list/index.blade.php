@extends('layouts.app')

@section('title', 'Shopping List — Recipe Machine')

@section('content')
    <div x-data="shoppingListPage()" x-init="init()">

        {{-- HEADER --}}
        <div class="mb-8 flex flex-wrap items-baseline justify-between gap-3 no-print">
            <div>
                <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">Shopping List</h1>
                <p class="mt-2 text-base text-stone-500 dark:text-stone-500"
                   x-show="hasItems" x-cloak>
                    <span x-text="items.length"></span>
                    <span x-text="items.length === 1 ? 'recipe' : 'recipes'"></span>
                    on your meal plan.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2" x-show="hasItems" x-cloak>
                <button type="button" @click="printList"
                        class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                    Print
                </button>
                <button type="button" @click="copyShareUrl"
                        class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                    <span x-show="copyState === 'idle'">Copy share link</span>
                    <span x-show="copyState === 'copied'" x-cloak class="text-amber-700 dark:text-amber-400">Copied!</span>
                </button>
                <button type="button" @click="clearAll"
                        class="rounded border border-stone-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50 dark:border-stone-700 dark:text-rose-400 dark:hover:bg-rose-950/30">
                    Clear list
                </button>
            </div>
        </div>

        {{-- LOADING / ERROR --}}
        <template x-if="loading && hasItems">
            <p class="text-sm italic text-stone-500 dark:text-stone-500">Loading list…</p>
        </template>
        <template x-if="error">
            <p class="text-sm italic text-rose-600 dark:text-rose-400" x-text="`Error loading list: ${error}`"></p>
        </template>

        {{-- EMPTY STATE --}}
        <template x-if="!hasItems && !loading">
            <section class="rounded-lg border border-dashed border-stone-200 bg-stone-50 p-10 text-center dark:border-stone-800 dark:bg-stone-900/40">
                <p class="font-display text-lg text-stone-700 dark:text-stone-300">
                    Your shopping list is empty.
                </p>
                <p class="mt-2 text-sm text-stone-500 dark:text-stone-500">
                    Browse recipes and click "Add to shopping list" on any one to get started.
                </p>
                <a href="{{ url('/') }}"
                   class="mt-5 inline-block rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                    Browse recipes
                </a>
            </section>
        </template>

        {{-- LIST CONTENT --}}
        <template x-if="hasItems && !loading && aggregated">
            <div class="grid grid-cols-1 gap-10 lg:grid-cols-[280px_1fr]">

                {{-- CONTRIBUTING RECIPES (sidebar) --}}
                <aside class="lg:sticky lg:top-24 lg:self-start no-print">
                    <h2 class="font-display text-base font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-3">
                        Recipes
                    </h2>
                    <ul class="space-y-2.5">
                        <template x-for="r in aggregated.source_recipes" :key="r.slug">
                            <li class="rounded-lg border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
                                <div class="flex items-start justify-between gap-2">
                                    <a :href="`/recipes/${r.slug}`" class="font-display text-sm font-medium text-stone-900 hover:text-amber-700 dark:text-stone-100 dark:hover:text-amber-400"
                                       x-text="r.title"></a>
                                    <button type="button" @click="removeRecipe(r.slug)"
                                            class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400 text-base leading-none"
                                            aria-label="Remove">×</button>
                                </div>
                                <div class="mt-2 flex items-center gap-1">
                                    <label class="text-xs text-stone-500 dark:text-stone-500" :for="`scale-${r.slug}`">Scale:</label>
                                    <input :id="`scale-${r.slug}`" type="number" step="0.5" min="0.5" max="20"
                                           :value="r.scale"
                                           @change="updateScale(r.slug, $event.target.value)"
                                           class="w-16 rounded border border-stone-300 px-2 py-0.5 text-sm text-stone-900 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                                </div>
                            </li>
                        </template>
                    </ul>
                </aside>

                {{-- AGGREGATED LIST (main) --}}
                <div>
                    <div class="mb-6 only-print">
                        <h1 class="font-display text-3xl font-semibold mb-2">Shopping List</h1>
                        <p class="text-sm text-stone-600">
                            For: <span x-text="aggregated.source_recipes.map(r => r.scale === 1 ? r.title : `${r.title} (×${r.scale})`).join(', ')"></span>
                        </p>
                    </div>

                    {{-- Per-aisle ingredient blocks --}}
                    <template x-for="(items, aisle) in aggregated.by_aisle" :key="aisle">
                        <section class="mb-8">
                            <h2 class="font-display text-base font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400 mb-3 border-b border-amber-200 dark:border-amber-900/50 pb-1"
                                x-text="aisle"></h2>
                            <ul class="space-y-1.5">
                                <template x-for="item in items" :key="item.name">
                                    <li class="flex items-baseline justify-between gap-4 text-stone-800 dark:text-stone-200">
                                        <div class="flex-1 min-w-0">
                                            <span class="font-medium" x-text="item.name"></span>
                                            <span x-show="item.optional" class="text-xs italic text-stone-500 dark:text-stone-500"> (optional)</span>
                                            <template x-if="item.source_attribution">
                                                <span class="text-xs text-stone-500 dark:text-stone-500 ml-1.5"
                                                      x-text="item.source_attribution"></span>
                                            </template>
                                            <template x-if="item.notes && item.notes.length">
                                                <span class="text-xs italic text-stone-500 dark:text-stone-500 ml-1.5"
                                                      x-text="`— ${item.notes.join('; ')}`"></span>
                                            </template>
                                        </div>
                                        <div class="text-right text-sm tabular-nums shrink-0">
                                            {{-- For imprecise: render the multi-source phrase. For others: amount + unit. --}}
                                            <template x-if="item.quantities[0] && item.quantities[0].amount === null">
                                                <span class="text-stone-600 dark:text-stone-400 italic"
                                                      x-text="impreciseSummary(item.quantities)"></span>
                                            </template>
                                            <template x-if="item.quantities[0] && item.quantities[0].amount !== null">
                                                <span x-text="renderAmount(item.quantities[0])"></span>
                                            </template>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </section>
                    </template>

                    {{-- Unparsed lines --}}
                    <template x-if="aggregated.unparsed && aggregated.unparsed.length > 0">
                        <section class="mb-8">
                            <h2 class="font-display text-base font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-2 border-b border-stone-200 dark:border-stone-700 pb-1">
                                Other / verify
                            </h2>
                            <p class="text-xs italic text-stone-500 dark:text-stone-500 mb-3">
                                These lines couldn't be auto-parsed and aren't included in totals above — please verify.
                            </p>
                            <ul class="space-y-1.5 text-sm">
                                <template x-for="(line, idx) in aggregated.unparsed" :key="idx">
                                    <li class="text-stone-700 dark:text-stone-300">
                                        <span x-text="line.raw"></span>
                                        <span class="text-xs text-stone-500 dark:text-stone-500" x-text="`(${line.source_title})`"></span>
                                    </li>
                                </template>
                            </ul>
                        </section>
                    </template>
                </div>
            </div>
        </template>

        {{-- Render helpers (declared on the component so the templates can call them). --}}
        <script>
            // Attach amount-rendering helpers to the shoppingListPage component once it's defined.
            (function () {
                const orig = window.shoppingListPage;
                if (!orig || orig._patched) return;
                window.shoppingListPage = function () {
                    const obj = orig();
                    obj.renderAmount = (q) => {
                        if (q.amount === null || q.amount === undefined) return '';
                        const fields = {
                            amount: q.amount,
                            amount_high: q.amount_high ?? null,
                            unit: q.unit || null,
                            unit_class: null,
                            ingredient: '',
                            modifier: null,
                            optional: false,
                        };
                        // Use the same formatter, ingredient blank so we get just "5 cups".
                        const line = window.formatIngredient(fields);
                        return line.trim();
                    };
                    obj.impreciseSummary = (quantities) => {
                        return quantities
                            .map((q) => {
                                const u = q.unit;
                                const phrase = u === 'to-taste' ? 'to taste' : u === 'as-needed' ? 'as needed' : `a ${u || 'pinch'}`;
                                return phrase;
                            })
                            .join(', ');
                    };
                    return obj;
                };
                window.shoppingListPage._patched = true;
            })();
        </script>
    </div>
@endsection
