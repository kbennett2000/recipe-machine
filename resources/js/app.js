import './bootstrap';

import Alpine from 'alpinejs';
import { formatIngredient } from './ingredient-format.js';

window.Alpine = Alpine;
window.formatIngredient = formatIngredient;

/**
 * Alpine component for the per-recipe servings stepper.
 *
 *   x-data="recipeScale('honey-oat-bread', 12)"
 *
 * Reads scale from sessionStorage (keyed by slug), defaults to the recipe's
 * yields. On change, recomputes every ingredient span carrying a
 * `data-amount` attribute on the page using the JS twin of IngredientFormatter.
 */
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
                    return; // watcher will re-fire with the clamped value
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
            if (this.servings > 1) {
                this.servings = this.servings - 1;
            }
        },

        increment() {
            if (this.servings < this.defaultServings * 2) {
                this.servings = this.servings + 1;
            }
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

Alpine.start();
