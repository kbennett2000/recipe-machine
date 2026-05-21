@extends('layouts.app')

@section('title', 'Search — Recipe Machine')

@section('content')
    <section class="max-w-2xl">
        <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">Search</h1>
        <p class="mt-3 text-lg text-stone-600 dark:text-stone-400">
            Search across recipe titles, ingredients, method, and notes.
        </p>

        <form action="{{ route('search') }}" method="get" class="mt-8">
            <label for="search-landing-input" class="sr-only">Search recipes</label>
            <input
                id="search-landing-input"
                type="search"
                name="q"
                autofocus
                placeholder="Search recipes…"
                class="w-full rounded-lg border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 placeholder-stone-400 shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100 dark:placeholder-stone-500 dark:focus:border-amber-600"
            >
        </form>

        <div class="mt-8">
            <p class="text-sm text-stone-500 dark:text-stone-500">Try one of these:</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($suggestedTerms as $term)
                    <a href="{{ route('search', ['q' => $term]) }}"
                       class="inline-block rounded-full border border-stone-200 bg-white px-3 py-1 text-sm text-stone-700 transition hover:border-amber-400 hover:text-amber-700 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:border-amber-700 dark:hover:text-amber-400">
                        {{ $term }}
                    </a>
                @endforeach
            </div>
        </div>

        <p class="mt-8 text-xs text-stone-400 dark:text-stone-600 leading-relaxed">
            <strong class="font-medium">Tips:</strong>
            Wrap a phrase in double quotes to match it exactly — <code class="rounded bg-stone-100 px-1 dark:bg-stone-800">"no knead"</code>.
            Press <kbd class="rounded border border-stone-300 bg-stone-50 px-1 text-xs dark:border-stone-700 dark:bg-stone-800">/</kbd> from anywhere to focus the search box.
        </p>
    </section>
@endsection
