<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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

    <div class="min-h-full flex flex-col">
        <header class="border-b border-stone-200 bg-white/95 backdrop-blur dark:border-stone-800 dark:bg-stone-900/95 sticky top-0 z-10">
            <div class="mx-auto flex max-w-6xl flex-col items-start gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                <a href="{{ url('/') }}" class="font-display text-2xl font-semibold tracking-tight">
                    Recipe Machine
                </a>
                <nav class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
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
                    <span class="text-stone-400 dark:text-stone-600 cursor-default border-l border-stone-200 dark:border-stone-700 pl-5">
                        Shopping List <span class="text-xs">soon</span>
                    </span>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
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
