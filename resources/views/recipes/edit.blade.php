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

    <header class="mb-6 border-b border-stone-200 pb-4 dark:border-stone-800">
        <h1 class="font-display text-3xl font-semibold tracking-tight">Editing: {{ $recipe->title }}</h1>
        <p class="mt-1 text-sm text-stone-500 dark:text-stone-500">
            Editing the raw markdown source. File:
            <code class="rounded bg-stone-100 px-1 py-0.5 text-xs dark:bg-stone-800">{{ $recipe->source_path }}</code>
        </p>
    </header>

    {{-- Phase 11D.1: sticky error banner. Stays pinned to the top while the
         user scrolls through a long recipe so the failure reason is always
         visible. Only applied to this page; the global flash banner on
         /recipes/{slug} keeps its regular layout flow. --}}
    @if ($errors->has('save'))
        <div class="sticky top-0 z-20 mb-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-rose-900 shadow-md dark:border-rose-700 dark:bg-rose-950/90 dark:text-rose-200">
            <p class="font-medium">Save failed</p>
            <p class="mt-1 text-sm">{{ $errors->first('save') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('recipes.update', ['recipe' => $recipe->slug]) }}">
        @csrf
        <label for="markdown" class="sr-only">Recipe markdown</label>

        {{-- Phase 11D.1: textarea-overlay syntax cues.
             A `<pre>` is positioned absolutely behind a transparent-text
             textarea. Both elements share font, padding, and line-height
             so the highlighted "shadow" lines up exactly with the user's
             input. Alpine writes the shadow's innerHTML on every keystroke;
             the textarea remains the source of truth.

             If the overlay ever drifts (a font issue, a tab-size
             mismatch), the worst case is "no color cue" — the textarea
             stays fully usable since it owns the actual text. --}}
        <div class="relative w-full max-w-[900px]" x-data="markdownEditor()" x-init="init()">
            <pre x-ref="shadow"
                 aria-hidden="true"
                 class="pointer-events-none absolute inset-0 m-0 rounded border border-transparent px-4 py-3 text-sm leading-relaxed text-stone-700 dark:text-stone-300 overflow-hidden whitespace-pre-wrap break-words"
                 style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', 'JetBrains Mono', Menlo, Consolas, monospace; tab-size: 2;"></pre>
            <textarea id="markdown" name="markdown" rows="40"
                      x-ref="textarea"
                      spellcheck="false" autocomplete="off"
                      x-on:input="render()"
                      x-on:scroll="syncScroll()"
                      x-on:keydown="onKeydown($event)"
                      class="relative w-full rounded border border-stone-300 bg-white/0 px-4 py-3 text-sm leading-relaxed shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-900/0 dark:text-stone-100"
                      style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', 'JetBrains Mono', Menlo, Consolas, monospace; tab-size: 2; caret-color: rgb(217 119 6); color: transparent; -webkit-text-fill-color: transparent;">{{ old('markdown', $markdown) }}</textarea>
        </div>

        <div class="mt-4 flex items-center gap-4">
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

    <aside class="mt-10 max-w-[900px] rounded-lg border border-stone-200 bg-stone-50 p-4 text-sm text-stone-700 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-300">
        <h2 class="font-display font-semibold text-base mb-2 text-stone-900 dark:text-stone-100">Tips</h2>
        <ul class="space-y-1 list-disc list-outside ml-5">
            <li>Editing the <code class="text-xs">slug</code> or <code class="text-xs">category</code> isn't supported here — that's coming in a later phase.</li>
            <li>Markdown reference: <a href="https://github.com/kbennett2000/recipe-machine/blob/main/docs/recipe-format.md" target="_blank" rel="noopener noreferrer" class="text-amber-700 underline hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">recipe-format.md</a>.</li>
            <li>Saving triggers a single-recipe reindex; large recipes may take a moment.</li>
            <li><kbd class="rounded border border-stone-300 bg-white px-1 text-xs dark:border-stone-700 dark:bg-stone-800">Tab</kbd> indents; <kbd class="rounded border border-stone-300 bg-white px-1 text-xs dark:border-stone-700 dark:bg-stone-800">Shift+Tab</kbd> unindents; <kbd class="rounded border border-stone-300 bg-white px-1 text-xs dark:border-stone-700 dark:bg-stone-800">Esc</kbd> exits the textarea.</li>
        </ul>
    </aside>
@endsection
