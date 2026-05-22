@extends('layouts.app')

@section('title', 'Editing — '.$recipe->title)

@section('content')
    <nav class="mb-4 text-sm text-stone-500 dark:text-stone-500">
        <a href="{{ url('/') }}" class="hover:text-amber-700 dark:hover:text-amber-400">Recipes</a>
        <span class="mx-2">·</span>
        <a href="{{ route('categories.show', ['category' => $recipe->category]) }}"
           class="hover:text-amber-700 dark:hover:text-amber-400">{{ ucfirst($recipe->category) }}</a>
        <span class="mx-2">·</span>
        <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
           class="hover:text-amber-700 dark:hover:text-amber-400">{{ $recipe->title }}</a>
        <span class="mx-2">·</span>
        <span>Edit</span>
    </nav>

    <header class="mb-4 border-b border-stone-200 pb-4 dark:border-stone-800">
        <h1 class="font-display text-3xl font-semibold tracking-tight">Editing: {{ $recipe->title }}</h1>
        <p class="mt-1 text-sm text-stone-500 dark:text-stone-500">
            File: <code class="rounded bg-stone-100 px-1 py-0.5 text-xs dark:bg-stone-800">{{ $recipe->source_path }}</code>
        </p>
    </header>

    @if ($errors->has('save'))
        <div class="sticky top-0 z-30 mb-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-rose-900 shadow-md dark:border-rose-700 dark:bg-rose-950/90 dark:text-rose-200">
            <p class="font-medium">Save failed</p>
            <p class="mt-1 text-sm">{{ $errors->first('save') }}</p>
        </div>
    @endif

    @php
        $stateAttr = $initialState !== null ? htmlspecialchars(json_encode($initialState, JSON_UNESCAPED_UNICODE), ENT_QUOTES) : '';
        $canFormMode = $initialState !== null;
    @endphp

    <div x-data="recipeEditor({
                slug: '{{ $recipe->slug }}',
                initialMode: '{{ $canFormMode ? ($errors->has('save') ? 'raw' : 'form') : 'raw' }}',
                hasInitialState: {{ $canFormMode ? 'true' : 'false' }},
                routes: {
                    parse: '{{ route('recipes.edit.parse', ['recipe' => $recipe->slug]) }}',
                    serialize: '{{ route('recipes.edit.serialize', ['recipe' => $recipe->slug]) }}',
                    preview: '{{ route('recipes.edit.preview', ['recipe' => $recipe->slug]) }}',
                },
            })"
            x-init="init(@js($initialState))"
            data-initial-state="{{ $stateAttr }}"
            data-initial-markdown="{{ old('markdown', $markdown) }}"
            class="space-y-4">

        {{-- Mode toggle --}}
        <div class="flex items-center gap-2">
            <span class="text-sm text-stone-500 dark:text-stone-500">Mode:</span>
            <div class="inline-flex rounded-md border border-stone-300 bg-white p-0.5 dark:border-stone-700 dark:bg-stone-900">
                <button type="button" @click="switchMode('form')"
                        :disabled="!hasInitialState && mode === 'raw'"
                        :class="mode === 'form' ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' : 'text-stone-600 hover:text-stone-900 dark:text-stone-400 dark:hover:text-stone-100'"
                        class="rounded px-3 py-1 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed">
                    Form
                </button>
                <button type="button" @click="switchMode('raw')"
                        :class="mode === 'raw' ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' : 'text-stone-600 hover:text-stone-900 dark:text-stone-400 dark:hover:text-stone-100'"
                        class="rounded px-3 py-1 text-sm font-medium transition">
                    Raw
                </button>
            </div>
            <span x-show="modeSwitching" x-cloak class="text-xs text-stone-400 dark:text-stone-600">switching…</span>
        </div>

        <form id="edit-form" method="POST" action="{{ route('recipes.update', ['recipe' => $recipe->slug]) }}"
              @submit="onSubmit($event)">
            @csrf
            {{-- Hidden state field — populated on submit when in form mode --}}
            <input type="hidden" name="state" x-ref="stateField">

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_440px] gap-6">
                {{-- Editor column --}}
                <div class="space-y-4 min-w-0">
                    {{-- Form mode --}}
                    <div x-show="mode === 'form'" x-cloak class="space-y-6">
                        @include('recipes._edit_form')
                    </div>

                    {{-- Raw mode --}}
                    <div x-show="mode === 'raw'" x-cloak class="space-y-2">
                        <label for="markdown" class="sr-only">Recipe markdown</label>
                        <textarea id="markdown" name="markdown" rows="40"
                                  x-ref="rawTextarea"
                                  spellcheck="false" autocomplete="off"
                                  x-on:input="onRawInput()"
                                  x-on:keydown="onKeydown($event)"
                                  class="w-full rounded border border-stone-300 bg-white px-4 py-3 text-sm leading-relaxed shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                  style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace; tab-size: 2;">{{ old('markdown', $markdown) }}</textarea>
                    </div>
                </div>

                {{-- Preview column --}}
                <aside class="min-w-0">
                    <div class="lg:sticky lg:top-4">
                        <div class="mb-2 flex items-center justify-between text-sm">
                            <span class="font-medium text-stone-600 dark:text-stone-400">Live preview</span>
                            <span x-show="previewLoading" x-cloak class="text-xs text-stone-400 dark:text-stone-600">updating…</span>
                        </div>
                        <div class="rounded border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900 max-h-[80vh] overflow-y-auto"
                             x-ref="previewPane">
                            <p class="text-sm italic text-stone-400 dark:text-stone-600">Loading preview…</p>
                        </div>
                    </div>
                </aside>
            </div>

            {{-- Save button (always visible at bottom) --}}
            <div class="mt-6 flex items-center gap-4">
                <button type="submit"
                        class="inline-flex items-center rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                    Save changes
                </button>
                <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
                   class="text-sm text-stone-600 hover:text-amber-700 dark:text-stone-400 dark:hover:text-amber-400">
                    ← Back to recipe
                </a>
            </div>
        </form>

        <aside class="rounded-lg border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">
            <span class="font-semibold">Tips:</span>
            Slug and category are read-only here. Markdown reference:
            <a href="https://github.com/kbennett2000/recipe-machine/blob/main/docs/recipe-format.md" target="_blank" rel="noopener" class="text-amber-700 underline hover:text-amber-800 dark:text-amber-400">recipe-format.md</a>.
            Save triggers a single-recipe reindex.
        </aside>
    </div>
@endsection
