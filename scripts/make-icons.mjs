#!/usr/bin/env node
// Draws the PWA icons: a tear-off calendar sheet, binding holes in a yellow
// band, and a serif D, on ink. Re-run it when the mark changes.
//
//   node scripts/make-icons.mjs
//
// Writes public/assets/icons/icon-192.png, icon-512.png and icon-maskable-512.png.
// Rendered in the browser the other scripts use, so the D is the app's own
// IBM Plex Serif rather than whatever font a drawing tool has.

import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Tab, launch, openPage, sleep } from './screenshots.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ICONS = join(ROOT, 'public', 'assets', 'icons');
const FONT = pathToFileURL(join(ROOT, 'public', 'assets', 'fonts', 'plexserif-latin-500.woff2')).href;

// The palette of tokens.css, which the icon cannot read: ink, paper, Monday's yellow.
const INK = '#1B2330';
const PAPER = '#FBFAF7';
const BAND = '#F2C200';

// Everything lives inside the central 80% circle, which is all a maskable icon
// is guaranteed to keep when the system crops it to a circle or a squircle.
function drawing(round) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="${round ? 96 : 0}" fill="${INK}"/>
  <rect x="120" y="112" width="272" height="288" fill="${PAPER}"/>
  <rect x="120" y="112" width="272" height="72" fill="${BAND}"/>
  <circle cx="192" cy="148" r="11" fill="${INK}"/>
  <circle cx="320" cy="148" r="11" fill="${INK}"/>
  <text x="256" y="362" text-anchor="middle" font-family="DayFlowSerif" font-weight="500" font-size="190" fill="${INK}">D</text>
</svg>`;
}

const page = round => `<!doctype html><meta charset="utf-8">
<style>@font-face{font-family:DayFlowSerif;src:url("${FONT}") format("woff2");font-weight:500}
html,body{margin:0;background:transparent}svg{display:block}</style>${drawing(round)}`;

async function render(tab, dir, round, size, file) {
    const html = join(dir, `icon-${round ? 'any' : 'maskable'}.html`);
    writeFileSync(html, page(round));
    await tab.goto(pathToFileURL(html).href);
    await tab.eval('document.fonts.ready.then(() => true)');
    await sleep(200);
    await tab.send('Emulation.setDeviceMetricsOverride', { width: 512, height: 512, deviceScaleFactor: size / 512, mobile: false });
    await tab.send('Emulation.setDefaultBackgroundColorOverride', { color: { r: 0, g: 0, b: 0, a: 0 } });
    const { data } = await tab.send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: 512, height: 512, scale: 1 }, fromSurface: true });
    writeFileSync(join(ICONS, file), Buffer.from(data, 'base64'));
    console.log(`  ${file}  ${size}px`);
}

const dir = mkdtempSync(join(tmpdir(), 'dayflow-icons-'));
const { cdp, stop } = await launch();
try {
    const tab = new Tab(cdp, await openPage(cdp));
    await render(tab, dir, true, 192, 'icon-192.png');
    await render(tab, dir, true, 512, 'icon-512.png');
    await render(tab, dir, false, 512, 'icon-maskable-512.png');
} finally {
    stop();
    rmSync(dir, { recursive: true, force: true });
}
