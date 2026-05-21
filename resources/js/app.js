import './bootstrap';

import Alpine from 'alpinejs';
import { formatIngredient } from './ingredient-format.js';

window.Alpine = Alpine;
window.formatIngredient = formatIngredient;

// ============================================================================
// Shopping-list state — Alpine store, used across the recipe page (Add button),
// the layout (nav badge), and the shopping-list page (the actual list).
// ============================================================================

Alpine.store('shoppingList', {
    items: [],         // [{slug, scale}]
    initialized: false,

    init() {
        if (this.initialized) return;
        this.initialized = true;

        // URL hash takes precedence — a shared meal-plan link populates the
        // sessionStorage on first load.
        const hash = window.location.hash || '';
        if (hash.startsWith('#m=')) {
            try {
                const decoded = JSON.parse(atob(hash.substring(3)));
                if (Array.isArray(decoded.recipes)) {
                    this.items = decoded.recipes
                        .filter((r) => r && typeof r.slug === 'string' && r.slug.length > 0)
                        .map((r) => ({ slug: r.slug, scale: Number(r.scale) || 1 }));
                    this.save();
                    return;
                }
            } catch (_) {
                /* fall through to sessionStorage */
            }
        }

        const stored = sessionStorage.getItem('shopping-list');
        if (stored) {
            try {
                const parsed = JSON.parse(stored);
                if (Array.isArray(parsed.recipes)) {
                    this.items = parsed.recipes;
                }
            } catch (_) {
                /* ignore corrupt state */
            }
        }
    },

    save() {
        sessionStorage.setItem('shopping-list', JSON.stringify({ recipes: this.items }));
    },

    add(slug, scale = 1) {
        if (!slug) return;
        if (this.items.find((i) => i.slug === slug)) return;
        this.items.push({ slug, scale: Number(scale) || 1 });
        this.save();
    },

    remove(slug) {
        this.items = this.items.filter((i) => i.slug !== slug);
        this.save();
    },

    clear() {
        this.items = [];
        this.save();
    },

    setScale(slug, scale) {
        const item = this.items.find((i) => i.slug === slug);
        if (item) {
            item.scale = Math.max(0.1, Number(scale) || 1);
            this.save();
        }
    },

    has(slug) {
        return this.items.some((i) => i.slug === slug);
    },

    scaleFor(slug) {
        const item = this.items.find((i) => i.slug === slug);
        return item ? item.scale : 1;
    },

    get count() {
        return this.items.length;
    },

    shareUrl() {
        const encoded = btoa(JSON.stringify({ recipes: this.items }));
        return window.location.origin + '/shopping-list#m=' + encoded;
    },
});

// Initialize the store once Alpine starts.
document.addEventListener('alpine:init', () => {
    Alpine.store('shoppingList').init();
});

// ============================================================================
// Per-recipe scaling stepper (unchanged from Phase 5).
// ============================================================================

window.recipeScale = function (slug, defaultServings) {
    return {
        slug,
        defaultServings,
        servings: defaultServings,

        init() {
            const stored = sessionStorage.getItem(`scale:${this.slug}`);
            if (stored !== null) {
                const n = parseInt(stored, 10);
                if (!Number.isNaN(n) && n > 0) {
                    this.servings = n;
                }
            }
            this.$watch('servings', (val) => {
                const safe = Math.max(1, Math.min(this.defaultServings * 2, parseInt(val, 10) || this.defaultServings));
                if (safe !== val) {
                    this.servings = safe;
                    return;
                }
                sessionStorage.setItem(`scale:${this.slug}`, safe);
                this.applyScale();
            });
            this.applyScale();
        },

        get scale() {
            return this.servings / this.defaultServings;
        },

        get scaledLabel() {
            const s = this.scale;
            if (s === 1) return '';
            return `× ${s.toFixed(2).replace(/\.?0+$/, '')} scaled`;
        },

        decrement() {
            if (this.servings > 1) this.servings = this.servings - 1;
        },

        increment() {
            if (this.servings < this.defaultServings * 2) this.servings = this.servings + 1;
        },

        applyScale() {
            const scale = this.scale;
            document.querySelectorAll('[data-amount]').forEach((el) => {
                const amount = parseFloat(el.dataset.amount);
                if (Number.isNaN(amount)) return;
                const hasHigh = el.dataset.amountHigh !== undefined && el.dataset.amountHigh !== '';
                const amountHigh = hasHigh ? parseFloat(el.dataset.amountHigh) : null;
                const fields = {
                    amount: amount * scale,
                    amount_high: amountHigh !== null ? amountHigh * scale : null,
                    unit: el.dataset.unit || null,
                    unit_class: el.dataset.unitClass || null,
                    ingredient: el.dataset.ingredient || null,
                    modifier: el.dataset.modifier || null,
                    optional: el.dataset.optional === '1',
                };
                el.textContent = formatIngredient(fields);
            });
        },
    };
};

// ============================================================================
// Shopping-list page component — reads the store, fetches the aggregated
// list from /shopping-list/calculate, renders into the page.
// ============================================================================

window.shoppingListPage = function () {
    return {
        loading: true,
        error: null,
        aggregated: null,
        shareUrlValue: '',
        copyState: 'idle', // 'idle' | 'copied'

        async init() {
            await this.refresh();
        },

        get items() {
            return Alpine.store('shoppingList').items;
        },

        get hasItems() {
            return this.items.length > 0;
        },

        async refresh() {
            const items = Alpine.store('shoppingList').items;
            if (items.length === 0) {
                this.loading = false;
                this.aggregated = null;
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                const resp = await fetch('/shopping-list/calculate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf || '',
                    },
                    body: JSON.stringify({ recipes: items }),
                });
                if (!resp.ok) {
                    throw new Error('Server returned ' + resp.status);
                }
                this.aggregated = await resp.json();
            } catch (e) {
                this.error = e.message || String(e);
                this.aggregated = null;
            }
            this.loading = false;
        },

        removeRecipe(slug) {
            Alpine.store('shoppingList').remove(slug);
            this.refresh();
        },

        updateScale(slug, newScale) {
            Alpine.store('shoppingList').setScale(slug, newScale);
            this.refresh();
        },

        clearAll() {
            Alpine.store('shoppingList').clear();
            this.refresh();
        },

        async copyShareUrl() {
            const url = Alpine.store('shoppingList').shareUrl();
            try {
                await navigator.clipboard.writeText(url);
                this.copyState = 'copied';
                setTimeout(() => { this.copyState = 'idle'; }, 1500);
            } catch (_) {
                // Fallback: select the URL in a temp input
                window.prompt('Copy this URL:', url);
            }
        },

        printList() {
            window.print();
        },
    };
};

Alpine.start();
