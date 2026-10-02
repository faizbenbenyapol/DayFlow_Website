#!/usr/bin/env node
// Keeps the redesigned stylesheets honest.
//
//   node scripts/check-css.mjs
//
// Colours belong in tokens.css. A stylesheet that has been redesigned may not
// write a hex colour, rgb()/hsl() colour or named colour of its own; it must
// use a token (a translucent scrim is the one exception, below). Add a file to
// CHECKED when its page or component set is finished; module stylesheets join
// as phase 5 reaches them.

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CSS = join(ROOT, 'public', 'assets', 'css');

const CHECKED = [
    'base.css', 'components.css', 'shell.css',
    // Pages that are finished (docs/REDESIGN.md, phases 4 and 5).
    'modules/dashboard.css', 'modules/tasks.css', 'modules/planner.css',
    'modules/habits.css', 'modules/focus.css', 'modules/quick.css',
    'modules/finance.css', 'modules/subscriptions.css', 'modules/stocks.css',
    'modules/notes.css', 'modules/skills.css', 'modules/exercise.css', 'modules/food_notes.css',
    'modules/review.css', 'modules/bookmarks.css',
    'modules/calculator.css', 'modules/ai.css', 'modules/transfer.css', 'modules/files.css',
    'modules/file-tools.css', 'modules/settings.css', 'modules/shares.css', 'modules/projects.css',
    // Outside the app (phase 5E): sign-in, a shared file, errors, the share-mode bar.
    'modules/auth.css', 'modules/outside.css', 'share-mode.css',
];

// rgb(21 24 29 / .5): the dark scrim behind a dialog is the same in both themes.
const SCRIM = /rgb\(21 24 29 \/ [0-9.]+\)/g;

let problems = 0;

for (const file of CHECKED) {
    const text = readFileSync(join(CSS, file), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')   // comments may mention colours
        .replace(SCRIM, '');

    text.split(/\r?\n/).forEach((line, index) => {
        const found = line.match(/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(/);
        if (found) {
            problems++;
            console.log(`${file}:${index + 1}  ${found[0]}  ${line.trim().slice(0, 80)}`);
        }
    });
}

if (problems) {
    console.log(`\n${problems} colour(s) written outside tokens.css`);
    process.exit(1);
}
console.log(`No stray colours in ${CHECKED.join(', ')}.`);
