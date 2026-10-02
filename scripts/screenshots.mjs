#!/usr/bin/env node
// Photographs every page of a running DayFlow, so a UI change can be judged
// against how the page looked before it.
//
//   node scripts/screenshots.mjs --label before
//   node scripts/screenshots.mjs --label after --only today,finance --themes dark
//
// Options (all optional):
//   --base      app address                 (default http://localhost:8089)
//   --label     folder under docs/screenshots (default "shots")
//   --account   shots | shots_empty         (default shots; see seed-screenshots.php)
//   --only      comma list of page names
//   --viewports desktop,mobile              (default both)
//   --themes    light,dark                  (default both)
//
// Needs Microsoft Edge or Chrome and Node 22+. It drives the browser over the
// DevTools protocol itself, so there is nothing to npm install. Sign in uses
// the account that scripts/seed-screenshots.php creates: run that first.

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const VIEWPORTS = {
    desktop: { width: 1366, height: 900, mobile: false },
    mobile: { width: 390, height: 844, mobile: true },
};

// name, path. Names become file names, so they are the page's menu key.
const PAGES = [
    ['today', '/'], ['review', '/review'],
    ['tasks', '/tasks'], ['projects', '/projects'], ['planner', '/planner'],
    ['habits', '/habits'], ['focus', '/focus'], ['skills', '/skills'],
    ['notes', '/notes'], ['quick-notes', '/quick-notes'], ['bookmarks', '/bookmarks'],
    ['finance', '/finance'], ['subscriptions', '/subscriptions'], ['stocks', '/stocks'],
    ['exercise', '/exercise'], ['food-notes', '/food-notes'],
    ['files', '/files'], ['file-tools', '/file-tools'], ['transfer', '/transfer'],
    ['ai', '/ai'], ['calculator', '/calculator'], ['settings', '/settings'],
];

// Live values that change every second and would make two shots differ.
const MASK = '#headerLiveClock, #headerLastUpdate, .clock, [data-live]';
// A page taller than this is cut off: a 200-row list is not worth a 20 000 px image.
const MAX_HEIGHT = 5000;

function args() {
    const out = {};
    const argv = process.argv.slice(2);
    for (let i = 0; i < argv.length; i++) {
        if (!argv[i].startsWith('--')) continue;
        out[argv[i].slice(2)] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
    }
    return out;
}

const opt = args();
const BASE = String(opt.base || 'http://localhost:8089').replace(/\/$/, '');
const LABEL = String(opt.label || 'shots');
const ACCOUNT = String(opt.account || 'shots');
const VIEWPORT_NAMES = String(opt.viewports || 'desktop,mobile').split(',');
const THEMES = String(opt.themes || 'light,dark').split(',');
const ONLY = opt.only ? String(opt.only).split(',') : null;
const OUT = join(ROOT, 'docs', 'screenshots', LABEL);

function findBrowser() {
    const candidates = [
        process.env.BROWSER,
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        '/usr/bin/microsoft-edge', '/usr/bin/google-chrome', '/usr/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ].filter(Boolean);
    const found = candidates.find(existsSync);
    if (!found) throw new Error('ไม่พบ Edge หรือ Chrome: ตั้งตัวแปร BROWSER เป็นตำแหน่งของโปรแกรม');
    return found;
}

/** The password the seed script gave the account, read from that script so the two never drift. */
function accountPassword() {
    const seed = readFileSync(join(ROOT, 'scripts', 'seed-screenshots.php'), 'utf8');
    const match = seed.match(/SHOTS_PASSWORD\s*=\s*'([^']+)'/);
    if (!match) throw new Error('หารหัสผ่านใน scripts/seed-screenshots.php ไม่เจอ');
    return match[1];
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

// ---------------------------------------------------------------- DevTools

class Cdp {
    constructor(url) {
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = [];
        this.ws = new WebSocket(url);
        this.ready = new Promise((ok, fail) => {
            this.ws.onopen = ok;
            this.ws.onerror = () => fail(new Error('เชื่อมต่อ DevTools ไม่ได้'));
        });
        this.ws.onmessage = ({ data }) => {
            const msg = JSON.parse(data);
            if (msg.id) {
                const call = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                if (msg.error) call.fail(new Error(`${call.method}: ${msg.error.message}`));
                else call.ok(msg.result);
            } else {
                this.listeners.forEach(fn => fn(msg));
            }
        };
    }

    send(method, params = {}, sessionId) {
        const id = this.nextId++;
        this.ws.send(JSON.stringify({ id, method, params, sessionId }));
        return new Promise((ok, fail) => this.pending.set(id, { ok, fail, method }));
    }

    /** Resolves on the first event of this name from this session. */
    once(method, sessionId, timeoutMs = 20000) {
        return new Promise((ok, fail) => {
            const timer = setTimeout(() => fail(new Error(`รอ ${method} นานเกินไป`)), timeoutMs);
            const fn = msg => {
                if (msg.method === method && msg.sessionId === sessionId) {
                    clearTimeout(timer);
                    this.listeners = this.listeners.filter(l => l !== fn);
                    ok(msg.params);
                }
            };
            this.listeners.push(fn);
        });
    }
}

async function launch() {
    const profile = mkdtempSync(join(tmpdir(), 'dayflow-shots-'));
    const proc = spawn(findBrowser(), [
        '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
        '--force-color-profile=srgb', '--remote-debugging-port=0',
        `--user-data-dir=${profile}`, 'about:blank',
    ], { stdio: 'ignore' });

    // The browser reports the port it picked in a file inside its profile.
    const portFile = join(profile, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
    if (!existsSync(portFile)) throw new Error('เบราว์เซอร์ไม่เปิดพอร์ต DevTools');
    const [port, path] = readFileSync(portFile, 'utf8').trim().split('\n');

    const cdp = new Cdp(`ws://127.0.0.1:${port}${path}`);
    await cdp.ready;
    const stop = () => {
        try { cdp.ws.close(); } catch { /* already closed */ }
        proc.kill();
        try { rmSync(profile, { recursive: true, force: true }); } catch { /* the browser may still hold it */ }
    };
    return { cdp, stop };
}

async function openPage(cdp) {
    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
    await cdp.send('Page.enable', {}, sessionId);
    await cdp.send('Runtime.enable', {}, sessionId);
    return sessionId;
}

// ---------------------------------------------------------------- one page

class Tab {
    constructor(cdp, sessionId) { this.cdp = cdp; this.sid = sessionId; }

    send(method, params) { return this.cdp.send(method, params, this.sid); }

    async eval(expression) {
        const res = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (res.exceptionDetails) throw new Error(res.exceptionDetails.exception?.description || res.exceptionDetails.text);
        return res.result.value;
    }

    async viewport({ width, height, mobile }) {
        await this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    }

    async goto(url) {
        const loaded = this.cdp.once('Page.loadEventFired', this.sid);
        await this.send('Page.navigate', { url });
        await loaded;
    }

    /** Waits until nothing on the page says it is still loading. */
    async settle() {
        for (let i = 0; i < 40; i++) {
            const busy = await this.eval(
                `!!document.querySelector('.spinner, .widget-loading, [aria-busy="true"]')`
            );
            if (!busy) break;
            await sleep(250);
        }
        await this.eval('document.fonts.ready.then(() => true)');
        await sleep(300);
    }

    /** Freezes motion and live values so two runs of the same page look the same. */
    async freeze() {
        await this.eval(`(() => {
            const css = document.createElement('style');
            css.textContent = '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' +
                '${MASK}{visibility:hidden!important}';
            document.head.appendChild(css);
        })()`);
    }

    async theme(name) {
        await this.eval(`document.documentElement.setAttribute('data-theme', ${JSON.stringify(name)})`);
        await sleep(150);
    }

    /** The page's full height, including a scroll area that is not the document itself. */
    pageHeight() {
        return this.eval(`Math.ceil(Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight,
            ...[...document.querySelectorAll('main, .app-main, .app-content')].map(el => el.scrollHeight)
        ))`);
    }

    /**
     * Grows the window to the page's height, so a fixed sidebar or tab bar spans
     * the whole picture instead of stopping where the first screen ended. A page
     * that sizes itself to the window can grow again, so it is measured twice.
     */
    async capture(file, vp) {
        let height = vp.height;
        for (let pass = 0; pass < 2; pass++) {
            const wanted = Math.min(Math.max(await this.pageHeight(), vp.height), MAX_HEIGHT);
            if (wanted === height && pass > 0) break;
            height = wanted;
            await this.viewport({ ...vp, height });
            await sleep(200);
        }
        const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(file, Buffer.from(data, 'base64'));
        await this.viewport(vp);
    }
}

// ---------------------------------------------------------------- main

async function signIn(tab, password) {
    await tab.goto(`${BASE}/login`);
    const result = await tab.eval(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const res = await fetch('${BASE}/api/auth/login', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ identifier: ${JSON.stringify(ACCOUNT)}, password: ${JSON.stringify(password)}, remember_device: false })
        });
        return { ok: res.ok, status: res.status };
    })()`);
    if (!result.ok) {
        throw new Error(`เข้าสู่ระบบไม่ได้ (HTTP ${result.status}): รัน php scripts/seed-screenshots.php ในแอปก่อนหรือยัง`);
    }
}

async function main() {
    const unknown = (ONLY || []).filter(n => !PAGES.some(([name]) => name === n) && n !== 'login');
    if (unknown.length) throw new Error(`ไม่รู้จักหน้า: ${unknown.join(', ')}`);

    mkdirSync(OUT, { recursive: true });
    const { cdp, stop } = await launch();
    const failures = [];
    let count = 0;

    try {
        const tab = new Tab(cdp, await openPage(cdp));

        // The sign-in page is only worth photographing while signed out.
        const wants = name => !ONLY || ONLY.includes(name);
        const shoot = async (name, path, viewportName, theme) => {
            const vp = VIEWPORTS[viewportName];
            await tab.viewport(vp);
            await tab.goto(BASE + path);
            await tab.freeze();
            await tab.settle();
            const landed = await tab.eval('location.pathname');
            if (name !== 'login' && landed.endsWith('/login')) throw new Error('ถูกส่งกลับไปหน้าเข้าสู่ระบบ');
            await tab.theme(theme);
            await tab.capture(join(OUT, `${name}-${viewportName}-${theme}.png`), vp);
            count++;
        };

        if (wants('login')) {
            for (const v of VIEWPORT_NAMES) for (const t of THEMES) {
                await shoot('login', '/login', v, t).catch(e => failures.push(`login ${v} ${t}: ${e.message}`));
            }
        }

        await signIn(tab, accountPassword());

        for (const [name, path] of PAGES.filter(([n]) => wants(n))) {
            for (const v of VIEWPORT_NAMES) for (const t of THEMES) {
                try {
                    await shoot(name, path, v, t);
                    process.stdout.write('.');
                } catch (e) {
                    failures.push(`${name} ${v} ${t}: ${e.message}`);
                    process.stdout.write('x');
                }
            }
        }
    } finally {
        stop();
    }

    console.log(`\nถ่ายแล้ว ${count} ภาพ → ${OUT}`);
    if (failures.length) {
        console.error(`ไม่สำเร็จ ${failures.length} ภาพ:\n  ` + failures.join('\n  '));
        process.exitCode = 1;
    }
}

main().catch(e => { console.error(e.message); process.exit(1); });
