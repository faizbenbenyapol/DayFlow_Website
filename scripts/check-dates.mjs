#!/usr/bin/env node
// Checks that app.js reads the server's dates as the visitor's own days.
//
//   node scripts/check-dates.mjs
//
// new Date('2026-10-24') is midnight UTC: west of Greenwich every date on a
// page showed a day early and a task due today counted as overdue. This runs
// the helpers from public/assets/js/app.js in zones on both sides of UTC and
// exits 1 when one of them gets the day wrong.

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const src = readFileSync(join(ROOT, 'public', 'assets', 'js', 'app.js'), 'utf8').split('\r\n').join('\n');

/** The source of one top-level function in app.js. */
function pick(name) {
    const start = src.indexOf('function ' + name + '(');
    if (start < 0) throw new Error('app.js has no function ' + name);
    return src.slice(start, src.indexOf('\n}\n', start) + 3);
}

const names = ['parseServerDate', 'formatDate', 'formatDateTime', 'daysUntil', 'todayISO'];
const helpers = new Function(names.map(pick).join('\n') + '\nreturn { ' + names.join(', ') + ' };')();

const ZONES = ['Pacific/Honolulu', 'America/New_York', 'UTC', 'Asia/Bangkok', 'Pacific/Auckland'];
let failed = 0;

function expect(zone, what, actual, wanted) {
    const ok = actual === wanted;
    if (!ok) failed++;
    console.log((ok ? 'ok    ' : 'FAIL  ') + zone.padEnd(18) + what + ': ' + actual + (ok ? '' : ' (want ' + wanted + ')'));
}

for (const zone of ZONES) {
    process.env.TZ = zone;   // Node re-reads the zone when TZ changes
    const today = helpers.todayISO();

    expect(zone, 'formatDate', helpers.formatDate('2026-10-24'), '24 ต.ค. 2569');
    expect(zone, 'formatDateTime', helpers.formatDateTime('2026-10-24 09:30:00'), '24 ต.ค. 2569 09:30 น.');
    expect(zone, 'due today', helpers.daysUntil(today), 0);
    expect(zone, 'parse keeps the day', helpers.parseServerDate('2026-01-01').getDate(), 1);
}

if (failed) {
    console.error('\n' + failed + ' date check(s) failed.');
    process.exit(1);
}
console.log('\nDates read as the visitor\'s own days in every zone.');
