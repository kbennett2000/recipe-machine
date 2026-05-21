// Node-side driver for the parity test. Reads JSON from stdin, runs each
// input field-set through the JS formatIngredient, prints results as JSON.
//
// The PHP test invokes this via `node tests/scripts/run-formatter.mjs < cases.json`
// and compares each Node output against the matching PHP IngredientFormatter
// output. Any divergence fails the parity test.

import { formatIngredient } from '../../resources/js/ingredient-format.js';
import { readFileSync } from 'node:fs';

const raw = readFileSync(0, 'utf8');  // stdin
const cases = JSON.parse(raw);
const out = cases.map((c) => formatIngredient(c));
process.stdout.write(JSON.stringify(out));
