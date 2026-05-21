@extends('layouts.app')

@section('title', $categoryLabel.' — Recipe Machine')

@section('content')
    <nav class="mb-6 text-sm text-stone-500 dark:text-stone-500">
        <a href="{{ url('/') }}" class="hover:text-amber-700 dark:hover:text-amber-400">Recipes</a>
        <span class="mx-2">·</span>
        <span>{{ $categoryLabel }}</span>
    </nav>

    <h1 class="font-display text-4xl font-semibold tracking-tight sm:text-5xl">{{ $categoryLabel }}</h1>
    <p class="mt-2 text-base text-stone-500 dark:text-stone-500">
        {{ $recipes->count() }} {{ \Illuminate\Support\Str::plural('recipe', $recipes->count()) }}
    </p>

    <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach ($recipes as $recipe)
            <article class="rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-400 hover:shadow-md dark:border-stone-800 dark:bg-stone-900 dark:hover:border-amber-700">
                <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}" class="block">
                    <h2 class="font-display text-xl font-semibold text-stone-900 dark:text-stone-100">
                        {{ $recipe->title }}
                    </h2>

                    @if ($recipe->libation)
                        <p class="mt-2 text-sm italic text-stone-600 dark:text-stone-400 line-clamp-2">
                            With {{ $recipe->libation }}
                        </p>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500 dark:text-stone-500">
                        @if ($recipe->cook_time)
                            <span class="inline-flex items-center gap-1">
                                <span class="font-medium">Cook</span> {{ $recipe->cook_time }}
                            </span>
                        @endif
                        @if ($recipe->oven_temp)
                            <span class="inline-flex items-center gap-1">
                                <span class="font-medium">Oven</span> {{ $recipe->oven_temp }}
                            </span>
                        @endif
                        @if (! $recipe->cook_time && ! $recipe->oven_temp)
                            <span class="italic">No-cook</span>
                        @endif
                    </div>

                    @if ($recipe->unparsed_count > 0)
                        <p class="mt-3 text-xs text-stone-400 dark:text-stone-600">
                            ({{ $recipe->unparsed_count }} {{ \Illuminate\Support\Str::plural('line', $recipe->unparsed_count) }} couldn't be auto-parsed)
                        </p>
                    @endif
                </a>
            </article>
        @endforeach
    </div>
@endsection
