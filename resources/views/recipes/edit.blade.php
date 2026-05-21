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

    @if ($errors->has('save'))
        <div class="mb-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-rose-900 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-medium">Save failed</p>
            <p class="mt-1 text-sm">{{ $errors->first('save') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('recipes.update', ['recipe' => $recipe->slug]) }}"
          x-data="{ pristine: true }"
          @submit="pristine = true">
        @csrf
        <label for="markdown" class="sr-only">Recipe markdown</label>
        <textarea id="markdown" name="markdown" rows="40"
                  spellcheck="false" autocomplete="off"
                  x-on:input="pristine = false"
                  x-on:keydown.tab.prevent="
                      const el = $event.target;
                      const start = el.selectionStart;
                      const end = el.selectionEnd;
                      el.value = el.value.substring(0, start) + '  ' + el.value.substring(end);
                      el.selectionStart = el.selectionEnd = start + 2;
                  "
                  class="w-full max-w-[900px] rounded border border-stone-300 bg-white px-4 py-3 text-sm leading-relaxed shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                  style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', 'JetBrains Mono', Menlo, Consolas, monospace; tab-size: 2;">{{ old('markdown', $markdown) }}</textarea>

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
            <li>Saving triggers a single-recipe reindex; large recipes (lots of ingredients or method steps) may take a moment.</li>
            <li>Tab key inserts two spaces inside the textarea (not browser focus-skip).</li>
        </ul>
    </aside>
@endsection
