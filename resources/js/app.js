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

// ============================================================================
// Cooking-mode component (Phase 7).
// State (timers + step) lives in sessionStorage keyed by recipe slug so a
// refresh, accidental navigate-away, or browser-tab swap leaves the user
// exactly where they were. The cook:{slug}:step + cook:{slug}:timers keys
// are cleared on Finish; Exit leaves them so the user can resume.
//
// Timers store {id, label, started_at, duration, low}; remaining seconds,
// low-bound-reached, and complete are derived from the wall clock each
// tick so a backgrounded tab doesn't drift.
// ============================================================================

window.cookingMode = function (slug, totalSteps, startStep) {
    return {
        slug,
        totalSteps,
        currentStep: startStep || 1,
        timers: [],
        _tick: 0, // bumped each second to force Alpine reactivity on derived status
        showFinishToast: false,
        showIngredients: false,
        wakeLockActive: false,
        _wakeLock: null,
        _tickInterval: null,
        _beepedTimerIds: {},

        init() {
            const storedStep = sessionStorage.getItem(`cook:${this.slug}:step`);
            if (storedStep !== null) {
                const n = parseInt(storedStep, 10);
                if (!Number.isNaN(n) && n >= 1 && n <= this.totalSteps) {
                    this.currentStep = n;
                }
            }

            const storedTimers = sessionStorage.getItem(`cook:${this.slug}:timers`);
            if (storedTimers !== null) {
                try {
                    const parsed = JSON.parse(storedTimers);
                    if (Array.isArray(parsed)) {
                        this.timers = parsed.filter((t) =>
                            t && typeof t.id === 'string'
                            && typeof t.label === 'string'
                            && typeof t.started_at === 'number'
                            && typeof t.duration === 'number'
                        );
                    }
                } catch (_) { /* ignore corrupt state */ }
            }

            this._updateUrl();

            this._tickInterval = setInterval(() => {
                this._tick += 1;
                // Beep once when a timer first hits zero.
                for (const t of this.timers) {
                    const s = this.statusFor(t);
                    if (s.complete && !this._beepedTimerIds[t.id]) {
                        this._beepedTimerIds[t.id] = true;
                        this._beep();
                    }
                }
            }, 1000);

            this._keyHandler = (ev) => {
                if (ev.target && (ev.target.tagName === 'INPUT' || ev.target.tagName === 'TEXTAREA')) {
                    return;
                }
                if (ev.key === 'ArrowRight' || ev.key === ' ') {
                    if (this.currentStep < this.totalSteps) {
                        ev.preventDefault();
                        this.nextStep();
                    } else if (ev.key === ' ' && this.currentStep === this.totalSteps) {
                        ev.preventDefault();
                        this.finish();
                    }
                } else if (ev.key === 'ArrowLeft') {
                    if (this.currentStep > 1) {
                        ev.preventDefault();
                        this.prevStep();
                    }
                }
            };
            window.addEventListener('keydown', this._keyHandler);

            this._requestWakeLock();
            this._visibilityHandler = () => {
                if (document.visibilityState === 'visible' && !this._wakeLock) {
                    this._requestWakeLock();
                }
            };
            document.addEventListener('visibilitychange', this._visibilityHandler);

            this._popHandler = () => {
                const params = new URLSearchParams(window.location.search);
                const requested = parseInt(params.get('step') || '1', 10);
                if (!Number.isNaN(requested) && requested >= 1 && requested <= this.totalSteps) {
                    this.currentStep = requested;
                    sessionStorage.setItem(`cook:${this.slug}:step`, String(requested));
                }
            };
            window.addEventListener('popstate', this._popHandler);
        },

        // ---- Step navigation ----

        nextStep() {
            if (this.currentStep < this.totalSteps) {
                this.currentStep += 1;
                sessionStorage.setItem(`cook:${this.slug}:step`, String(this.currentStep));
                this._updateUrl();
            }
        },

        prevStep() {
            if (this.currentStep > 1) {
                this.currentStep -= 1;
                sessionStorage.setItem(`cook:${this.slug}:step`, String(this.currentStep));
                this._updateUrl();
            }
        },

        finish() {
            this.showFinishToast = true;
            this._clearCookState();
            setTimeout(() => {
                this.showFinishToast = false;
                window.location.href = `/recipes/${this.slug}`;
            }, 1500);
        },

        toggleIngredients() {
            this.showIngredients = !this.showIngredients;
        },

        // ---- Timers ----

        startTimer(label, durationSeconds, lowSeconds) {
            if (this.timers.some((t) => t.label === label)) return;
            this.timers.push({
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                label,
                started_at: Date.now(),
                duration: durationSeconds,
                low: lowSeconds === null || lowSeconds === undefined ? null : Number(lowSeconds),
            });
            this._saveTimers();
        },

        stopTimer(id) {
            this.timers = this.timers.filter((t) => t.id !== id);
            delete this._beepedTimerIds[id];
            this._saveTimers();
        },

        statusFor(t) {
            void this._tick; // touch the reactive tick so this rebuilds each second
            const elapsed = Math.floor((Date.now() - t.started_at) / 1000);
            const remaining = Math.max(0, t.duration - elapsed);
            return {
                remaining,
                low_bound_reached: t.low !== null && elapsed >= t.low && remaining > 0,
                complete: remaining === 0,
            };
        },

        formatTime(seconds) {
            if (seconds < 0) seconds = 0;
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
        },

        cleanup() {
            if (this._tickInterval) clearInterval(this._tickInterval);
            if (this._keyHandler) window.removeEventListener('keydown', this._keyHandler);
            if (this._popHandler) window.removeEventListener('popstate', this._popHandler);
            if (this._visibilityHandler) document.removeEventListener('visibilitychange', this._visibilityHandler);
            if (this._wakeLock) {
                try { this._wakeLock.release(); } catch (_) { /* ignore */ }
                this._wakeLock = null;
            }
            // Exit leaves cook:{slug}:* keys intact so the user can resume.
        },

        // ---- Internals ----

        _saveTimers() {
            sessionStorage.setItem(`cook:${this.slug}:timers`, JSON.stringify(this.timers));
        },

        _clearCookState() {
            sessionStorage.removeItem(`cook:${this.slug}:step`);
            sessionStorage.removeItem(`cook:${this.slug}:timers`);
        },

        _updateUrl() {
            const url = new URL(window.location.href);
            url.searchParams.set('step', String(this.currentStep));
            window.history.replaceState({}, '', url.toString());
        },

        async _requestWakeLock() {
            if (!('wakeLock' in navigator)) return;
            try {
                this._wakeLock = await navigator.wakeLock.request('screen');
                this.wakeLockActive = true;
                this._wakeLock.addEventListener('release', () => {
                    this.wakeLockActive = false;
                    this._wakeLock = null;
                });
            } catch (_) {
                this.wakeLockActive = false;
            }
        },

        _beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g);
                g.connect(ctx.destination);
                o.frequency.value = 880;
                o.type = 'sine';
                g.gain.setValueAtTime(0.001, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + 0.05);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                o.start();
                o.stop(ctx.currentTime + 0.6);
            } catch (_) { /* no audio context, no beep */ }
        },
    };
};

Alpine.start();
