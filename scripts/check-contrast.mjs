#!/usr/bin/env node
// Checks that the colour pairs in public/assets/css/tokens.css are readable.
//
//   node scripts/check-contrast.mjs
//
// Exits 1 when a pair falls below its WCAG 2.1 ratio, so CI stops a palette
// edit that makes text hard to read. Add a pair to PAIRS when a new token is
// used for text or for a control's edge.
//
//   text   4.5:1  body-size text
//   ui     3:1    the edge of a field, a checkbox, a focus ring
//
// A pair marked "info" is printed but never fails: the weekday marker beside
// the open menu entry is a 3px stripe whose meaning is also carried by the
// entry's bold text, so it is decoration for contrast purposes.

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const css = readFileSync(join(ROOT, 'public', 'assets', 'css', 'tokens.css'), 'utf8');

const TEXT = 4.5;
const UI = 3;

// Foreground, background, required ratio, what it is for.
const PAIRS = [
    ['ink',            'paper',        TEXT, 'body text'],
    ['ink',            'paper-sunk',   TEXT, 'text in a field or hovered row'],
    ['pencil',         'paper',        TEXT, 'secondary text'],
    ['pencil',         'paper-sunk',   TEXT, 'secondary text in a field or rail'],
    ['stamp-red',      'paper',        TEXT, 'overdue and expense text'],
    ['stamp-red',      'paper-sunk',   TEXT, 'overdue text in a field or rail'],
    ['ledger-green',   'paper',        TEXT, 'income and done text'],
    ['ledger-green',   'paper-sunk',   TEXT, 'income text in a field or rail'],
    ['caution',        'paper',        TEXT, 'due-today text'],
    ['caution',        'paper-sunk',   TEXT, 'due-today text in a field or rail'],
    ['ink',            'highlighter',  TEXT, 'a search hit'],
    ['paper',          'ink',          TEXT, 'text on a primary button'],
    ['rule-strong',    'paper',        UI,   'field and checkbox edge'],
    ['rule-strong',    'paper-sunk',   UI,   'field edge on the rail'],
];

const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

/** The declarations of the first rule whose selector is exactly `selector`. */
function block(selector) {
    const start = css.indexOf(selector + ' {');
    if (start < 0) throw new Error(`tokens.css has no "${selector}" rule`);
    const end = css.indexOf('}', start);
    return css.slice(start, end);
}

/** name -> value for every custom property declared in a rule body. */
function declarations(body) {
    const out = {};
    for (const m of body.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/g)) out[m[1]] = m[2].trim();
    return out;
}

function resolveVar(value, scope) {
    const m = value.match(/^var\(--([a-z0-9-]+)\)$/);
    return m ? resolveVar(scope[m[1]] ?? '', scope) : value;
}

function channel(hex, i) {
    const v = parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
}

function luminance(hex) {
    return 0.2126 * channel(hex, 0) + 0.7152 * channel(hex, 1) + 0.0722 * channel(hex, 2);
}

function ratio(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

const light = declarations(block(':root'));
const dark = { ...light, ...declarations(block('[data-theme="dark"]')) };

let failures = 0;
const rows = [];

function check(theme, scope, fg, bg, need, why, { info = false } = {}) {
    const a = resolveVar(scope[fg] ?? '', scope);
    const b = resolveVar(scope[bg] ?? '', scope);
    if (!/^#[0-9a-fA-F]{6}$/.test(a) || !/^#[0-9a-fA-F]{6}$/.test(b)) {
        throw new Error(`${theme}: --${fg} (${a}) or --${bg} (${b}) is not a six-digit hex colour`);
    }
    const got = ratio(a, b);
    const ok = got >= need;
    if (!ok && !info) failures++;
    rows.push(`${ok ? 'ok  ' : info ? 'info' : 'FAIL'}  ${theme.padEnd(5)} ${got.toFixed(2).padStart(5)} (need ${need})  --${fg} on --${bg}: ${why}`);
}

for (const [theme, scope] of [['light', light], ['dark', dark]]) {
    for (const [fg, bg, need, why] of PAIRS) check(theme, scope, fg, bg, need, why);
}

// Each weekday colour: the text on it must read, in both themes. The marker's
// own contrast against the paper is reported but is not a failure.
for (const day of DAYS) {
    const body = declarations(block(`[data-day="${day}"]`));
    for (const [theme, scope] of [['light', light], ['dark', dark]]) {
        const withDay = { ...scope, 'day': body.day, 'on-day': body['on-day'] };
        check(theme, withDay, 'on-day', 'day', TEXT, `text on the ${day} date block`);
        check(theme, withDay, 'day', 'paper', UI, `the ${day} marker beside the open menu entry`, { info: true });
    }
}

console.log(rows.join('\n'));
console.log(failures === 0 ? '\nAll required pairs pass.' : `\n${failures} required pair(s) fail.`);
process.exit(failures === 0 ? 0 : 1);
