<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    {{-- Fraunces + Inter are self-hosted under public/fonts/.
         @font-face declarations live in resources/css/app.css. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-stone-50 text-stone-900 antialiased dark:bg-stone-950 dark:text-stone-100 font-sans">
    @php
        /** @var array $navCategories */
        $navCategories = $navCategories ?? \App\Recipes\Nav\Categories::orderedWithCounts(
            \App\Models\Recipe::query()
                ->selectRaw('category, count(*) as cnt')
                ->groupBy('category')
                ->pluck('cnt', 'category')
                ->all()
        );
    @endphp

    <div class="min-h-full flex flex-col"
         x-data="{}"
         @keydown.window.slash="
            if (['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) return;
            $event.preventDefault();
            document.getElementById('global-search-input')?.focus();
         ">
        <header class="border-b border-stone-200 bg-white/95 backdrop-blur dark:border-stone-800 dark:bg-stone-900/95 sticky top-0 z-10">
            <div class="mx-auto flex max-w-6xl flex-col items-start gap-3 px-6 py-4 lg:flex-row lg:items-center lg:gap-5">
                <a href="{{ url('/') }}" class="font-display text-2xl font-semibold tracking-tight whitespace-nowrap">
                    Recipe Machine
                </a>

                {{-- Global search box --}}
                <form action="{{ route('search') }}" method="get" class="relative w-full lg:max-w-sm">
                    <label for="global-search-input" class="sr-only">Search recipes</label>
                    <input
                        id="global-search-input"
                        type="search"
                        name="q"
                        value="{{ request()->routeIs('search') ? request()->query('q', '') : '' }}"
                        placeholder="Search recipes…"
                        autocomplete="off"
                        class="w-full rounded-md border border-stone-300 bg-white px-3 py-1.5 pr-8 text-sm text-stone-900 placeholder-stone-400 shadow-sm focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:placeholder-stone-500 dark:focus:border-amber-600"
                    >
                    <kbd class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 hidden rounded border border-stone-300 bg-stone-50 px-1.5 text-xs text-stone-500 dark:border-stone-600 dark:bg-stone-700 dark:text-stone-400 sm:inline">/</kbd>
                </form>

                <nav class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm lg:ml-auto">
                    @foreach ($navCategories as $cat)
                        @if ($cat['count'] > 0)
                            <a href="{{ route('categories.show', ['category' => $cat['slug']]) }}"
                               class="text-stone-700 hover:text-amber-700 dark:text-stone-300 dark:hover:text-amber-400 transition">
                                {{ $cat['label'] }}
                                <span class="text-xs text-stone-500 dark:text-stone-500">{{ $cat['count'] }}</span>
                            </a>
                        @else
                            <span class="text-stone-400 dark:text-stone-600 cursor-default">
                                {{ $cat['label'] }}
                                <span class="text-xs">soon</span>
                            </span>
                        @endif
                    @endforeach
                    <a href="{{ route('recipes.index') }}"
                       class="border-l border-stone-200 dark:border-stone-700 pl-4 text-stone-600 hover:text-amber-700 dark:text-stone-400 dark:hover:text-amber-400 transition">
                        Index
                    </a>
                    <a href="{{ route('shopping-list') }}"
                       class="text-stone-700 hover:text-amber-700 dark:text-stone-300 dark:hover:text-amber-400 transition">
                        Shopping List
                        <span x-show="$store.shoppingList.count > 0" x-cloak
                              x-text="$store.shoppingList.count"
                              class="ml-0.5 inline-block min-w-[1.25rem] rounded-full bg-amber-100 px-1.5 text-xs font-medium text-amber-800 text-center dark:bg-amber-900/40 dark:text-amber-300"></span>
                    </a>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
            @if (session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                     class="mb-6 flex items-start justify-between gap-3 rounded border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">
                    <p class="text-sm">{{ session('success') }}</p>
                    <button type="button" @click="show = false"
                            class="text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-200"
                            aria-label="Dismiss">×</button>
                </div>
            @endif
            @yield('content')
        </main>

        <footer class="border-t border-stone-200 dark:border-stone-800 mt-12">
            <div class="mx-auto max-w-6xl px-6 py-6 text-xs text-stone-500 dark:text-stone-500">
                Recipe Machine · Phase 3 — browsing online
            </div>
        </footer>
    </div>
</body>
</html>
