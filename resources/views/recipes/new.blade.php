@extends('layouts.app')

@section('title', 'New recipe')

@section('content')
    <nav class="mb-4 text-sm text-stone-500 dark:text-stone-500">
        <a href="{{ url('/') }}" class="hover:text-amber-700 dark:hover:text-amber-400">Recipes</a>
        <span class="mx-2">·</span>
        <span>New</span>
    </nav>

    <header class="mb-4 border-b border-stone-200 pb-4 dark:border-stone-800">
        <h1 class="font-display text-3xl font-semibold tracking-tight">New recipe</h1>
        <p class="mt-1 text-sm text-stone-500 dark:text-stone-500">
            Title and category are required. The slug auto-derives from the title.
        </p>
    </header>

    @if ($errors->has('save'))
        <div class="sticky top-0 z-30 mb-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-rose-900 shadow-md dark:border-rose-700 dark:bg-rose-950/90 dark:text-rose-200">
            <p class="font-medium">Save failed</p>
            <p class="mt-1 text-sm">{{ $errors->first('save') }}</p>
        </div>
    @endif

    @php
        $stateAttr = htmlspecialchars(json_encode($initialState, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    @endphp

    <div x-data="recipeEditor({
                slug: '',
                initialMode: 'form',
                hasInitialState: true,
                isNew: true,
                routes: {
                    parse: '{{ route('recipes.create.parse') }}',
                    serialize: '{{ route('recipes.create.serialize') }}',
                    preview: '{{ route('recipes.create.preview') }}',
                },
            })"
            x-init="init(@js($initialState))"
            data-initial-state="{{ $stateAttr }}"
            data-initial-markdown="{{ old('markdown', $scaffoldMarkdown) }}"
            class="space-y-4">

        {{-- Mode toggle --}}
        <div class="flex items-center gap-2">
            <span class="text-sm text-stone-500 dark:text-stone-500">Mode:</span>
            <div class="inline-flex rounded-md border border-stone-300 bg-white p-0.5 dark:border-stone-700 dark:bg-stone-900">
                <button type="button" @click="switchMode('form')"
                        :class="mode === 'form' ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' : 'text-stone-600 hover:text-stone-900 dark:text-stone-400 dark:hover:text-stone-100'"
                        class="rounded px-3 py-1 text-sm font-medium transition">
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

        <form id="new-form" method="POST" action="{{ route('recipes.store') }}"
              @submit="onSubmit($event)">
            @csrf
            <input type="hidden" name="state" x-ref="stateField">

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_440px] gap-6">
                <div class="space-y-4 min-w-0">
                    {{-- Form mode --}}
                    <div x-show="mode === 'form'" x-cloak class="space-y-6">
                        @include('recipes._edit_form', ['isNew' => true])
                    </div>

                    {{-- Raw mode --}}
                    <div x-show="mode === 'raw'" x-cloak class="relative">
                        <label for="markdown" class="sr-only">Recipe markdown</label>
                        <pre x-ref="rawShadow"
                             aria-hidden="true"
                             class="pointer-events-none absolute inset-0 m-0 rounded border border-transparent px-4 py-3 text-sm leading-relaxed text-stone-700 dark:text-stone-300 overflow-hidden whitespace-pre-wrap break-words"
                             style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace; tab-size: 2;"></pre>
                        <textarea id="markdown" name="markdown" rows="30"
                                  x-ref="rawTextarea"
                                  spellcheck="false" autocomplete="off"
                                  x-on:input="onRawInput()"
                                  x-on:scroll="syncRawShadowScroll()"
                                  x-on:keydown="onKeydown($event)"
                                  data-markdown-editor="true"
                                  class="relative w-full rounded border border-stone-300 px-4 py-3 text-sm leading-relaxed shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700"
                                  style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace; tab-size: 2; caret-color: rgb(217 119 6); color: transparent; -webkit-text-fill-color: transparent; background-color: transparent;">{{ old('markdown', $scaffoldMarkdown) }}</textarea>
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
                            <p class="text-sm italic text-stone-400 dark:text-stone-600">Preview shows once you start filling in the form.</p>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="submit"
                        :disabled="(state.frontmatter.title || '').trim() === '' || (state.frontmatter.category || '').trim() === ''"
                        data-testid="save-new-recipe"
                        class="inline-flex items-center rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100 disabled:opacity-50 disabled:cursor-not-allowed dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                    Create recipe
                </button>
                <a href="{{ url('/') }}"
                   class="text-sm text-stone-600 hover:text-amber-700 dark:text-stone-400 dark:hover:text-amber-400">
                    Cancel
                </a>
            </div>

            {{-- Mobile sticky save bar --}}
            <div data-testid="mobile-save-bar"
                 class="lg:hidden fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 backdrop-blur px-4 py-3 shadow-[0_-2px_8px_rgba(0,0,0,0.06)] dark:border-stone-800 dark:bg-stone-900/95">
                <div class="mx-auto flex max-w-6xl items-center justify-between gap-3">
                    <a href="{{ url('/') }}"
                       class="text-sm text-stone-600 dark:text-stone-400">Cancel</a>
                    <button type="submit"
                            :disabled="(state.frontmatter.title || '').trim() === '' || (state.frontmatter.category || '').trim() === ''"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 disabled:opacity-50 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                        Create
                    </button>
                </div>
            </div>
        </form>

        <aside class="rounded-lg border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">
            <span class="font-semibold">Tips:</span>
            The slug is fixed after the first save. Choose carefully. Markdown reference: see
            <code class="rounded bg-stone-200 px-1 py-0.5 text-[0.85em] dark:bg-stone-800">docs/recipe-format.md</code> in the repo.
        </aside>
    </div>
@endsection
