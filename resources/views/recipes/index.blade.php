@extends('layouts.app')

@section('title', 'All recipes — Recipe Machine')

@section('content')
    <header class="mb-6">
        <h1 class="font-display text-3xl font-semibold tracking-tight">All recipes</h1>
        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
            {{ $recipes->count() }} recipes total. Use the filter to find one by title, or scan the cross-links to spot connections.
        </p>
    </header>

    <div x-data="{ q: '' }" class="space-y-4">
        <input type="search" x-model="q" placeholder="Filter by title…" autocomplete="off"
               class="w-full max-w-md rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">

        <ul class="divide-y divide-stone-200 dark:divide-stone-800">
            @foreach ($recipes as $recipe)
                @php
                    $outgoing = $recipe->references->filter(fn ($r) => $r->resolvedRecipe !== null);
                    $incoming = $recipe->referencedBy;
                @endphp
                <li class="py-4"
                    data-title="{{ Str::lower($recipe->title) }}"
                    x-show="q === '' || '{{ Str::lower($recipe->title) }}'.includes(q.toLowerCase())">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
                           class="font-display text-lg font-semibold text-stone-900 hover:text-amber-700 dark:text-stone-100 dark:hover:text-amber-400">
                            {{ $recipe->title }}
                        </a>
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-400">
                            {{ ucfirst($recipe->category) }}
                        </span>
                        @if ($recipe->cook_time)
                            <span class="text-xs text-stone-500 dark:text-stone-500">Cook {{ $recipe->cook_time }}</span>
                        @endif
                        @if ($recipe->oven_temp)
                            <span class="text-xs text-stone-500 dark:text-stone-500">{{ $recipe->oven_temp }}</span>
                        @endif
                    </div>

                    @if ($outgoing->isNotEmpty())
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="text-stone-400 dark:text-stone-600">linked to:</span>
                            @foreach ($outgoing as $ref)
                                <a href="{{ route('recipes.show', ['recipe' => $ref->resolvedRecipe->slug]) }}"
                                   class="inline-flex items-center rounded-full border border-stone-200 bg-white px-2 py-0.5 text-stone-700 hover:border-amber-400 hover:text-amber-700 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:border-amber-600 dark:hover:text-amber-400">
                                    → {{ $ref->resolvedRecipe->title }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if ($incoming->isNotEmpty())
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="text-stone-400 dark:text-stone-600">linked from:</span>
                            @foreach ($incoming as $ref)
                                <a href="{{ route('recipes.show', ['recipe' => $ref->recipe->slug]) }}"
                                   class="inline-flex items-center rounded-full border border-stone-200 bg-white px-2 py-0.5 text-stone-700 hover:border-amber-400 hover:text-amber-700 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:border-amber-600 dark:hover:text-amber-400">
                                    ← {{ $ref->recipe->title }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endsection
