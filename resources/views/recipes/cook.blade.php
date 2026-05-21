@extends('layouts.cook')

@section('title', 'Cook — '.$recipe->title)

@section('content')
    <div x-data="cookingMode('{{ $recipe->slug }}', {{ $totalSteps }}, {{ $startStep }}, {{ $recipe->yields !== null && $recipe->yields > 0 ? (int) $recipe->yields : 'null' }})" x-init="init()">

        {{-- TOP BAR --}}
        <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 backdrop-blur dark:border-stone-800 dark:bg-stone-900/95">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="font-display text-base font-semibold tracking-tight truncate"
                       title="{{ $recipe->title }}">{{ $recipe->title }}</p>
                    @if ($totalSteps > 0)
                        <p class="text-xs text-stone-500 dark:text-stone-500">
                            Step <span x-text="currentStep"></span> of {{ $totalSteps }}
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <span x-show="wakeLockActive" x-cloak
                          class="hidden sm:inline text-xs text-stone-500 dark:text-stone-500"
                          title="The screen will stay on while cooking mode is active.">
                        🌑 Screen on
                    </span>
                    <button type="button" @click="toggleIngredients"
                            class="lg:hidden rounded border border-stone-300 px-2.5 py-1 text-xs text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                        <span x-text="showIngredients ? 'Hide ingredients' : 'Show ingredients'"></span>
                    </button>
                    <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
                       @click="cleanup"
                       class="text-sm text-stone-600 hover:text-amber-700 dark:text-stone-400 dark:hover:text-amber-400">
                        Exit
                    </a>
                </div>
            </div>
        </header>

        {{-- ACTIVE TIMERS STRIPE --}}
        <div x-show="timers.length > 0" x-cloak
             class="bg-amber-50 border-b border-amber-200 dark:bg-amber-950/30 dark:border-amber-900/50">
            <div class="mx-auto max-w-4xl px-4 py-2 flex items-start gap-4 flex-wrap">
                <span class="text-xs font-medium uppercase tracking-wider text-amber-800 dark:text-amber-300 shrink-0 pt-1">
                    Active timers
                </span>
                <ul class="flex flex-wrap gap-2 flex-1">
                    <template x-for="t in timers" :key="t.id">
                        <li class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-sm shadow-sm dark:bg-stone-900"
                            :class="statusFor(t).complete ? 'ring-2 ring-rose-400 dark:ring-rose-600 animate-pulse'
                                  : statusFor(t).low_bound_reached ? 'ring-2 ring-amber-400 dark:ring-amber-600'
                                  : ''">
                            <span class="font-medium" x-text="t.label"></span>
                            <span class="tabular-nums text-stone-700 dark:text-stone-300"
                                  x-text="formatTime(statusFor(t).remaining)"></span>
                            <template x-if="statusFor(t).low_bound_reached && !statusFor(t).complete">
                                <span class="text-xs text-amber-700 dark:text-amber-400">check now</span>
                            </template>
                            <button type="button" @click="stopTimer(t.id)"
                                    class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400"
                                    aria-label="Stop timer">×</button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        {{-- MAIN CONTENT --}}
        <main class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 lg:py-12">
            @if ($totalSteps === 0)
                <p class="text-xl italic text-stone-600 dark:text-stone-400">
                    (No instructions recorded — see notes on the
                    <a href="{{ route('recipes.show', ['recipe' => $recipe->slug]) }}"
                       class="underline hover:text-amber-700 dark:hover:text-amber-400">recipe page</a>.)
                </p>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-8">

                    {{-- STEP CONTENT --}}
                    <article>
                        @foreach ($renderedSteps as $step)
                            <section x-show="currentStep === {{ $step['position'] }}" x-cloak
                                     class="text-2xl lg:text-3xl leading-relaxed font-display text-stone-900 dark:text-stone-100">
                                {!! $step['html'] !!}
                            </section>
                        @endforeach
                    </article>

                    {{-- INGREDIENTS PANEL (desktop) --}}
                    <aside class="hidden lg:block lg:sticky lg:top-24 lg:self-start">
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-2">
                            Ingredients
                        </h2>
                        @include('recipes._cook_ingredients_list')
                    </aside>
                </div>

                {{-- INGREDIENTS PANEL (mobile overlay) --}}
                <div x-show="showIngredients" x-cloak
                     class="lg:hidden mt-6 rounded-lg border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-stone-600 dark:text-stone-400 mb-2">
                        Ingredients
                    </h2>
                    @include('recipes._cook_ingredients_list')
                </div>

                {{-- NAVIGATION --}}
                <div class="mt-12 flex items-center justify-between border-t border-stone-200 pt-6 dark:border-stone-800">
                    <button type="button" @click="prevStep"
                            :disabled="currentStep === 1"
                            class="rounded-lg border border-stone-300 px-4 py-2 text-base font-medium text-stone-700 hover:bg-stone-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800">
                        ← Previous
                    </button>
                    <p class="text-sm text-stone-500 dark:text-stone-500 hidden sm:block">
                        Step <span x-text="currentStep"></span> of {{ $totalSteps }}
                    </p>
                    <button type="button"
                            @click="currentStep === totalSteps ? finish() : nextStep()"
                            class="rounded-lg border border-amber-400 bg-amber-50 px-4 py-2 text-base font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                        <span x-text="currentStep === totalSteps ? 'Finish' : 'Next →'"></span>
                    </button>
                </div>
            @endif
        </main>

        {{-- FINISH TOAST --}}
        <div x-show="showFinishToast" x-cloak
             class="fixed bottom-6 left-1/2 -translate-x-1/2 rounded-lg bg-stone-900 px-5 py-3 text-white shadow-xl dark:bg-stone-100 dark:text-stone-900">
            Recipe complete 🎉
        </div>
    </div>
@endsection
