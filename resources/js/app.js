import './bootstrap';

import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import { formatIngredient } from './ingredient-format.js';

window.Alpine = Alpine;
window.formatIngredient = formatIngredient;
window.Sortable = Sortable;

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

window.cookingMode = function (slug, totalSteps, startStep, defaultServings) {
    return {
        slug,
        totalSteps,
        defaultServings: defaultServings || null,
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
            this._applyScale();
            this._syncInlineButtonStates();

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
                this._syncInlineButtonStates();
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
            // Dedupe by label: a given label = a given real-world action (e.g.
            // "35–40 minutes" of baking). If the user navigates between steps
            // that share a label, both inline buttons reflect the same
            // underlying timer — see _syncInlineButtonStates.
            if (this.timers.some((t) => t.label === label)) return;
            this.timers.push({
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                label,
                started_at: Date.now(),
                duration: durationSeconds,
                low: lowSeconds === null || lowSeconds === undefined ? null : Number(lowSeconds),
            });
            this._saveTimers();
            this._syncInlineButtonStates();
        },

        stopTimer(id) {
            this.timers = this.timers.filter((t) => t.id !== id);
            delete this._beepedTimerIds[id];
            this._saveTimers();
            this._syncInlineButtonStates();
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

        // Match inline timer-btn elements to live timers by data-label. Same
        // label = same underlying timer (startTimer dedupes by label) so when
        // two steps share a phrase like "10 min" both buttons share state.
        _syncInlineButtonStates() {
            const btns = document.querySelectorAll('button.timer-btn');
            btns.forEach((btn) => {
                btn.classList.remove('timer-btn-running', 'timer-btn-low', 'timer-btn-done');
                const label = btn.dataset.label;
                if (!label) return;
                const timer = this.timers.find((t) => t.label === label);
                if (!timer) return;
                const s = this.statusFor(timer);
                if (s.complete) {
                    btn.classList.add('timer-btn-done');
                } else if (s.low_bound_reached) {
                    btn.classList.add('timer-btn-low');
                } else {
                    btn.classList.add('timer-btn-running');
                }
            });
        },

        // Apply scale from sessionStorage to the ingredients sidebar in the
        // same way recipeScale.applyScale does on the recipe detail page. The
        // cook page itself has no servings stepper — it READS the scale the
        // user set elsewhere. If the recipe has no defaultServings (recipes
        // without `yields`), there's no scaling to apply.
        _applyScale() {
            if (!this.defaultServings) return;
            const stored = sessionStorage.getItem(`scale:${this.slug}`);
            if (stored === null) return;
            const servings = parseInt(stored, 10);
            if (Number.isNaN(servings) || servings <= 0) return;
            const scale = servings / this.defaultServings;
            if (scale === 1) return;
            const fmt = window.formatIngredient;
            if (typeof fmt !== 'function') return;
            document.querySelectorAll('[data-amount]').forEach((el) => {
                const amount = parseFloat(el.dataset.amount);
                if (Number.isNaN(amount)) return;
                const hasHigh = el.dataset.amountHigh !== undefined && el.dataset.amountHigh !== '';
                const amountHigh = hasHigh ? parseFloat(el.dataset.amountHigh) : null;
                el.textContent = fmt({
                    amount: amount * scale,
                    amount_high: amountHigh !== null ? amountHigh * scale : null,
                    unit: el.dataset.unit || null,
                    unit_class: el.dataset.unitClass || null,
                    ingredient: el.dataset.ingredient || null,
                    modifier: el.dataset.modifier || null,
                    optional: el.dataset.optional === '1',
                });
            });
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

// ============================================================================
// Phase 11D.1 — markdown editor enhancements.
//
// A small Alpine component that powers the recipe-edit textarea with:
//   - Syntax cues via a transparent-text textarea + colorized `<pre>`
//     shadow overlay (the standard editor-overlay trick).
//   - Tab indents (2 spaces); Shift+Tab unindents; Tab on a selection
//     indents every line; Shift+Tab on a selection unindents every line.
//   - Esc moves focus out of the textarea so keyboard users can leave.
//
// The textarea is the source of truth — the shadow is decorative. If
// the regex misses a syntax case the worst outcome is "no color cue,
// plain text," which is graceful degradation.
// ============================================================================

window.markdownEditor = function () {
    return {
        init() {
            this.render();
        },

        render() {
            const ta = this.$refs.textarea;
            const shadow = this.$refs.shadow;
            if (! ta || ! shadow) return;
            shadow.innerHTML = highlightMarkdown(ta.value);
            this.syncScroll();
        },

        syncScroll() {
            const ta = this.$refs.textarea;
            const shadow = this.$refs.shadow;
            if (! ta || ! shadow) return;
            shadow.scrollTop = ta.scrollTop;
            shadow.scrollLeft = ta.scrollLeft;
        },

        onKeydown(ev) {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.target.blur();
                return;
            }
            if (ev.key !== 'Tab') return;
            ev.preventDefault();
            const ta = ev.target;
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const value = ta.value;
            const selectionSpansLines = value.substring(start, end).includes('\n')
                || start !== end;

            if (ev.shiftKey) {
                // Shift+Tab: unindent. If a selection spans multiple lines,
                // unindent each. Otherwise unindent the current line only.
                if (selectionSpansLines) {
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const before = value.substring(0, lineStart);
                    const block = value.substring(lineStart, end);
                    const after = value.substring(end);
                    const unindented = block
                        .split('\n')
                        .map((l) => l.replace(/^  /, ''))
                        .join('\n');
                    ta.value = before + unindented + after;
                    const delta = block.length - unindented.length;
                    ta.selectionStart = Math.max(lineStart, start - 2);
                    ta.selectionEnd = end - delta;
                } else {
                    // Single-line unindent: remove up to 2 leading spaces from
                    // the current line.
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const lineHead = value.substring(lineStart, start);
                    const trimmed = lineHead.replace(/^  /, '');
                    const removed = lineHead.length - trimmed.length;
                    ta.value = value.substring(0, lineStart) + trimmed + value.substring(start);
                    ta.selectionStart = ta.selectionEnd = start - removed;
                }
            } else {
                // Tab: indent.
                if (selectionSpansLines) {
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const before = value.substring(0, lineStart);
                    const block = value.substring(lineStart, end);
                    const after = value.substring(end);
                    const indented = block
                        .split('\n')
                        .map((l) => '  ' + l)
                        .join('\n');
                    ta.value = before + indented + after;
                    const delta = indented.length - block.length;
                    ta.selectionStart = start + 2;
                    ta.selectionEnd = end + delta;
                } else {
                    // Insert 2 spaces at cursor.
                    ta.value = value.substring(0, start) + '  ' + value.substring(end);
                    ta.selectionStart = ta.selectionEnd = start + 2;
                }
            }
            this.render();
        },
    };
};

function highlightMarkdown(src) {
    // Order:
    //   1. Escape HTML entities so the shadow can't render arbitrary
    //      markup the user typed.
    //   2. Process line-by-line so headers, list markers, YAML keys, and
    //      frontmatter fences match the right context.
    //   3. Apply inline rules ([[ref]], **bold**) within each line.
    const esc = (s) => s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const HASH = 'text-amber-400';
    const KEY = 'text-amber-400';
    const MARKER = 'text-stone-400';
    const FENCE = 'text-stone-500';
    const REF = 'text-amber-300';

    const lines = src.split('\n').map((raw) => {
        let line = esc(raw);

        // YAML frontmatter fence (a line of three dashes only).
        if (/^---\s*$/.test(raw)) {
            return `<span class="${FENCE}">${line}</span>`;
        }
        // YAML key (only colorize the key portion, leave the value plain).
        const keyMatch = raw.match(/^([a-z_][a-z0-9_]*):(.*)$/);
        if (keyMatch) {
            const key = esc(keyMatch[1]);
            const rest = esc(':' + keyMatch[2]);
            return `<span class="${KEY}">${key}</span>${applyInline(rest, REF)}`;
        }
        // Markdown ATX header: # ... ###### . Color the hashes only.
        const hMatch = raw.match(/^(#{1,6})(\s.*)?$/);
        if (hMatch) {
            const hashes = esc(hMatch[1]);
            const after = esc(hMatch[2] ?? '');
            return `<span class="${HASH}">${hashes}</span>${applyInline(after, REF)}`;
        }
        // Bullet list marker: "- " at start (or two-space indented).
        const bulletMatch = raw.match(/^(\s*)([-*+])(\s.*)?$/);
        if (bulletMatch) {
            const indent = esc(bulletMatch[1]);
            const marker = esc(bulletMatch[2]);
            const after = esc(bulletMatch[3] ?? '');
            return `${indent}<span class="${MARKER}">${marker}</span>${applyInline(after, REF)}`;
        }
        // Numbered list marker: "1. " at start.
        const numMatch = raw.match(/^(\s*)(\d+\.)(\s.*)?$/);
        if (numMatch) {
            const indent = esc(numMatch[1]);
            const marker = esc(numMatch[2]);
            const after = esc(numMatch[3] ?? '');
            return `${indent}<span class="${MARKER}">${marker}</span>${applyInline(after, REF)}`;
        }
        return applyInline(line, REF);
    });
    // Trailing newline shows as an empty line in the shadow — match the
    // textarea by leaving the join product as-is.
    return lines.join('\n') + '\n';
}

function applyInline(escapedLine, refClass) {
    // [[bracket-ref]] — already HTML-escaped, so `[[`/`]]` are literal.
    let out = escapedLine.replace(/\[\[([^\]]+)\]\]/g, (m, inner) => {
        return `<span class="${refClass}">[[${inner}]]</span>`;
    });
    // **bold** — wrap inner in a bold span. Don't recurse.
    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    return out;
}

// ============================================================================
// Phase 11E — form-mode recipe editor.
//
// The Alpine `recipeEditor` factory backs /recipes/{slug}/edit. Holds the
// recipe's state in a JS object that mirrors ParsedRecipe.toArray():
//   state.frontmatter.{title, category, slug, ...}
//   state.ingredients[]   — each row has _key (sortable identity),
//                            raw, parsed, amount, unit, ingredient, etc.
//   state.method[]        — each step has _key + text
//   state.notes / state.libation_prose / state.cross_references
//
// All state transformations (parse, serialize, preview) hit server-side
// endpoints — no JS twin of RecipeSerializer. The Phase 11E ADR is in
// the controller's docblock.
// ============================================================================

window.recipeEditor = function (config) {
    return {
        slug: config.slug,
        routes: config.routes,
        hasInitialState: config.hasInitialState,

        mode: config.initialMode,
        modeSwitching: false,

        // Reactive backing store. Initialized in init().
        state: { frontmatter: {}, ingredients: [], method: [], notes: '', libation_prose: '', cross_references: [] },

        previewLoading: false,
        _previewTimer: null,
        _keyCounter: 0,

        // Phase 11F: dirty-state tracking. Snapshot of the JSON state at
        // load (or after a successful save); `dirty` is recomputed on a
        // debounce when the user mutates anything.
        _initialStateJson: '',
        _dirtyTimer: null,
        dirty: false,

        init(initialState) {
            if (initialState) {
                this.state = this.hydrate(initialState);
            }
            // Snapshot the initial state for the dirty-tracking comparison.
            // Phase 11F: shows an amber dot on the mode toggle when the
            // user has unsaved changes vs. the last-loaded state.
            this._initialStateJson = JSON.stringify(this.dehydrate());

            // Always run a preview on first render so the pane isn't blank.
            this.$nextTick(() => {
                this.schedulePreview();
                this.renderRawShadow();
            });

            // Broad input/change listener: triggers preview refresh + the
            // debounced dirty re-evaluation. Individual mutator methods
            // (addStep, removeIngredient, etc.) also call schedulePreview()
            // directly so reorders and clicks register.
            this.$el.addEventListener('input', () => {
                this.schedulePreview();
                this.scheduleDirtyCheck();
            });
            this.$el.addEventListener('change', () => {
                this.schedulePreview();
                this.scheduleDirtyCheck();
            });

            // Wire up SortableJS for the ingredient and method lists
            // (in form mode only; the lists exist in the DOM either way
            // because Alpine x-show keeps both subtrees rendered).
            this.$nextTick(() => this.initSortables());
        },

        // Translate ParsedRecipe shape (snake_case from PHP) into the
        // reactive store. Adds a _key to each ingredient/method row so
        // x-for diffing + drag reorder identifies items by stable id.
        hydrate(parsed) {
            const fm = parsed.frontmatter || {};
            return {
                frontmatter: {
                    title: fm.title || '',
                    category: fm.category || '',
                    slug: fm.slug || '',
                    servings: fm.servings || null,
                    yields: fm.yields ?? null,
                    prep_time: fm.prep_time || null,
                    cook_time: fm.cook_time || null,
                    total_time: fm.total_time || null,
                    oven_temp: fm.oven_temp || null,
                    difficulty: fm.difficulty || null,
                    tags: Array.isArray(fm.tags) ? fm.tags.slice() : null,
                    libation: fm.libation || null,
                    source: fm.source || null,
                    references: Array.isArray(fm.references) ? fm.references.slice() : null,
                    extra: { ...(fm.extra || {}) },
                },
                ingredients: (parsed.ingredients || []).map((i) => ({
                    _key: ++this._keyCounter,
                    raw: i.raw || '',
                    parsed: !!i.parsed,
                    amount: i.amount ?? null,
                    amount_high: i.amount_high ?? null,
                    unit: i.unit || '',
                    ingredient: i.ingredient || '',
                    modifier: i.modifier || '',
                    note: i.note || '',
                    optional: !!i.optional,
                    group: i.group || null,
                })),
                method: (parsed.method || []).map((s) => ({
                    _key: ++this._keyCounter,
                    text: s || '',
                })),
                notes: parsed.notes || '',
                libation_prose: parsed.libation_prose || '',
                cross_references: parsed.cross_references || [],
            };
        },

        // Translate reactive store back to ParsedRecipe-shaped JSON for
        // POST to /edit/serialize, /edit/preview, or the main save path.
        dehydrate() {
            return {
                frontmatter: { ...this.state.frontmatter },
                ingredients: this.state.ingredients.map((i) => ({
                    raw: i.raw,
                    parsed: i.parsed,
                    amount: i.parsed && i.amount !== '' && i.amount !== null ? i.amount : null,
                    amount_high: i.amount_high,
                    unit: i.unit || null,
                    ingredient: i.ingredient || null,
                    modifier: i.modifier || null,
                    note: i.note || null,
                    optional: i.optional,
                    group: i.group || null,
                })),
                method: this.state.method.map((s) => s.text).filter((s) => s !== ''),
                notes: this.state.notes || null,
                libation_prose: this.state.libation_prose || null,
                cross_references: this.state.cross_references || [],
            };
        },

        // ---- Sortable wiring ----

        initSortables() {
            // Phase 11F: long-press to initiate drag on touch; instant on
            // mouse. `delay` is the time the user holds before drag starts.
            // `delayOnTouchOnly: true` keeps desktop click-drag immediate.
            // `touchStartThreshold` ignores tiny finger jitter during press.
            const opts = {
                handle: '.drag-handle',
                animation: 150,
                delay: 500,
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                chosenClass: 'sortable-chosen',
                ghostClass: 'sortable-ghost',
            };
            if (this.$refs.ingredientsList) {
                new Sortable(this.$refs.ingredientsList, {
                    ...opts,
                    onEnd: (ev) => this.reorderIngredients(ev),
                });
            }
            if (this.$refs.methodList) {
                new Sortable(this.$refs.methodList, {
                    ...opts,
                    onEnd: (ev) => this.reorderMethod(ev),
                });
            }
        },

        reorderIngredients(ev) {
            // The DOM rearranged itself; sync state.ingredients to match.
            // We only sort the parsed-subset list (unparsed rows live in
            // a separate section), so reorder within parsed in place.
            const list = this.$refs.ingredientsList;
            const orderedKeys = Array.from(list.querySelectorAll('[data-key]'))
                .map((el) => Number(el.dataset.key));
            const byKey = new Map(this.state.ingredients.map((i) => [i._key, i]));
            const parsedReordered = orderedKeys.map((k) => byKey.get(k)).filter(Boolean);
            const unparsed = this.state.ingredients.filter((i) => ! i.parsed);
            this.state.ingredients = [...parsedReordered, ...unparsed];
            this.schedulePreview();
        },

        reorderMethod(ev) {
            const list = this.$refs.methodList;
            const orderedKeys = Array.from(list.querySelectorAll('[data-key]'))
                .map((el) => Number(el.dataset.key));
            const byKey = new Map(this.state.method.map((s) => [s._key, s]));
            this.state.method = orderedKeys.map((k) => byKey.get(k)).filter(Boolean);
            this.schedulePreview();
        },

        // ---- Ingredient operations ----

        addIngredient() {
            this.state.ingredients.push({
                _key: ++this._keyCounter,
                raw: '',
                parsed: true,
                amount: null,
                amount_high: null,
                unit: '',
                ingredient: '',
                modifier: '',
                note: '',
                optional: false,
                group: null,
            });
            this.schedulePreview();
        },

        removeIngredient(key) {
            this.state.ingredients = this.state.ingredients.filter((i) => i._key !== key);
            this.schedulePreview();
        },

        convertToStructured(key) {
            const row = this.state.ingredients.find((i) => i._key === key);
            if (! row) return;
            row.parsed = true;
            // Best-effort guess from raw: leave amount/unit empty, fill ingredient.
            row.ingredient = row.raw;
            row.raw = '';
            this.schedulePreview();
        },

        unparsedIngredients() {
            return this.state.ingredients.filter((i) => ! i.parsed);
        },

        // ---- Sub-group operations ----

        groupNames() {
            const seen = new Set();
            for (const i of this.state.ingredients) {
                if (i.group) seen.add(i.group);
            }
            return Array.from(seen);
        },

        addGroup() {
            // Phase 11F: dropped window.prompt() in favor of inline editing.
            // Create a new ingredient tagged with a unique placeholder
            // group name; the Groups Manager row renders its editable name
            // input below, which the next $nextTick auto-focuses.
            const placeholder = this.uniqueGroupName('New group');
            this.state.ingredients.push({
                _key: ++this._keyCounter,
                raw: '',
                parsed: true,
                amount: null,
                amount_high: null,
                unit: '',
                ingredient: '',
                modifier: '',
                note: '',
                optional: false,
                group: placeholder,
            });
            this.schedulePreview();
            this.$nextTick(() => {
                // Auto-focus the group's rename input so the user can type
                // a real name without an extra click.
                const input = this.$el.querySelector('[data-group-name="'+CSS.escape(placeholder)+'"]');
                if (input) input.focus();
            });
        },

        uniqueGroupName(base) {
            const existing = new Set(this.groupNames());
            if (! existing.has(base)) return base;
            for (let i = 2; i < 100; i++) {
                const candidate = base + ' ' + i;
                if (! existing.has(candidate)) return candidate;
            }
            return base + ' ' + Date.now();
        },

        renameGroup(oldName, newName) {
            if (! newName) return;
            for (const i of this.state.ingredients) {
                if (i.group === oldName) i.group = newName;
            }
            this.schedulePreview();
        },

        deleteGroup(name) {
            for (const i of this.state.ingredients) {
                if (i.group === name) i.group = null;
            }
            this.schedulePreview();
        },

        // ---- Method operations ----

        addStep() {
            this.state.method.push({ _key: ++this._keyCounter, text: '' });
            this.schedulePreview();
        },

        removeStep(key) {
            this.state.method = this.state.method.filter((s) => s._key !== key);
            this.schedulePreview();
        },

        autoResize(el) {
            el.style.height = 'auto';
            el.style.height = (el.scrollHeight) + 'px';
        },

        // ---- Frontmatter extras ----

        addExtra() {
            // Phase 11F: dropped window.prompt(). Create a placeholder
            // entry and auto-focus its key input for inline rename.
            let placeholder = 'new_field';
            const existing = Object.keys(this.state.frontmatter.extra || {});
            let i = 1;
            while (existing.includes(placeholder)) {
                i += 1;
                placeholder = 'new_field_' + i;
            }
            this.state.frontmatter.extra = { ...this.state.frontmatter.extra, [placeholder]: '' };
            this.$nextTick(() => {
                const input = this.$el.querySelector('[data-extra-key="'+CSS.escape(placeholder)+'"]');
                if (input) input.focus();
            });
        },

        removeExtra(key) {
            const copy = { ...this.state.frontmatter.extra };
            delete copy[key];
            this.state.frontmatter.extra = copy;
            this.schedulePreview();
        },

        renameExtra(oldKey, newKey) {
            if (! newKey || newKey === oldKey) return;
            const copy = { ...this.state.frontmatter.extra };
            copy[newKey] = copy[oldKey];
            delete copy[oldKey];
            this.state.frontmatter.extra = copy;
            this.schedulePreview();
        },

        // ---- Mode toggling ----

        async switchMode(target) {
            if (target === this.mode) return;
            this.modeSwitching = true;
            try {
                if (target === 'raw') {
                    // Form → Raw: serialize state to markdown server-side
                    // and populate the textarea.
                    const r = await this.postJson(this.routes.serialize, { state: JSON.stringify(this.dehydrate()) });
                    if (r && r.markdown !== undefined) {
                        this.$refs.rawTextarea.value = r.markdown;
                    }
                } else {
                    // Raw → Form: parse the textarea content server-side
                    // and re-hydrate.
                    const md = this.$refs.rawTextarea.value;
                    const r = await this.postJson(this.routes.parse, { markdown: md });
                    if (r && ! r.error) {
                        this.state = this.hydrate(r);
                        this.$nextTick(() => this.initSortables());
                    } else if (r && r.error) {
                        alert('Cannot switch to form mode — the markdown didn\'t parse:\n' + r.error);
                        return;
                    }
                }
                this.mode = target;
            } finally {
                this.modeSwitching = false;
            }
        },

        onRawInput() {
            this.renderRawShadow();
            this.schedulePreview();
        },

        // Phase 11F: syntax-cue overlay restored from 11D.1. The raw-mode
        // textarea is transparent; this method paints the colorized
        // markdown into the shadow `<pre>` that sits beneath it.
        renderRawShadow() {
            const ta = this.$refs.rawTextarea;
            const shadow = this.$refs.rawShadow;
            if (! ta || ! shadow) return;
            shadow.innerHTML = highlightMarkdown(ta.value);
            this.syncRawShadowScroll();
        },

        syncRawShadowScroll() {
            const ta = this.$refs.rawTextarea;
            const shadow = this.$refs.rawShadow;
            if (! ta || ! shadow) return;
            shadow.scrollTop = ta.scrollTop;
            shadow.scrollLeft = ta.scrollLeft;
        },

        onKeydown(ev) {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.target.blur();
                return;
            }
            if (ev.key !== 'Tab') return;
            ev.preventDefault();
            const ta = ev.target;
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const value = ta.value;
            const selectionSpansLines = value.substring(start, end).includes('\n');
            if (ev.shiftKey) {
                // Shift+Tab: unindent. If selection spans lines, unindent each.
                if (selectionSpansLines) {
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const before = value.substring(0, lineStart);
                    const block = value.substring(lineStart, end);
                    const after = value.substring(end);
                    const unindented = block.split('\n').map((l) => l.replace(/^  /, '')).join('\n');
                    ta.value = before + unindented + after;
                    ta.selectionStart = Math.max(lineStart, start - 2);
                    ta.selectionEnd = end - (block.length - unindented.length);
                } else {
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const lineHead = value.substring(lineStart, start);
                    const trimmed = lineHead.replace(/^  /, '');
                    const removed = lineHead.length - trimmed.length;
                    ta.value = value.substring(0, lineStart) + trimmed + value.substring(start);
                    ta.selectionStart = ta.selectionEnd = start - removed;
                }
            } else {
                if (selectionSpansLines) {
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                    const before = value.substring(0, lineStart);
                    const block = value.substring(lineStart, end);
                    const after = value.substring(end);
                    const indented = block.split('\n').map((l) => '  ' + l).join('\n');
                    ta.value = before + indented + after;
                    ta.selectionStart = start + 2;
                    ta.selectionEnd = end + (indented.length - block.length);
                } else {
                    ta.value = value.substring(0, start) + '  ' + value.substring(end);
                    ta.selectionStart = ta.selectionEnd = start + 2;
                }
            }
            // Repaint the shadow after a programmatic edit.
            this.renderRawShadow();
        },

        // ---- Preview ----

        schedulePreview() {
            if (this._previewTimer) clearTimeout(this._previewTimer);
            this._previewTimer = setTimeout(() => this.refreshPreview(), 300);
        },

        scheduleDirtyCheck() {
            if (this._dirtyTimer) clearTimeout(this._dirtyTimer);
            this._dirtyTimer = setTimeout(() => {
                this.dirty = JSON.stringify(this.dehydrate()) !== this._initialStateJson;
            }, 200);
        },

        async refreshPreview() {
            this.previewLoading = true;
            try {
                const body = this.mode === 'form'
                    ? { state: JSON.stringify(this.dehydrate()) }
                    : { markdown: this.$refs.rawTextarea?.value ?? '' };
                const r = await this.postJson(this.routes.preview, body);
                if (r && r.html !== undefined) {
                    this.$refs.previewPane.innerHTML = r.html;
                } else if (r && r.error) {
                    this.$refs.previewPane.innerHTML =
                        '<p class="text-sm text-rose-700 dark:text-rose-400 italic">Preview error: ' + r.error + '</p>';
                }
            } finally {
                this.previewLoading = false;
            }
        },

        // ---- Submit ----

        onSubmit(ev) {
            // In form mode we serialize state into the hidden `state` field
            // BEFORE the form submits. The controller's update() branches
            // on `state` vs `markdown`.
            if (this.mode === 'form') {
                this.$refs.stateField.value = JSON.stringify(this.dehydrate());
            } else {
                // Raw mode: clear `state` so the controller takes the
                // textarea contents.
                this.$refs.stateField.value = '';
            }
        },

        // ---- Plumbing ----

        async postJson(url, body) {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const fd = new FormData();
            for (const [k, v] of Object.entries(body)) fd.append(k, v);
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf || '', 'Accept': 'application/json' },
                body: fd,
            });
            try {
                return await resp.json();
            } catch {
                return { error: 'Server returned non-JSON response' };
            }
        },
    };
};

Alpine.start();
