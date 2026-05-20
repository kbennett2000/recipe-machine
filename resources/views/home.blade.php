@extends('layouts.app')

@section('title', 'Recipe Machine')

@section('content')
    <section class="space-y-6">
        <div>
            <h1 class="text-4xl font-bold tracking-tight">Recipe Machine</h1>
            <p class="mt-3 text-lg text-stone-600 dark:text-stone-400">
                A skeleton awaiting recipes.
            </p>
        </div>

        {{-- Phase 0 Alpine smoke test — remove in Phase 1. --}}
        <div x-data="{ open: false }" class="rounded-lg border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900">
            <button
                type="button"
                @click="open = !open"
                class="inline-flex items-center rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-900 dark:hover:bg-stone-300"
            >
                <span x-text="open ? 'Hide' : 'Say hello'"></span>
            </button>
            <p x-show="open" x-cloak class="mt-3 text-stone-700 dark:text-stone-300">
                Hello — Alpine is wired up.
            </p>
        </div>
    </section>
@endsection
