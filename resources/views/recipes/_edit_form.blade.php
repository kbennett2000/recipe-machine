{{--
    Phase 11E — form-mode sections.

    The full editor surface, broken into logical groups. Every field
    is bound to `state.*` via x-model, so changes flow straight into
    the Alpine reactive object that backs the preview and the save
    serializer.

    All field labels match the spec / ParsedRecipe shape:
      state.frontmatter.{title,category,slug,...}
      state.ingredients[]
      state.method[]
      state.notes
      state.libation_prose
      state.frontmatter.extra{}
--}}
@php
    /** @var list<string> $categories */
    $unitOptions = [
        ['', '(none)'],
        ['tsp', 'tsp'], ['tbsp', 'Tbsp'], ['cup', 'cup'], ['floz', 'fl oz'],
        ['pint', 'pint'], ['quart', 'quart'], ['gallon', 'gallon'],
        ['ml', 'ml'], ['l', 'L'],
        ['g', 'g'], ['kg', 'kg'], ['oz', 'oz'], ['lb', 'lb'],
        ['whole', 'whole (count)'],
        ['pinch', 'pinch'], ['dash', 'dash'], ['splash', 'splash'],
        ['drizzle', 'drizzle'], ['handful', 'handful'], ['sprinkle', 'sprinkle'],
        ['to-taste', 'to taste'], ['as-needed', 'as needed'],
    ];
@endphp

{{-- =========== Title + category =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Title &amp; category</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Title</span>
            <input type="text" x-model="state.frontmatter.title"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Category</span>
            <select x-model="state.frontmatter.category"
                    class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-stone-500 dark:text-stone-600">Category moves aren't supported in the editor.</p>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400 flex items-center gap-2">
                Slug
                <span class="inline-flex items-center rounded-full bg-stone-200 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-stone-600 dark:bg-stone-800 dark:text-stone-400"
                      title="Slug is derived from the filename. Rename via terminal if needed.">
                    🔒 immutable
                </span>
            </span>
            <input type="text" :value="state.frontmatter.slug" readonly disabled
                   class="mt-1 block w-full rounded border border-stone-300 bg-stone-100 px-2 py-1.5 text-sm text-stone-500 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-500">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Servings</span>
            <input type="text" x-model="state.frontmatter.servings"
                   placeholder='e.g. "1 loaf (~12 slices)"'
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Yields</span>
            <input type="number" x-model.number="state.frontmatter.yields" min="0"
                   inputmode="numeric"
                   placeholder="e.g. 12"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
    </div>
</section>

{{-- =========== Times + meta =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Times &amp; metadata</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Prep time</span>
            <input type="text" x-model="state.frontmatter.prep_time" placeholder="e.g. 20m"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Cook time</span>
            <input type="text" x-model="state.frontmatter.cook_time" placeholder="e.g. 1h30m"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Total time</span>
            <input type="text" x-model="state.frontmatter.total_time" placeholder="e.g. 2h"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Oven temp</span>
            <input type="text" x-model="state.frontmatter.oven_temp" placeholder="e.g. 350F"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Difficulty</span>
            <select x-model="state.frontmatter.difficulty"
                    class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
                <option :value="null">—</option>
                <option value="easy">easy</option>
                <option value="medium">medium</option>
                <option value="hard">hard</option>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Source</span>
            <input type="text" x-model="state.frontmatter.source"
                   class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        </label>
    </div>
    <label class="block">
        <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Tags (comma-separated)</span>
        <input type="text" :value="(state.frontmatter.tags || []).join(', ')"
               @input="state.frontmatter.tags = $event.target.value.split(',').map(s => s.trim()).filter(Boolean)"
               class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
        <div class="mt-1 flex flex-wrap gap-1" x-show="(state.frontmatter.tags || []).length > 0">
            <template x-for="t in (state.frontmatter.tags || [])" :key="t">
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-400" x-text="t"></span>
            </template>
        </div>
    </label>
    <label class="block">
        <span class="text-xs font-medium text-stone-600 dark:text-stone-400">Libation (short pairing)</span>
        <input type="text" x-model="state.frontmatter.libation"
               placeholder='e.g. "Semi-sweet mead — honey loves honey"'
               class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
    </label>
    <label class="block">
        <span class="text-xs font-medium text-stone-600 dark:text-stone-400">References (comma-separated slugs)</span>
        <input type="text" :value="(state.frontmatter.references || []).join(', ')"
               @input="state.frontmatter.references = $event.target.value.split(',').map(s => s.trim()).filter(Boolean)"
               placeholder="e.g. pie-crust, sourdough-starter"
               class="mt-1 block w-full rounded border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
    </label>
</section>

{{-- =========== Ingredients (sortable) =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Ingredients</h2>
    <ul x-ref="ingredientsList" class="space-y-2">
        <template x-for="(ing, idx) in state.ingredients.filter(i => i.parsed)" :key="ing._key">
            <li class="flex items-start gap-2 rounded border border-stone-200 bg-white p-2 dark:border-stone-800 dark:bg-stone-900"
                :data-key="ing._key">
                <span class="cursor-grab select-none touch-none text-stone-400 hover:text-stone-600 dark:text-stone-600 dark:hover:text-stone-400 pt-1.5 drag-handle"
                      style="min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center;"
                      aria-label="Drag to reorder">⋮⋮</span>
                {{-- Mobile (< sm): stacked 2-row layout per the Phase 11F spec.
                     Desktop (sm+): the existing inline grid. --}}
                <div class="flex-1 space-y-1.5 min-w-0">
                    {{-- Row 1: amount / unit / group / optional. Always inline. --}}
                    <div class="grid grid-cols-12 gap-1.5">
                        <div class="col-span-4 sm:col-span-2">
                            <input type="text" x-model="ing.amount" inputmode="decimal" placeholder="amt"
                                   autocapitalize="none"
                                   class="block w-full rounded border border-stone-300 bg-white px-1.5 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                        </div>
                        <div class="col-span-4 sm:col-span-2">
                            <select x-model="ing.unit"
                                    class="block w-full rounded border border-stone-300 bg-white px-1 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                                @foreach ($unitOptions as [$value, $label])
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="hidden sm:block sm:col-span-4">
                            <input type="text" x-model="ing.ingredient" placeholder="ingredient"
                                   class="block w-full rounded border border-stone-300 bg-white px-1.5 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                        </div>
                        <div class="hidden sm:block sm:col-span-2">
                            <div class="flex items-center gap-1">
                                <input type="text" x-model="ing.modifier" placeholder="modifier"
                                       class="block w-full rounded border border-stone-300 bg-white px-1.5 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                                <span class="cursor-help text-stone-400 dark:text-stone-600" title="Preparation state (post-comma in original line): diced, softened, minced.">?</span>
                            </div>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <select x-model="ing.group" aria-label="Sub-group"
                                    class="block w-full rounded border border-stone-300 bg-white px-1 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                                <option :value="null">—</option>
                                <template x-for="g in groupNames()" :key="g">
                                    <option :value="g" x-text="g"></option>
                                </template>
                            </select>
                        </div>
                        <div class="col-span-2 sm:col-span-1 flex items-center justify-end">
                            <label class="flex items-center gap-1 text-xs text-stone-600 dark:text-stone-400 cursor-pointer">
                                <input type="checkbox" x-model="ing.optional" class="rounded">
                                <span>opt</span>
                            </label>
                        </div>
                    </div>
                    {{-- Row 2: ingredient (mobile only — already inline on desktop) --}}
                    <div class="sm:hidden">
                        <input type="text" x-model="ing.ingredient" placeholder="ingredient"
                               class="block w-full rounded border border-stone-300 bg-white px-1.5 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                    </div>
                    {{-- Row 3: modifier (mobile only) --}}
                    <div class="sm:hidden flex items-center gap-1">
                        <input type="text" x-model="ing.modifier" placeholder="modifier"
                               class="block w-full rounded border border-stone-300 bg-white px-1.5 py-1 text-xs dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                        <span class="cursor-help text-stone-400 dark:text-stone-600" title="Preparation state (post-comma in original line): diced, softened, minced.">?</span>
                    </div>
                    {{-- Row 4: note (both desktop + mobile, full width) --}}
                    <div class="flex items-center gap-1">
                        <input type="text" x-model="ing.note" placeholder="note (em-dash content)"
                               class="block w-full rounded border border-stone-200 bg-white/50 px-1.5 py-1 text-xs dark:border-stone-800 dark:bg-stone-900/50 dark:text-stone-300">
                        <span class="cursor-help text-stone-400 dark:text-stone-600" title="Free-form note (post-em-dash in source): adjust to taste, for dusting, see also …">?</span>
                    </div>
                </div>
                <button type="button" @click="removeIngredient(ing._key)"
                        aria-label="Remove ingredient"
                        class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400 pt-1.5">×</button>
            </li>
        </template>
    </ul>
    <div class="flex flex-wrap gap-2">
        <button type="button" @click="addIngredient()"
                class="rounded border border-stone-300 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:bg-stone-800">
            + Add ingredient
        </button>
        <button type="button" @click="addGroup()"
                class="rounded border border-stone-300 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:bg-stone-800">
            + Add group
        </button>
    </div>

    {{-- Groups manager: lets the user rename/delete groups --}}
    <div x-show="groupNames().length > 0" x-cloak class="mt-2 space-y-1">
        <p class="text-xs font-medium text-stone-500 dark:text-stone-600">Groups</p>
        <template x-for="(g, gi) in groupNames()" :key="g">
            <div class="flex items-center gap-2">
                <input type="text" :value="g" :data-group-name="g"
                       @change="renameGroup(g, $event.target.value)"
                       @keydown.enter.prevent="$event.target.blur()"
                       class="rounded border border-stone-300 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
                <button type="button" @click="deleteGroup(g)"
                        class="text-xs text-stone-500 hover:text-rose-600 dark:text-stone-500 dark:hover:text-rose-400">
                    remove (ingredients become ungrouped)
                </button>
            </div>
        </template>
    </div>
</section>

{{-- =========== Verify these (unparsed lines) =========== --}}
<section x-show="unparsedIngredients().length > 0" x-cloak class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-amber-300 pb-1 text-amber-800 dark:border-amber-700 dark:text-amber-300">
        Verify these
    </h2>
    <p class="text-xs text-stone-600 dark:text-stone-400">
        The parser couldn't structure these lines. Each is preserved as raw text.
        You can edit it, leave it alone, or convert it into a structured ingredient row.
    </p>
    <ul class="space-y-2">
        <template x-for="ing in unparsedIngredients()" :key="ing._key">
            <li class="flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
                <input type="text" x-model="ing.raw"
                       class="flex-1 rounded border border-amber-300 bg-white px-2 py-1 text-sm dark:border-amber-700 dark:bg-stone-900 dark:text-stone-100">
                <button type="button" @click="convertToStructured(ing._key)"
                        class="rounded border border-amber-400 bg-white px-2 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-600 dark:bg-stone-900 dark:text-amber-300 dark:hover:bg-amber-950/40">
                    Convert to structured
                </button>
                <button type="button" @click="removeIngredient(ing._key)" aria-label="Remove"
                        class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400">×</button>
            </li>
        </template>
    </ul>
</section>

{{-- =========== Method (sortable) =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Method</h2>
    <ol x-ref="methodList" class="space-y-2">
        <template x-for="(step, idx) in state.method" :key="step._key">
            <li class="flex items-start gap-2 rounded border border-stone-200 bg-white p-2 dark:border-stone-800 dark:bg-stone-900"
                :data-key="step._key">
                <span class="cursor-grab select-none touch-none text-stone-400 hover:text-stone-600 dark:text-stone-600 dark:hover:text-stone-400 pt-1.5 drag-handle"
                      style="min-width: 24px; min-height: 24px; display: inline-flex; align-items: center; justify-content: center;">⋮⋮</span>
                <span class="font-display text-amber-700 dark:text-amber-400 pt-1.5 w-6 text-right text-sm" x-text="(idx + 1) + '.'"></span>
                <textarea x-model="step.text" rows="2"
                          @input="autoResize($event.target)"
                          x-init="$nextTick(() => autoResize($el))"
                          class="flex-1 rounded border border-stone-300 bg-white px-2 py-1.5 text-sm leading-relaxed dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100"></textarea>
                <button type="button" @click="removeStep(step._key)" aria-label="Remove step"
                        class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400 pt-1.5">×</button>
            </li>
        </template>
    </ol>
    <button type="button" @click="addStep()"
            class="rounded border border-stone-300 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:bg-stone-800">
        + Add step
    </button>
</section>

{{-- =========== Notes =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Notes</h2>
    <textarea x-model="state.notes" rows="6"
              placeholder="Markdown supported. [[cross-references]] resolve to links when saved."
              class="w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"></textarea>
</section>

{{-- =========== Libation (body-form) =========== --}}
<section class="space-y-3">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 dark:border-stone-800">Libation (body-form, optional)</h2>
    <textarea x-model="state.libation_prose" rows="3"
              placeholder="Longer description. Use the short libation field above for one-liners."
              class="w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"></textarea>
</section>

{{-- =========== Other fields =========== --}}
<section class="space-y-3" x-data="{ open: false }">
    <h2 class="font-display text-lg font-semibold border-b border-stone-200 pb-1 flex items-center justify-between dark:border-stone-800">
        Other frontmatter fields
        <button type="button" @click="open = !open" class="text-sm text-stone-500 dark:text-stone-500">
            <span x-text="open ? '▾' : '▸'"></span>
        </button>
    </h2>
    <div x-show="open" x-cloak class="space-y-2">
        <p class="text-xs text-stone-500 dark:text-stone-600">Custom frontmatter keys that aren't part of the canonical schema. Renders as-is in YAML.</p>
        <template x-for="(value, key) in (state.frontmatter.extra || {})" :key="key">
            <div class="flex items-center gap-2">
                <input type="text" :value="key" :data-extra-key="key"
                       @change="renameExtra(key, $event.target.value)"
                       @keydown.enter.prevent="$event.target.blur()"
                       class="w-1/3 rounded border border-stone-300 bg-white px-2 py-1 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
                <input type="text" :value="value" @input="state.frontmatter.extra[key] = $event.target.value"
                       class="flex-1 rounded border border-stone-300 bg-white px-2 py-1 text-sm dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100">
                <button type="button" @click="removeExtra(key)" aria-label="Remove field"
                        class="text-stone-400 hover:text-rose-600 dark:text-stone-600 dark:hover:text-rose-400">×</button>
            </div>
        </template>
        <button type="button" @click="addExtra()"
                class="rounded border border-stone-300 bg-white px-3 py-1 text-xs font-medium text-stone-700 hover:bg-stone-100 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:hover:bg-stone-800">
            + Add field
        </button>
    </div>
</section>
