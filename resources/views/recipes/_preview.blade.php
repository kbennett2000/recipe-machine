{{--
    Phase 11E — recipe preview partial.

    Renders against a ParsedRecipe (not a DB Recipe), since the editor
    might POST state that hasn't been persisted yet. Strips chrome
    (no nav, no actions, no shopping-list buttons) — this is a content
    preview only.
--}}
@php
    /** @var \App\Recipes\Parser\ParsedRecipe $parsed */
    /** @var \App\Recipes\Display\IngredientFormatter $ingredientFormatter */
    /** @var \App\Recipes\Display\MethodFormatter $methodFormatter */
    $fm = $parsed->frontmatter;

    // Group ingredients by sub-group, top-level first.
    $groups = [];
    foreach ($parsed->ingredients as $ing) {
        $key = $ing->group ?? '';
        $groups[$key][] = $ing;
    }
@endphp

<article class="text-stone-900 dark:text-stone-100">
    <header class="mb-4 border-b border-stone-200 pb-3 dark:border-stone-800">
        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ $fm->title }}</h1>
        @php
            $metaFields = collect([
                'Serves' => $fm->servings,
                'Yields' => $fm->yields,
                'Cook'   => $fm->cookTime,
                'Oven'   => $fm->ovenTemp,
            ])->filter(fn ($v) => $v !== null && $v !== '');
        @endphp
        @if ($metaFields->isNotEmpty())
            <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-600 dark:text-stone-400">
                @foreach ($metaFields as $label => $value)
                    <div><dt class="inline font-medium">{{ $label }}:</dt> <dd class="inline">{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif
        @if ($fm->tags)
            <div class="mt-2 flex flex-wrap gap-1">
                @foreach ($fm->tags as $tag)
                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[10px] text-stone-600 dark:bg-stone-800 dark:text-stone-400">{{ $tag }}</span>
                @endforeach
            </div>
        @endif
    </header>

    @if (! empty($parsed->ingredients))
        <section class="mb-4">
            <h2 class="font-display text-base font-semibold mb-2">Ingredients</h2>
            @foreach ($groups as $groupName => $items)
                @if ($groupName !== '')
                    <h3 class="font-display text-sm font-semibold mt-3 mb-1 text-stone-700 dark:text-stone-300">{{ $groupName }}</h3>
                @endif
                <ul class="space-y-1 text-sm">
                    @foreach ($items as $ing)
                        <li class="leading-snug">
                            @if ($ing->parsed)
                                {{ $ingredientFormatter->formatFields([
                                    'amount' => is_numeric($ing->amount) ? (float) $ing->amount : null,
                                    'amount_high' => $ing->amountHigh,
                                    'unit' => $ing->unit,
                                    'unit_class' => null,
                                    'ingredient' => $ing->ingredient,
                                    'modifier' => $ing->modifier,
                                    'optional' => $ing->optional,
                                ]) }}
                                @if ($ing->note)<span class="text-xs text-stone-500 dark:text-stone-500"> — {{ $ing->note }}</span>@endif
                            @else
                                <span class="italic text-stone-700 dark:text-stone-300">{{ $ing->raw }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>
    @endif

    @if (! empty($parsed->method))
        <section class="mb-4">
            <h2 class="font-display text-base font-semibold mb-2">Method</h2>
            <ol class="space-y-2 text-sm">
                @foreach ($parsed->method as $i => $step)
                    <li class="flex gap-2 leading-relaxed">
                        <span class="font-display text-amber-700 dark:text-amber-400 flex-none w-5 text-right">{{ $i + 1 }}.</span>
                        <span class="flex-1">{!! $methodFormatter->format($step) !!}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($fm->libation || $parsed->libationProse)
        <aside class="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
            <h3 class="font-display text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300">Libation</h3>
            <p class="text-sm italic">{{ $parsed->libationProse ?: $fm->libation }}</p>
        </aside>
    @endif

    @if ($parsed->notes)
        <section class="mb-4">
            <h2 class="font-display text-base font-semibold mb-2">Notes</h2>
            <div class="prose-sm prose-stone dark:prose-invert text-sm">{!! \Illuminate\Support\Str::markdown($parsed->notes) !!}</div>
        </section>
    @endif
</article>
