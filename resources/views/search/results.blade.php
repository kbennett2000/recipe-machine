@extends('layouts.app')

@section('title', ($query ? '"'.$query.'" — ' : '').'Search — Recipe Machine')

@php
    use Illuminate\Support\Str;

    /** @var \App\Recipes\Search\SearchResults $results */

    // Helper: a URL that mutates the current query string. Used by filter pills.
    $urlWith = function (array $overrides) use ($query, $activeCategoryFilters, $activeTagFilters) {
        $params = [];
        if ($query !== '') {
            $params['q'] = $query;
        }
        $cats = $overrides['category'] ?? $activeCategoryFilters;
        if (! empty($cats)) {
            $params['category'] = array_values($cats);
        }
        $tags = $overrides['tag'] ?? $activeTagFilters;
        if (! empty($tags)) {
            $params['tag'] = array_values($tags);
        }
        return route('search', $params);
    };
@endphp

@section('content')
    {{-- HEADER --}}
    <header class="mb-8">
        @if ($query !== '')
            <h1 class="font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                <span class="text-stone-600 dark:text-stone-400">Results for</span>
                <span>"{{ $query }}"</span>
            </h1>
        @else
            <h1 class="font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                Filtered recipes
            </h1>
        @endif
        <p class="mt-2 text-base text-stone-500 dark:text-stone-500">
            {{ $results->count() }} {{ \Illuminate\Support\Str::plural('result', $results->count()) }}
            @if ($results->truncated)
                — showing top {{ $results->cap }}
            @endif
        </p>

        {{-- Active filter pills (removable) --}}
        @if ($activeCategoryFilters !== [] || $activeTagFilters !== [])
            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-stone-500 dark:text-stone-500">Filters:</span>
                @foreach ($activeCategoryFilters as $cat)
                    <a href="{{ $urlWith(['category' => array_diff($activeCategoryFilters, [$cat])]) }}"
                       class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-amber-900 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-200 dark:hover:bg-amber-900/60">
                        category: {{ $cat }}
                        <span aria-hidden="true">×</span>
                    </a>
                @endforeach
                @foreach ($activeTagFilters as $tag)
                    <a href="{{ $urlWith(['tag' => array_diff($activeTagFilters, [$tag])]) }}"
                       class="inline-flex items-center gap-1.5 rounded-full bg-stone-200 px-3 py-1 text-stone-800 hover:bg-stone-300 dark:bg-stone-700 dark:text-stone-200 dark:hover:bg-stone-600">
                        tag: {{ $tag }}
                        <span aria-hidden="true">×</span>
                    </a>
                @endforeach
            </div>
        @endif
    </header>

    <div class="grid grid-cols-1 gap-10 lg:grid-cols-[220px_1fr]">

        {{-- SIDEBAR: filters --}}
        <aside>
            <h2 class="font-display text-base font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-3">
                Filter
            </h2>
            @if (count($availableCategories) > 0)
                <p class="text-xs text-stone-500 dark:text-stone-500 mb-2">Category</p>
                <ul class="space-y-1 text-sm">
                    @foreach ($availableCategories as $cat)
                        @php
                            $isActive = in_array($cat, $activeCategoryFilters, true);
                            $nextCats = $isActive
                                ? array_diff($activeCategoryFilters, [$cat])
                                : array_merge($activeCategoryFilters, [$cat]);
                        @endphp
                        <li>
                            <a href="{{ $urlWith(['category' => array_values($nextCats)]) }}"
                               class="inline-flex items-center gap-2 {{ $isActive
                                   ? 'text-amber-700 font-medium dark:text-amber-300'
                                   : 'text-stone-700 hover:text-amber-700 dark:text-stone-300 dark:hover:text-amber-400' }}">
                                <span class="inline-block w-3 text-center">{{ $isActive ? '✓' : '·' }}</span>
                                {{ ucfirst($cat) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>

        {{-- MAIN: results list --}}
        <div>
            @if ($results->isEmpty())
                <section class="rounded-lg border border-dashed border-stone-200 bg-stone-50 p-8 text-center dark:border-stone-800 dark:bg-stone-900/40">
                    <p class="font-display text-lg text-stone-700 dark:text-stone-300">
                        @if ($query !== '')
                            No recipes match "{{ $query }}".
                        @else
                            No recipes match those filters.
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-stone-500 dark:text-stone-500">
                        Try a different word or remove filters.
                    </p>
                    @if ($query !== '')
                        <div class="mt-5">
                            <p class="text-xs text-stone-500 dark:text-stone-500">Or try:</p>
                            <div class="mt-2 flex flex-wrap justify-center gap-2">
                                @foreach ($suggestedTerms as $term)
                                    <a href="{{ route('search', ['q' => $term]) }}"
                                       class="inline-block rounded-full border border-stone-200 bg-white px-3 py-1 text-sm text-stone-700 transition hover:border-amber-400 hover:text-amber-700 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:border-amber-700 dark:hover:text-amber-400">
                                        {{ $term }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @else
                <ul class="space-y-5">
                    @foreach ($results->results as $hit)
                        @php
                            $strongest = $hit->snippets[array_key_first($hit->snippets)] ?? null;
                        @endphp
                        <li>
                            <a href="{{ route('recipes.show', ['recipe' => $hit->recipe->slug]) }}"
                               class="group block rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-400 hover:shadow-md dark:border-stone-800 dark:bg-stone-900 dark:hover:border-amber-700">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h3 class="font-display text-xl font-semibold text-stone-900 group-hover:text-amber-700 dark:text-stone-100 dark:group-hover:text-amber-400">
                                        {{ $hit->recipe->title }}
                                    </h3>
                                    <span class="text-xs uppercase tracking-wider text-stone-500 dark:text-stone-500">
                                        {{ $hit->recipe->category }}
                                    </span>
                                </div>

                                @if ($strongest)
                                    <p class="mt-3 text-sm text-stone-600 dark:text-stone-400 leading-relaxed">
                                        {!! $strongest !!}
                                    </p>
                                @endif

                                @if ($hit->matchedIn !== [])
                                    <p class="mt-3 text-xs text-stone-500 dark:text-stone-500">
                                        matched in: {{ implode(', ', $hit->matchedIn) }}
                                    </p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
