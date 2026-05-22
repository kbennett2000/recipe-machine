@extends('layouts.app')

@section('title', 'Recipe Machine')

@section('content')
    @php $navCategories = $categories; @endphp

    <section class="mb-12">
        <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">Recipe Machine</h1>
        <p class="mt-3 text-lg text-stone-600 dark:text-stone-400 max-w-2xl">
            A self-hosted recipe library. {{ $totalRecipes }} recipes across {{ collect($categories)->where('count', '>', 0)->count() }} categories, parsed from markdown files on disk.
        </p>
    </section>

    <section class="mb-14">
        <div class="mb-5 flex items-baseline justify-between">
            <h2 class="font-display text-xl font-semibold text-stone-900 dark:text-stone-100">Browse by category</h2>
            <a href="{{ route('recipes.create') }}"
               data-testid="home-new-recipe"
               class="inline-flex items-center rounded-lg border border-amber-400 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                + New recipe
            </a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($categories as $cat)
                @if ($cat['count'] > 0)
                    <a href="{{ route('categories.show', ['category' => $cat['slug']]) }}"
                       class="group block rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-400 hover:shadow-md dark:border-stone-800 dark:bg-stone-900 dark:hover:border-amber-700">
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-display text-xl font-semibold text-stone-900 group-hover:text-amber-700 dark:text-stone-100 dark:group-hover:text-amber-400">
                                {{ $cat['label'] }}
                            </h3>
                            <span class="text-sm font-medium text-stone-500 dark:text-stone-500">
                                {{ $cat['count'] }} {{ \Illuminate\Support\Str::plural('recipe', $cat['count']) }}
                            </span>
                        </div>
                    </a>
                @else
                    <div class="rounded-lg border border-dashed border-stone-200 bg-stone-50 p-5 dark:border-stone-800 dark:bg-stone-900/40">
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-display text-xl font-semibold text-stone-400 dark:text-stone-600">
                                {{ $cat['label'] }}
                            </h3>
                            <span class="text-xs uppercase tracking-wider text-stone-400 dark:text-stone-600">
                                Coming soon
                            </span>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </section>

    @if ($recent->isNotEmpty())
        <section>
            <h2 class="font-display text-xl font-semibold mb-5 text-stone-900 dark:text-stone-100">Recently updated</h2>
            <ul class="divide-y divide-stone-200 border-t border-b border-stone-200 dark:divide-stone-800 dark:border-stone-800">
                @foreach ($recent as $r)
                    <li>
                        <a href="{{ route('recipes.show', ['recipe' => $r->slug]) }}"
                           class="flex items-baseline justify-between gap-3 py-3 hover:text-amber-700 dark:hover:text-amber-400 transition">
                            <span class="font-display text-base">{{ $r->title }}</span>
                            <span class="text-xs text-stone-500 dark:text-stone-500 uppercase tracking-wider">
                                {{ $r->category }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
