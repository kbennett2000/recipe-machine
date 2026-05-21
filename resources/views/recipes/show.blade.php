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

    {{-- INGREDIENTS + METHOD GRID --}}
    <div class="grid grid-cols-1 gap-10 lg:grid-cols-[280px_1fr]">

        {{-- INGREDIENTS SIDEBAR --}}
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <h2 class="font-display text-xl font-semibold mb-4 text-stone-900 dark:text-stone-100">Ingredients</h2>

            @if ($recipe->ingredients->isEmpty())
                <p class="text-sm italic text-stone-500 dark:text-stone-500">
                    (No ingredients recorded)
                </p>
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
                                    <span>{{ $ingredientFormatter->format($ing) }}</span>
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
        </div>
    </div>
@endsection
