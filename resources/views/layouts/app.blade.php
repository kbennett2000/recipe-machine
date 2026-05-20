<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-stone-50 text-stone-900 antialiased dark:bg-stone-950 dark:text-stone-100">
    <div class="min-h-full flex flex-col">
        <header class="border-b border-stone-200 bg-white/80 backdrop-blur dark:border-stone-800 dark:bg-stone-900/80">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <a href="{{ url('/') }}" class="text-lg font-semibold tracking-tight">
                    Recipe Machine
                </a>
                <nav class="flex items-center gap-6 text-sm text-stone-600 dark:text-stone-400">
                    {{-- Nav placeholder — links added in later phases --}}
                    <span class="opacity-60">Recipes</span>
                    <span class="opacity-60">Shopping List</span>
                    <span class="opacity-60">About</span>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-6 py-12">
            @yield('content')
        </main>

        <footer class="border-t border-stone-200 dark:border-stone-800">
            <div class="mx-auto max-w-5xl px-6 py-6 text-xs text-stone-500 dark:text-stone-500">
                Recipe Machine &middot; Phase 0 skeleton
            </div>
        </footer>
    </div>
</body>
</html>
