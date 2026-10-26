#!/usr/bin/env node
// Looks for the faults a screenshot hides. Every page of the app, at a small
// phone, a phone, a tablet and a desktop width, with every dialog on the page
// opened in turn:
//
//   layout     a box that spills past the page, text that runs out of its
//              box, controls drawn on top of each other, a scrollbar nobody
//              meant, anything drawn over an open dialog
//   reading    text whose colour does not stand out from what is behind it
//              (WCAG 2.1: 4.5:1, or 3:1 for large text)
//   naming     a button, link or field a screen reader cannot name; an id
//              used twice; an image with no alt
//
//   node scripts/audit-layout.mjs [--base http://localhost:8080] [--only finance]
//                                 [--theme light|dark] [--account shots_empty]
//
// Signs in as the account scripts/seed-screenshots.php creates (--account
// picks another of its accounts). Prints one line per fault and exits 1 when
// there is any.

import { Tab, accountPassword, launch, openPage, signIn, sleep } from './screenshots.mjs';

const arg = name => (process.argv.includes(name) ? process.argv[process.argv.indexOf(name) + 1] : null);
const BASE = (arg('--base') || 'http://localhost:8080').replace(/\/$/, '');
const ONLY = arg('--only');
const THEME = arg('--theme') || 'light';

// NOTE stands for the first note's editor; it is looked up once signed in.
const PAGES = [
    '/', '/review', '/tasks', '/projects', '/planner', '/habits', '/focus', '/skills', '/exercise',
    '/food-notes', '/finance', '/subscriptions', '/stocks', '/notes', 'NOTE', '/quick-notes', '/bookmarks',
    '/files', '/transfer', '/file-tools', '/ai', '/calculator', '/settings', '/no-such-page',
];
const WIDTHS = [[320, 640, true], [390, 844, true], [820, 1000, false], [1366, 900, false]];

// Runs in the page. Returns a list of faults as short strings.
// root: the element to check (the page, or one open dialog); label: a prefix.
const PROBE = `((root, label) => {
    const faults = [];
    const name = el => {
        let s = el.tagName.toLowerCase();
        if (el.id) s += '#' + el.id;
        const cls = [...el.classList].slice(0, 2).join('.');
        if (cls) s += '.' + cls;
        const text = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\\s+/g, ' ').slice(0, 24);
        return s + (text ? ' "' + text + '"' : '');
    };
    const visible = el => {
        const r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) return false;
        // The inside of a closed <details> still has a box, but nobody sees it.
        if (el.checkVisibility && !el.checkVisibility({ contentVisibilityAuto: true, opacityProperty: true, visibilityProperty: true })) return false;
        if (el.closest('details:not([open])') && !el.closest('summary')) return false;
        const cs = getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.05;
    };
    const hiddenAway = el => !!el.closest('dialog:not([open]), [hidden], .modal-backdrop:not(.active), .modal-overlay[hidden], [aria-hidden="true"]');
    // Inside something that scrolls or clips on purpose: not a spill.
    const clipped = el => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const o = getComputedStyle(p);
            if (/(auto|scroll|hidden|clip)/.test(o.overflowX + o.overflow)) return p;
        }
        return null;
    };

    if (!root) root = document.body;
    if (!label && document.documentElement.scrollWidth > innerWidth + 1) {
        faults.push('page scrolls sideways by ' + (document.documentElement.scrollWidth - innerWidth) + 'px');
    }

    // An open dialog must be on top: nothing from the page may be drawn over it.
    if (label) {
        const r = root.getBoundingClientRect();
        const covers = new Set();
        for (let i = 1; i < 6; i++) for (let j = 1; j < 6; j++) {
            const x = r.left + r.width * i / 6, y = Math.max(r.top, 0) + (Math.min(r.bottom, innerHeight) - Math.max(r.top, 0)) * j / 6;
            const hit = document.elementFromPoint(x, y);
            if (hit && !root.contains(hit) && !hit.contains(root)) covers.add(name(hit.closest('nav, header, button, a, div') || hit));
        }
        covers.forEach(c => faults.push('drawn over the open dialog: ' + c));
    }

    // ---------- colour maths for the reading check ----------
    const parse = c => {
        const m = c.match(/rgba?\\(([^)]+)\\)/);
        if (!m) return null;
        const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const lum = ({ r, g, b }) => {
        const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const over = (top, under) => ({
        r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1,
    });
    // What is painted behind an element: the first solid background up the
    // tree, with any see-through ones in between laid over it. null when an
    // image or gradient is in the way, since its colour cannot be known.
    const behind = el => {
        const layers = [];
        for (let p = el; p; p = p.parentElement) {
            const cs = getComputedStyle(p);
            if (cs.backgroundImage !== 'none' && !/^linear-gradient\\(transparent/.test(cs.backgroundImage)) return null;
            const c = parse(cs.backgroundColor);
            if (c && c.a > 0) { layers.push(c); if (c.a >= 1) break; }
        }
        let base = { r: 255, g: 255, b: 255, a: 1 };
        for (let i = layers.length - 1; i >= 0; i--) base = over(layers[i], base);
        return base;
    };

    const box = root.getBoundingClientRect();
    const all = [...root.querySelectorAll('*')];
    for (const el of all) {
        if (!visible(el) || hiddenAway(el)) continue;
        const r = el.getBoundingClientRect();
        if ((r.right > box.right + 1 || r.left < box.left - 1) && !clipped(el) && root !== document.body) {
            faults.push('spills past the page: ' + name(el) + ' (' + Math.round(r.left) + '..' + Math.round(r.right) + ' vs ' + Math.round(box.left) + '..' + Math.round(box.right) + ')');
        }
        const cs = getComputedStyle(el);
        // A scrollbar nobody meant: a box set to scroll whose content is only
        // a few pixels too big, usually a negative margin under a tab.
        const overY = el.scrollHeight - el.clientHeight, overX = el.scrollWidth - el.clientWidth;
        if (/(auto|scroll)/.test(cs.overflowY) && overY > 0 && overY <= 8) faults.push('stray vertical scrollbar: ' + name(el) + ' (' + overY + 'px)');
        if (/(auto|scroll)/.test(cs.overflowX) && overX > 0 && overX <= 4) faults.push('stray horizontal scrollbar: ' + name(el) + ' (' + overX + 'px)');
        // Text running out of a control or a label.
        if (el.matches('button, .btn, label, .tag, .badge, .cat-chip, td, th, .form-control, .subnav-link, .flag, .menu-tile') && el.scrollWidth > el.clientWidth + 2) {
            if (!/(auto|scroll)/.test(cs.overflowX) && cs.textOverflow !== 'ellipsis' && el.tagName !== 'SELECT' && el.tagName !== 'INPUT') {
                faults.push('text runs out of its box: ' + name(el) + ' (' + el.scrollWidth + ' > ' + el.clientWidth + ')');
            }
        }
        // Reading: only elements that carry text of their own.
        const ownText = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim() !== '');
        // Text set to font-size 0 (a label shrunk to a bar of colour) is not read.
        if (ownText && parseFloat(cs.fontSize) >= 2 && !el.closest(':disabled, [aria-disabled="true"], .skel, option, svg')) {
            const fg = parse(cs.color);
            const bg = behind(el);
            if (fg && bg) {
                const f = fg.a < 1 ? over(fg, bg) : fg;
                const [hi, lo] = [lum(f), lum(bg)].sort((a, b) => b - a);
                const ratio = (hi + 0.05) / (lo + 0.05);
                const size = parseFloat(cs.fontSize), weight = Number(cs.fontWeight) || 400;
                const need = size >= 24 || (size >= 18.66 && weight >= 700) ? 3 : 4.5;
                if (ratio < need) faults.push('hard to read: ' + name(el) + ' (' + ratio.toFixed(2) + ':1, needs ' + need + ')');
            }
        }
    }

    // Naming: what a screen reader would say for each control.
    const controlsAll = [...root.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role="button"], [role="checkbox"], [role="tab"]')]
        .filter(el => !hiddenAway(el) && (visible(el) || el.matches('input.sr-only, .sr-only input')));
    for (const el of controlsAll) {
        const labelled = el.getAttribute('aria-labelledby');
        const named = (el.getAttribute('aria-label') || '').trim()
            || (labelled && labelled.split(/\\s+/).map(id => document.getElementById(id)?.textContent || '').join('').trim())
            || (el.labels && [...el.labels].map(l => l.textContent).join('').trim())
            || (el.closest('label')?.textContent || '').trim()
            || (el.matches('a, button, [role]') ? el.textContent.trim() : '')
            || (el.getAttribute('title') || '').trim()
            || [...el.querySelectorAll('img[alt]')].map(i => i.alt).join('').trim()
            || (el.matches('input[type=submit], input[type=button]') ? el.value : '')
            || (el.getAttribute('placeholder') || '').trim();
        if (!named) faults.push('no name for a screen reader: ' + name(el) + (el.getAttribute('type') ? ' [type=' + el.getAttribute('type') + ']' : ''));
    }
    if (!label) {
        const ids = {};
        document.querySelectorAll('[id]').forEach(el => { ids[el.id] = (ids[el.id] || 0) + 1; });
        Object.entries(ids).filter(([, n]) => n > 1).forEach(([id, n]) => faults.push('id used ' + n + ' times: #' + id));
        document.querySelectorAll('img:not([alt])').forEach(img => faults.push('image without alt: ' + (img.getAttribute('src') || '').slice(0, 60)));
    }

    // Controls on top of each other. Each box is cut down to what its
    // scrolling ancestors let show, so a field scrolled under a dialog's
    // footer does not count as covered by it.
    const shown = el => {
        let r = el.getBoundingClientRect();
        let b = { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const o = getComputedStyle(p);
            if (/(auto|scroll|hidden|clip)/.test(o.overflow + o.overflowX + o.overflowY)) {
                const c = p.getBoundingClientRect();
                b = { left: Math.max(b.left, c.left), top: Math.max(b.top, c.top), right: Math.min(b.right, c.right), bottom: Math.min(b.bottom, c.bottom) };
            }
        }
        return b;
    };
    const controls = [...(label ? root : document).querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')]
        .filter(el => visible(el) && !hiddenAway(el))
        .map(el => [el, shown(el)]);
    const fixed = el => { for (let p = el; p; p = p.parentElement) if (getComputedStyle(p).position === 'fixed') return true; return false; };
    for (let i = 0; i < controls.length; i++) {
        for (let j = i + 1; j < controls.length; j++) {
            const [a, ra] = controls[i], [b, rb] = controls[j];
            if (a.contains(b) || b.contains(a)) continue;
            if (a.closest('label') && a.closest('label') === b.closest('label')) continue;
            // A control placed inside its neighbour on purpose (the "show
            // password" word inside the field, which leaves room for it).
            if (a.parentElement === b.parentElement && [a, b].some(e => getComputedStyle(e).position === 'absolute')) continue;
            const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
            const h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
            if (w > 3 && h > 3) {
                // A fixed bar over scrolled content is expected; only report when both scroll together.
                if (fixed(a) !== fixed(b)) continue;
                faults.push('overlap: ' + name(a) + ' and ' + name(b) + ' (' + Math.round(w) + 'x' + Math.round(h) + ')');
            }
        }
    }
    return [...new Set(faults)].map(f => (label ? label + ' ' : '') + f);
})`;

// Opens the n-th dialog of the page, whatever kind it is, and tags the box to
// check with data-audit-open. Returns a short name for it, or null.
const OPEN_DIALOG = n => `(() => {
    const all = [...document.querySelectorAll('.modal-backdrop, dialog.sheet, .modal-overlay')];
    const d = all[${n}];
    if (!d) return null;
    document.querySelectorAll('[data-audit-open]').forEach(x => x.removeAttribute('data-audit-open'));
    if (d.matches('dialog')) { d.showModal(); d.setAttribute('data-audit-open', ''); }
    else if (d.matches('.modal-backdrop')) { d.classList.add('active'); (d.querySelector('.modal') || d).setAttribute('data-audit-open', ''); }
    else { d.hidden = false; (d.querySelector('.modal-box') || d).setAttribute('data-audit-open', ''); }
    return String(d.id || d.className).slice(0, 40);
})()`;

const CLOSE_DIALOGS = `(() => {
    document.querySelectorAll('dialog[open]').forEach(d => d.close());
    document.querySelectorAll('.modal-backdrop.active').forEach(d => d.classList.remove('active'));
    document.querySelectorAll('.modal-overlay').forEach(d => { d.hidden = true; });
    window.onbeforeunload = null;
    return true;
})()`;

// Tab.goto() leaves its load-event wait behind when Page.navigate itself
// fails; that wait rejects later with nobody listening. The retry below
// already handles the failed page, so the stray rejection is dropped.
process.on('unhandledRejection', () => {});

async function main() {
    const { cdp, stop } = await launch();
    let total = 0;
    try {
        const tab = new Tab(cdp, await openPage(cdp));
        // A form the audit opened may ask "leave this page?"; say yes, or the
        // next page never loads.
        cdp.listeners.push(msg => {
            if (msg.method === 'Page.javascriptDialogOpening') tab.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
        });
        // Pages a signed-out visitor sees, at every width, before signing in.
        // A '#' runs a script on the page first (the register tab).
        const OUTSIDE = ['/login', '/login#register', '/no-such-page', '/offline.html', '/share/0000'];
        for (const [width, height, mobile] of WIDTHS) {
            await tab.viewport({ width, height, mobile });
            for (const page of OUTSIDE.filter(p => !ONLY || p.includes(ONLY))) {
                const [path, step] = page.split('#');
                try { await tab.goto(BASE + path); await tab.settle(); } catch { console.log(`${width}px ${page}  did not finish loading`); total++; continue; }
                if (step === 'register') await tab.eval(`document.getElementById('tabRegister')?.click()`);
                if (THEME === 'dark') await tab.eval(`document.documentElement.dataset.theme = 'dark'`);
                await sleep(300);
                const faults = await tab.eval(PROBE + `(document.querySelector('.auth-book, main, body'), '')`);
                for (const f of faults) console.log(`${width}px ${page}  ${f}`);
                total += faults.length;
            }
        }

        await signIn(tab, accountPassword());
        const firstNote = await tab.eval(`fetch('${BASE}/api/notes', { credentials: 'same-origin' }).then(r => r.json()).then(d => (d.notes || [])[0]?.id || null).catch(() => null)`);
        const pages = PAGES
            .map(p => (p === 'NOTE' ? (firstNote ? '/notes/' + firstNote : null) : p))
            .filter(p => p && (!ONLY || p.includes(ONLY)));

        for (const [width, height, mobile] of WIDTHS) {
            await tab.viewport({ width, height, mobile });
            for (const path of pages) {
                // A slow page gets one more try; a page that never loads is a
                // fault of its own rather than the end of the run.
                let loaded = false;
                for (let attempt = 0; attempt < 2 && !loaded; attempt++) {
                    try { await tab.goto(BASE + path); await tab.settle(); loaded = true; } catch { await sleep(1000); }
                }
                if (!loaded) { console.log(`${width}px ${path}  did not finish loading`); total++; continue; }
                if (THEME === 'dark') await tab.eval(`document.documentElement.dataset.theme = 'dark'`);
                await sleep(500);
                const faults = await tab.eval(PROBE + `(document.querySelector('.app-main'), '')`);
                const dialogs = await tab.eval(`document.querySelectorAll('.modal-backdrop, dialog.sheet, .modal-overlay').length`);
                for (let n = 0; n < dialogs; n++) {
                    const which = await tab.eval(OPEN_DIALOG(n));
                    if (!which) continue;
                    if (process.env.AUDIT_DEBUG) console.log(`  … ${width}px ${path} opened ${which}`);
                    await sleep(200);
                    faults.push(...await tab.eval(PROBE + `(document.querySelector('[data-audit-open]'), ${JSON.stringify('[' + which + ']')})`));
                    await tab.eval(CLOSE_DIALOGS);
                }
                for (const f of faults) console.log(`${width}px ${path}  ${f}`);
                total += faults.length;
            }
        }
    } finally {
        stop();
    }
    console.log(total ? `\n${total} fault(s)` : 'No faults.');
    process.exit(total ? 1 : 0);
}

main().catch(error => { console.error(error); process.exit(1); });
