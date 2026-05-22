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
                initialMtime: {{ (int) $initialMtime }},
                routes: {
                    parse: '{{ route('recipes.edit.parse', ['recipe' => $recipe->slug]) }}',
                    serialize: '{{ route('recipes.edit.serialize', ['recipe' => $recipe->slug]) }}',
                    preview: '{{ route('recipes.edit.preview', ['recipe' => $recipe->slug]) }}',
                    mtime: '{{ route('recipes.edit.mtime', ['recipe' => $recipe->slug]) }}',
                    parseLine: '{{ route('recipes.edit.parseLine', ['recipe' => $recipe->slug]) }}',
                },
            })"
            x-init="init(@js($initialState))"
            data-initial-state="{{ $stateAttr }}"
            data-initial-markdown="{{ old('markdown', $markdown) }}"
            data-initial-mtime="{{ (int) $initialMtime }}"
            class="space-y-4">

        {{-- Phase 11H — stale-file banner. The recipeEditor Alpine component
             polls /edit/mtime every ~10s and flips `fileChanged` if the
             file on disk has been modified since this page rendered. The
             banner sits at the top of the editor area in amber — visible
             but non-blocking. The user can Reload to pick up the new
             version or just keep editing and Save (the existing save path
             overwrites unconditionally — the user accepts the clobber by
             choosing to save). --}}
        <div x-show="fileChanged" x-cloak data-testid="stale-file-banner"
             class="rounded border border-amber-400 bg-amber-50 px-4 py-3 text-amber-900 shadow-sm dark:border-amber-600 dark:bg-amber-950/60 dark:text-amber-100">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-medium">The file has changed on disk since you opened it.</p>
                    <p class="text-sm">Your edits may overwrite changes made elsewhere.</p>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <a href="{{ route('recipes.edit', ['recipe' => $recipe->slug]) }}"
                       data-testid="stale-banner-reload"
                       class="inline-flex items-center rounded border border-amber-500 bg-white px-3 py-1.5 font-medium text-amber-900 hover:bg-amber-100 dark:border-amber-500 dark:bg-amber-900/40 dark:text-amber-100 dark:hover:bg-amber-900/60">
                        Reload
                    </a>
                    <span class="text-xs text-amber-800/80 dark:text-amber-200/70">or click Save below to accept your version.</span>
                </div>
            </div>
        </div>

        {{-- Mode toggle + dirty indicator --}}
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
            {{-- Phase 11F dirty indicator: amber dot + tooltip. Hidden until
                 the user's first edit; cleared on successful save. --}}
            <span x-show="dirty" x-cloak data-testid="dirty-indicator"
                  title="Unsaved changes"
                  class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                Unsaved
            </span>
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

                    {{-- Raw mode: textarea with restored syntax-cue overlay
                         (Phase 11D.1 → dropped in 11E → restored here). A
                         transparent-text textarea sits on top of a colorized
                         `<pre>` shadow. Both elements live under the same
                         recipeEditor Alpine scope; no nested component. --}}
                    <div x-show="mode === 'raw'" x-cloak class="relative">
                        <label for="markdown" class="sr-only">Recipe markdown</label>
                        <pre x-ref="rawShadow"
                             aria-hidden="true"
                             class="pointer-events-none absolute inset-0 m-0 rounded border border-transparent px-4 py-3 text-sm leading-relaxed text-stone-700 dark:text-stone-300 overflow-hidden whitespace-pre-wrap break-words"
                             style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace; tab-size: 2;"></pre>
                        <textarea id="markdown" name="markdown" rows="40"
                                  x-ref="rawTextarea"
                                  spellcheck="false" autocomplete="off"
                                  x-on:input="onRawInput()"
                                  x-on:scroll="syncRawShadowScroll()"
                                  x-on:keydown="onKeydown($event)"
                                  data-markdown-editor="true"
                                  class="relative w-full rounded border border-stone-300 px-4 py-3 text-sm leading-relaxed shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700"
                                  style="font-family: ui-monospace, 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace; tab-size: 2; caret-color: rgb(217 119 6); color: transparent; -webkit-text-fill-color: transparent; background-color: transparent;">{{ old('markdown', $markdown) }}</textarea>
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

            {{-- Save button (always visible at bottom of form). --}}
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

            {{-- Phase 11F: mobile-only sticky save bar pinned to the viewport
                 bottom so the save action is reachable from anywhere in the
                 form. Hidden on lg+ where the inline save button is in view. --}}
            <div data-testid="mobile-save-bar"
                 class="lg:hidden fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 backdrop-blur px-4 py-3 shadow-[0_-2px_8px_rgba(0,0,0,0.06)] dark:border-stone-800 dark:bg-stone-900/95">
                <div class="mx-auto flex max-w-6xl items-center justify-between gap-3">
                    <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
                       class="text-sm text-stone-600 dark:text-stone-400">Cancel</a>
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                        <span x-show="dirty" x-cloak class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                        Save
                    </button>
                </div>
            </div>
        </form>

        <aside class="rounded-lg border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">
            <span class="font-semibold">Tips:</span>
            Slug and category are read-only here. Markdown reference:
            <a href="https://github.com/kbennett2000/recipe-machine/blob/main/docs/recipe-format.md" target="_blank" rel="noopener" class="text-amber-700 underline hover:text-amber-800 dark:text-amber-400">recipe-format.md</a>.
            Save triggers a single-recipe reindex.
        </aside>

        {{-- Phase 11G: delete flow. The link sits below the save bar in
             the page footer so it's not adjacent to the main action — a
             distracted user can't fat-finger it. The actual destructive
             POST sits behind a confirm dialog. --}}
        <div x-data="{ showConfirmDialog: false }" class="mt-8 border-t border-stone-200 pt-4 dark:border-stone-800">
            <button type="button" @click="showConfirmDialog = true"
                    data-testid="delete-recipe-link"
                    title="Removes file from disk"
                    class="text-xs text-rose-600 hover:text-rose-800 underline decoration-rose-600/30 underline-offset-2 hover:decoration-rose-800 dark:text-rose-400 dark:hover:text-rose-300">
                Delete recipe
            </button>

            <div x-show="showConfirmDialog" x-cloak
                 @keydown.escape.window="showConfirmDialog = false"
                 class="fixed inset-0 z-40 flex items-center justify-center px-4">
                <div class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm"
                     @click="showConfirmDialog = false"></div>
                <div data-testid="delete-confirm-dialog"
                     class="relative z-50 w-full max-w-md rounded-lg border border-stone-200 bg-white p-6 shadow-xl dark:border-stone-700 dark:bg-stone-900">
                    <h2 class="font-display text-lg font-semibold text-stone-900 dark:text-stone-100">Delete '{{ $recipe->title }}'?</h2>
                    <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">
                        This removes the file from disk and cannot be undone except via git.
                    </p>
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" @click="showConfirmDialog = false"
                                class="rounded border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:bg-stone-800">
                            Cancel
                        </button>
                        <form method="POST" action="{{ route('recipes.destroy', ['recipe' => $recipe->slug]) }}">
                            @csrf
                            <button type="submit"
                                    data-testid="delete-confirm-button"
                                    class="rounded border border-rose-500 bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-700 dark:border-rose-600 dark:bg-rose-700 dark:hover:bg-rose-600">
                                Delete permanently
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
