@extends('layouts.app')

@section('title', $recipe->title.' — Recipe Machine')

@php
    use Illuminate\Support\Str;

    /** @var \App\Recipes\Display\IngredientFormatter $ingredientFormatter */
    /** @var \App\Recipes\Display\MethodFormatter $methodFormatter */
    /** @var \App\Models\Recipe $recipe */

    // Render notes markdown with cross-references resolved.
    $notesHtml = null;
    if ($recipe->notes) {
        $resolved = [];
        foreach ($recipe->references as $ref) {
            $resolved[$ref->referenced_slug] = $ref->resolved_recipe_id !== null
                ? $ref->resolvedRecipe
                : null;
        }
        // Replace [[slug-or-title]] with links/bold BEFORE running through markdown,
        // since Str::markdown would otherwise escape the brackets.
        $notesText = preg_replace_callback('/\[\[(.+?)\]\]/u', function ($m) use ($resolved) {
            $key = $m[1];
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $key)));
            $slug = trim($slug, '-');
            if (isset($resolved[$slug]) && $resolved[$slug] !== null) {
                $url = route('recipes.show', ['recipe' => $resolved[$slug]->slug]);
                return '['.$resolved[$slug]->title.']('.$url.')';
            }
            return '**'.$m[1].'**';
        }, $recipe->notes);

        // Phase 8: after explicit bracket refs are resolved, AutoLinker picks
        // up any bare recipe titles mentioned in prose and wraps the first
        // occurrence of each in a markdown link. Bracket-resolved links and
        // existing markdown links/code spans are skipped.
        $notesText = app(\App\Recipes\Render\AutoLinker::class)
            ->link($notesText, $autoLinkIndex, $recipe->slug);

        // Use Laravel's helper which wraps CommonMark.
        $notesHtml = Str::markdown($notesText);
    }

    $libationText = $recipe->libation_prose ?: $recipe->libation;
    $unparsedCount = $recipe->ingredients->where('parsed', false)->count();
@endphp

@section('content')
    <nav class="mb-6 text-sm text-stone-500 dark:text-stone-500">
        <a href="{{ url('/') }}" class="hover:text-amber-700 dark:hover:text-amber-400">Recipes</a>
        <span class="mx-2">·</span>
        <a href="{{ route('categories.show', ['category' => $recipe->category]) }}"
           class="hover:text-amber-700 dark:hover:text-amber-400">{{ ucfirst($recipe->category) }}</a>
        <span class="mx-2">·</span>
        <span>{{ $recipe->title }}</span>
    </nav>

    {{-- HEADER --}}
    <header class="mb-8 border-b border-stone-200 pb-6 dark:border-stone-800">
        <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">{{ $recipe->title }}</h1>

        {{-- METADATA STRIP --}}
        @php
            $metaFields = collect([
                'Serves' => $recipe->servings,
                'Yields' => $recipe->yields,
                'Prep'   => $recipe->prep_time,
                'Cook'   => $recipe->cook_time,
                'Total'  => $recipe->total_time,
                'Oven'   => $recipe->oven_temp,
                'Difficulty' => $recipe->difficulty,
            ])->filter(fn ($v) => $v !== null && $v !== '');
        @endphp
        @if ($metaFields->isNotEmpty())
            <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                @foreach ($metaFields as $label => $value)
                    <div class="flex items-baseline gap-1.5">
                        <dt class="font-medium text-stone-600 dark:text-stone-400">{{ $label }}:</dt>
                        <dd class="text-stone-900 dark:text-stone-100">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($recipe->tags->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($recipe->tags as $tag)
                    <span class="inline-block rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-400">
                        {{ $tag->tag }}
                    </span>
                @endforeach
            </div>
        @endif
    </header>

    {{-- STEPPER + SHOPPING LIST + COOK + EDIT BUTTON ROW --}}
    <div class="mb-8 flex items-center gap-5 flex-wrap" x-data="{ slug: '{{ $recipe->slug }}' }">
        @if ($recipe->methodSteps->isNotEmpty())
            <a href="{{ route('recipes.cook', ['recipe' => $recipe->slug]) }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-800 hover:bg-stone-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-200 dark:hover:bg-stone-800">
                <span aria-hidden="true">▶</span> Cook
            </a>
        @endif
        <a href="{{ route('recipes.edit', ['recipe' => $recipe->slug]) }}"
           class="inline-flex items-center gap-1.5 text-sm text-stone-600 hover:text-amber-700 dark:text-stone-400 dark:hover:text-amber-400">
            <span aria-hidden="true">✎</span> Edit
        </a>
        @if ($recipe->yields !== null && $recipe->yields > 0)
            <div x-data="recipeScale('{{ $recipe->slug }}', {{ $recipe->yields }})" class="flex items-center gap-3 flex-wrap" data-testid="servings-stepper">
                <label for="servings-input" class="font-medium text-sm text-stone-700 dark:text-stone-300">
                    Servings:
                </label>
                <div class="inline-flex items-center gap-1">
                    <button type="button" @click="decrement"
                            class="rounded border border-stone-300 px-2.5 py-1 text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800"
                            aria-label="Decrease servings">−</button>
                    <input id="servings-input" type="number" x-model.number="servings"
                           :min="1" :max="defaultServings * 2"
                           class="w-16 rounded border border-stone-300 px-2 py-1 text-center text-stone-900 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                    <button type="button" @click="increment"
                            class="rounded border border-stone-300 px-2.5 py-1 text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800"
                            aria-label="Increase servings">+</button>
                </div>
                <span x-show="servings !== defaultServings" x-cloak x-text="scaledLabel"
                      class="text-sm font-medium text-amber-700 dark:text-amber-400"></span>
                {{-- Shopping list button — when the recipe has yields, the button knows the current scale. --}}
                <template x-if="!$store.shoppingList.has(slug)">
                    <button type="button"
                            @click="$store.shoppingList.add(slug, scale)"
                            class="ml-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                        <span aria-hidden="true">+</span> Add to shopping list
                    </button>
                </template>
                <template x-if="$store.shoppingList.has(slug)">
                    <span class="ml-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-100 px-3 py-1.5 text-sm font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-200">
                        <span aria-hidden="true">✓</span>
                        In shopping list
                        <button type="button" @click="$store.shoppingList.remove(slug)"
                                class="ml-1 text-amber-900/70 hover:text-rose-700 dark:text-amber-200/70 dark:hover:text-rose-400"
                                aria-label="Remove from shopping list">×</button>
                    </span>
                </template>
            </div>
        @else
            {{-- Recipes without yields: standalone Add button. --}}
            <template x-if="!$store.shoppingList.has(slug)">
                <button type="button"
                        @click="$store.shoppingList.add(slug, 1)"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                    <span aria-hidden="true">+</span> Add to shopping list
                </button>
            </template>
            <template x-if="$store.shoppingList.has(slug)">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-100 px-3 py-1.5 text-sm font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-200">
                    <span aria-hidden="true">✓</span>
                    In shopping list
                    <button type="button" @click="$store.shoppingList.remove(slug)"
                            class="ml-1 text-amber-900/70 hover:text-rose-700 dark:text-amber-200/70 dark:hover:text-rose-400"
                            aria-label="Remove from shopping list">×</button>
                </span>
            </template>
        @endif
    </div>

    {{-- INGREDIENTS + METHOD GRID --}}
    <div class="grid grid-cols-1 gap-10 lg:grid-cols-[280px_1fr]">

        {{-- INGREDIENTS SIDEBAR --}}
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <h2 class="font-display text-xl font-semibold mb-4 text-stone-900 dark:text-stone-100">Ingredients</h2>

            @if ($recipe->ingredients->isEmpty())
                @php
                    // Stub-recipe pattern: zero ingredients + at least one resolved
                    // frontmatter reference (e.g. "Pretzel Bread Loaves" uses the
                    // same dough as "Big Soft Pretzels"). Point the reader at
                    // those source recipes instead of an inert placeholder.
                    $resolvedRefs = $recipe->references
                        ->where('source', 'frontmatter')
                        ->filter(fn ($r) => $r->resolved_recipe_id !== null);
                @endphp
                @if ($resolvedRefs->isNotEmpty())
                    <p class="text-sm text-stone-700 dark:text-stone-300 leading-snug">
                        See
                        @foreach ($resolvedRefs as $ref)
                            <a href="{{ route('recipes.show', ['recipe' => $ref->resolvedRecipe->slug]) }}"
                               class="text-amber-700 underline decoration-amber-700/30 underline-offset-2 hover:decoration-amber-700 dark:text-amber-300 dark:decoration-amber-300/30 dark:hover:decoration-amber-300">{{ $ref->resolvedRecipe->title }}</a>{{ ! $loop->last ? ' and ' : '' }}
                        @endforeach
                        for ingredients.
                    </p>
                @else
                    <p class="text-sm italic text-stone-500 dark:text-stone-500">
                        (No ingredients recorded)
                    </p>
                @endif
            @else
                @foreach ($groupedIngredients as $groupName => $items)
                    @if ($groupName !== '')
                        <h3 class="font-display text-base font-semibold mt-4 mb-2 text-stone-700 dark:text-stone-300">
                            {{ $groupName }}
                        </h3>
                    @endif
                    <ul class="space-y-1.5 text-stone-800 dark:text-stone-200 mb-4">
                        @foreach ($items as $ing)
                            <li class="leading-snug">
                                @if ($ing->parsed)
                                    <span
                                        @if ($ing->amount !== null)data-amount="{{ $ing->amount }}"@endif
                                        @if ($ing->amount_high !== null)data-amount-high="{{ $ing->amount_high }}"@endif
                                        @if ($ing->unit !== null)data-unit="{{ $ing->unit }}"@endif
                                        @if ($ing->unit_class !== null)data-unit-class="{{ $ing->unit_class }}"@endif
                                        @if ($ing->ingredient !== null)data-ingredient="{{ $ing->ingredient }}"@endif
                                        @if ($ing->modifier !== null)data-modifier="{{ $ing->modifier }}"@endif
                                        @if ($ing->optional)data-optional="1"@endif
                                    >{{ $ingredientFormatter->format($ing) }}</span>
                                    @if ($ing->llm_parsed)
                                        {{-- Phase 9: signal that this line was inferred by the LLM
                                             fallback, not the rules-based parser. Maintainer cue. --}}
                                        <span class="ml-1 cursor-help text-amber-600 dark:text-amber-400"
                                              title="Auto-parsed by Claude — verify if it looks off."
                                              aria-label="auto-parsed">✨</span>
                                    @endif
                                    @if ($ing->note)
                                        <span class="text-sm text-stone-500 dark:text-stone-500"> — {{ $ing->note }}</span>
                                    @endif
                                @else
                                    <span class="italic text-stone-700 dark:text-stone-300">{{ $ing->raw }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                @if ($unparsedCount > 0)
                    <p class="mt-2 text-xs italic text-stone-400 dark:text-stone-600">
                        ({{ $unparsedCount }} {{ \Illuminate\Support\Str::plural('line', $unparsedCount) }} couldn't be auto-parsed)
                    </p>
                @endif
            @endif
        </aside>

        {{-- METHOD MAIN COLUMN --}}
        <div>
            <h2 class="font-display text-xl font-semibold mb-4 text-stone-900 dark:text-stone-100">Method</h2>

            @if ($recipe->methodSteps->isEmpty())
                <p class="text-sm italic text-stone-500 dark:text-stone-500">
                    (No instructions recorded — see notes)
                </p>
            @else
                <ol class="space-y-4 text-stone-800 dark:text-stone-200">
                    @foreach ($recipe->methodSteps as $i => $step)
                        <li class="flex gap-4 leading-relaxed">
                            <span class="font-display text-amber-700 dark:text-amber-400 flex-none w-7 text-right">{{ $i + 1 }}.</span>
                            <span class="flex-1">{!! $methodFormatter->format($step->content) !!}</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            {{-- LIBATION --}}
            @if ($libationText)
                <aside class="mt-10 rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <h3 class="font-display text-base font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300 mb-2">
                        Libation
                    </h3>
                    <p class="text-stone-800 dark:text-stone-200 italic">{{ $libationText }}</p>
                </aside>
            @endif

            {{-- NOTES --}}
            @if ($notesHtml)
                <section class="mt-10">
                    <h2 class="font-display text-xl font-semibold mb-4 text-stone-900 dark:text-stone-100">Notes</h2>
                    <div class="prose-notes">{!! $notesHtml !!}</div>
                </section>
            @endif

            {{-- REFERENCED BY --}}
            @if ($recipe->referencedBy->isNotEmpty())
                <section class="mt-10 border-t border-stone-200 pt-6 dark:border-stone-800">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-3">
                        Referenced by
                    </h2>
                    <ul class="space-y-1 text-sm">
                        @foreach ($recipe->referencedBy as $back)
                            <li>
                                <a href="{{ route('recipes.show', ['recipe' => $back->recipe->slug]) }}"
                                   class="text-amber-700 hover:underline dark:text-amber-400">
                                    {{ $back->recipe->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- SIMILAR RECIPES (Phase 8 see-also) --}}
            @if ($recipe->seeAlso->isNotEmpty())
                <section class="mt-10 border-t border-stone-200 pt-6 dark:border-stone-800" data-testid="similar-recipes">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-3">
                        Similar recipes
                    </h2>
                    <ul class="flex flex-wrap gap-3">
                        @foreach ($recipe->seeAlso as $sa)
                            @if ($sa->related)
                                <li>
                                    <a href="{{ route('recipes.show', ['recipe' => $sa->related->slug]) }}"
                                       class="block min-w-[180px] rounded-lg border border-stone-200 bg-white px-3 py-2 hover:border-amber-400 hover:bg-amber-50 dark:border-stone-800 dark:bg-stone-900 dark:hover:border-amber-700 dark:hover:bg-amber-950/30">
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="font-display font-semibold text-stone-900 dark:text-stone-100">{{ $sa->related->title }}</span>
                                            <span class="shrink-0 rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-400">{{ $sa->score }}% match</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-stone-500 dark:text-stone-500">{{ ucfirst($sa->related->category) }}</div>
                                    </a>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
@endsection
