{{--
    Ingredients list for cooking mode. Mirrors the show-page sidebar's data
    attributes so the cookingMode Alpine component (init phase) can apply the
    same scaled-text rewrite that recipeScale does on the recipe page, reading
    `scale:{slug}` from sessionStorage so cooking mode picks up whatever the
    user last set on the recipe page.
--}}
@if ($recipe->ingredients->isEmpty())
    <p class="text-sm italic text-stone-500 dark:text-stone-500">
        (No ingredients recorded)
    </p>
@else
    @foreach ($groupedIngredients as $groupName => $items)
        @if ($groupName !== '')
            <h3 class="font-display text-sm font-semibold mt-3 mb-1 text-stone-700 dark:text-stone-300">
                {{ $groupName }}
            </h3>
        @endif
        <ul class="space-y-1 text-sm text-stone-800 dark:text-stone-200 mb-3">
            @foreach ($items as $ing)
                <li class="leading-snug">
                    @if ($ing->parsed)
                        <span
                            @if ($ing->amount !== null)data-amount="{{ $ing->amount }}"@endif
                            @if ($ing->amount_high !== null)data-amount-high="{{ $ing->amount_high }}"@endif
                            @if ($ing->unit !== null)data-unit="{{ $ing->unit }}"@endif
                            @if ($ing->unit_class !== null)data-unit-class="{{ $ing->unit_class }}"@endif
                            @if ($ing->ingredient !== null)data-ingredient="{{ $ing->ingredient }}"@endif
                            @if ($ing->modifier !== null)data-modifier="{{ $ing->modifier }}"@endif
                            @if ($ing->optional)data-optional="1"@endif
                        >{{ $ingredientFormatter->format($ing) }}</span>
                    @else
                        <span class="italic text-stone-700 dark:text-stone-300">{{ $ing->raw }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
@endif
