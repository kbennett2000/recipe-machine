// JavaScript twin of app/Recipes/Display/IngredientFormatter.php.
//
// The two must produce byte-identical output for the same inputs — the
// parity test (tests/Feature/IngredientFormatParityTest.php) execs Node
// against this file and compares against the PHP formatter.
//
// Treat this file as a literal mirror. When you change the PHP version,
// change this one too, then run `composer parity-check`.

const UNIT_DISPLAY = {
    tsp:    { singular: 'tsp', plural: 'tsp' },
    tbsp:   { singular: 'Tbsp', plural: 'Tbsp' },
    cup:    { singular: 'cup', plural: 'cups' },
    floz:   { singular: 'fl oz', plural: 'fl oz' },
    pint:   { singular: 'pint', plural: 'pints' },
    quart:  { singular: 'quart', plural: 'quarts' },
    gallon: { singular: 'gallon', plural: 'gallons' },
    ml:     { singular: 'ml', plural: 'ml' },
    l:      { singular: 'L', plural: 'L' },
    g:      { singular: 'g', plural: 'g' },
    kg:     { singular: 'kg', plural: 'kg' },
    oz:     { singular: 'oz', plural: 'oz' },
    lb:     { singular: 'lb', plural: 'lb' },
};

const IMPRECISE_TRAILING_PHRASE = {
    'to-taste':  'to taste',
    'as-needed': 'as needed',
};

const FRACTIONS = [
    [0.125, '1/8'],
    [0.25,  '1/4'],
    [0.333, '1/3'],
    [0.375, '3/8'],
    [0.5,   '1/2'],
    [0.625, '5/8'],
    [0.667, '2/3'],
    [0.75,  '3/4'],
    [0.875, '7/8'],
];

export function formatIngredient(fields) {
    const amount     = fields.amount      ?? null;
    const amountHigh = fields.amount_high ?? null;
    const unit       = fields.unit        ?? null;
    const unitClass  = fields.unit_class  ?? null;
    const ingredient = fields.ingredient  ?? null;
    const modifier   = fields.modifier    ?? null;
    const optional   = !!fields.optional;

    const optionalTag = optional ? ' (optional)' : '';

    // Imprecise trailing.
    if (unit !== null && IMPRECISE_TRAILING_PHRASE[unit] !== undefined) {
        const phrase = IMPRECISE_TRAILING_PHRASE[unit];
        const sep = unit === 'as-needed' ? ', ' : ' ';
        return ((ingredient ?? '') + sep + phrase).trim() + optionalTag;
    }

    // Imprecise leading.
    if (unitClass === 'imprecise') {
        return 'a ' + unit + ' of ' + ingredient + optionalTag;
    }

    // Phase 9.2: amount-high-only renders with an "up to" prefix. Mirrors
    // the PHP twin — keep this branch byte-identical so the parity test
    // stays green.
    let upToPrefix = '';
    let amt = amount;
    let amtHi = amountHigh;
    if (amt === null && amtHi !== null && amtHi !== undefined) {
        upToPrefix = 'up to ';
        amt = amtHi;
        amtHi = null;
    }

    // Generic.
    const parts = [];

    if (amt !== null) {
        parts.push(formatAmount(amt, amtHi, unit));
    }

    if (unit !== null && unit !== 'whole') {
        const isPlural = amountIsPlural(amt, amtHi);
        const display = UNIT_DISPLAY[unit] || { singular: unit, plural: unit };
        parts.push(isPlural ? display.plural : display.singular);
    }

    if (ingredient !== null && ingredient !== '') {
        parts.push(ingredient);
    }

    let line = (upToPrefix + parts.join(' ')).trim();
    if (modifier !== null && modifier !== '') {
        line += ', ' + modifier;
    }

    return line + optionalTag;
}

export function formatAmount(amount, amountHigh, unit) {
    if (unit === 'whole' && isNonIntegerCount(amount, amountHigh)) {
        const lo = roundToHalf(amount);
        if (amountHigh !== null && amountHigh !== undefined) {
            const hi = roundToHalf(amountHigh);
            return '~' + formatHalfStep(lo) + '–' + formatHalfStep(hi);
        }
        return '~' + formatHalfStep(lo);
    }

    const lo = formatSingleAmount(amount);
    if (amountHigh !== null && amountHigh !== undefined) {
        const hi = formatSingleAmount(amountHigh);
        return lo + '–' + hi;
    }
    return lo;
}

function formatSingleAmount(n) {
    if (n === Math.floor(n) && n < 100) {
        return String(Math.floor(n));
    }

    const whole = Math.floor(n);
    const frac = n - whole;

    for (const [target, label] of FRACTIONS) {
        if (Math.abs(frac - target) < 0.01) {
            return whole > 0 ? whole + ' ' + label : label;
        }
    }
    // Fall back to decimal — match PHP's number_format($n, 2) + rtrim '0' + rtrim '.'
    let formatted = n.toFixed(2);
    formatted = formatted.replace(/0+$/, '').replace(/\.$/, '');
    return formatted;
}

function formatHalfStep(n) {
    if (n === Math.floor(n)) {
        return String(Math.floor(n));
    }
    return n.toFixed(1);
}

function isNonIntegerCount(a, b) {
    if (a !== Math.floor(a)) return true;
    if (b !== null && b !== undefined && b !== Math.floor(b)) return true;
    return false;
}

function roundToHalf(n) {
    return Math.round(n * 2) / 2;
}

function amountIsPlural(amount, amountHigh) {
    const ref = amountHigh ?? amount;
    return ref !== null && ref !== undefined && ref > 1.0;
}
