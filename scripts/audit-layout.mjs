#!/usr/bin/env node
// Looks for layout faults a screenshot hides: a box that spills past the
// page, text that runs out of its box, and controls drawn on top of each
// other. Every page of the app, at a phone, a tablet and a desktop width.
//
//   node scripts/audit-layout.mjs [--base http://localhost:8089] [--only finance]
//
// Signs in as the account scripts/seed-screenshots.php creates. Prints one
// line per fault and exits 1 when there is any.

import { Tab, accountPassword, launch, openPage, signIn, sleep } from './screenshots.mjs';

const arg = name => (process.argv.includes(name) ? process.argv[process.argv.indexOf(name) + 1] : null);
const BASE = (arg('--base') || 'http://localhost:8089').replace(/\/$/, '');
const ONLY = arg('--only');

const PAGES = [
    '/', '/review', '/tasks', '/projects', '/planner', '/habits', '/focus', '/skills', '/exercise',
    '/food-notes', '/finance', '/subscriptions', '/stocks', '/notes', '/quick-notes', '/bookmarks',
    '/files', '/transfer', '/file-tools', '/ai', '/calculator', '/settings',
];
const WIDTHS = [[390, 844, true], [820, 1000, false], [1366, 900, false]];

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
        for (let d = el.closest('details:not([open])'); d; d = null) {
            if (!el.closest('summary')) return false;
        }
        const cs = getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.05;
    };
    // Inside something that scrolls or clips on purpose: not a spill.
    const clipped = el => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const o = getComputedStyle(p);
            if (/(auto|scroll|hidden|clip)/.test(o.overflowX + o.overflow)) return p;
        }
        return null;
    };

    if (!root) return [];
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

    const box = root.getBoundingClientRect();
    const all = [...root.querySelectorAll('*')];
    for (const el of all) {
        if (!visible(el) || el.closest('dialog:not([open]), [hidden], .modal-backdrop:not(.active), .modal-overlay[hidden]')) continue;
        const r = el.getBoundingClientRect();
        if ((r.right > box.right + 1 || r.left < box.left - 1) && !clipped(el)) {
            faults.push('spills past the page: ' + name(el) + ' (' + Math.round(r.left) + '..' + Math.round(r.right) + ' vs ' + Math.round(box.left) + '..' + Math.round(box.right) + ')');
        }
        // A scrollbar nobody meant: a box set to scroll whose content is only
        // a few pixels too big, usually a negative margin under a tab.
        {
            const cs = getComputedStyle(el);
            const overY = el.scrollHeight - el.clientHeight, overX = el.scrollWidth - el.clientWidth;
            if (/(auto|scroll)/.test(cs.overflowY) && overY > 0 && overY <= 8) faults.push('stray vertical scrollbar: ' + name(el) + ' (' + overY + 'px)');
            if (/(auto|scroll)/.test(cs.overflowX) && overX > 0 && overX <= 4) faults.push('stray horizontal scrollbar: ' + name(el) + ' (' + overX + 'px)');
        }
        // Text running out of a control or a label.
        if (el.matches('button, .btn, label, .tag, .badge, .cat-chip, td, th, .form-control, .subnav-link, .flag') && el.scrollWidth > el.clientWidth + 2) {
            const cs = getComputedStyle(el);
            if (!/(auto|scroll)/.test(cs.overflowX) && cs.textOverflow !== 'ellipsis' && el.tagName !== 'SELECT' && el.tagName !== 'INPUT') {
                faults.push('text runs out of its box: ' + name(el) + ' (' + el.scrollWidth + ' > ' + el.clientWidth + ')');
            }
        }
    }

    // Controls on top of each other. Each box is cut down to what its
    // scrolling ancestors let show, so a field scrolled under a dialog's
    // footer does not count as covered by it.
    const shown = el => {
        let r = el.getBoundingClientRect();
        let box = { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const o = getComputedStyle(p);
            if (/(auto|scroll|hidden|clip)/.test(o.overflow + o.overflowX + o.overflowY)) {
                const c = p.getBoundingClientRect();
                box = { left: Math.max(box.left, c.left), top: Math.max(box.top, c.top), right: Math.min(box.right, c.right), bottom: Math.min(box.bottom, c.bottom) };
            }
        }
        return box;
    };
    const controls = [...(label ? root : document).querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')]
        .filter(el => visible(el) && !el.closest('dialog:not([open]), [hidden], .modal-backdrop:not(.active)'))
        .map(el => [el, shown(el)]);
    for (let i = 0; i < controls.length; i++) {
        for (let j = i + 1; j < controls.length; j++) {
            const [a, ra] = controls[i], [b, rb] = controls[j];
            if (a.contains(b) || b.contains(a)) continue;
            if (a.closest('label') && a.closest('label') === b.closest('label')) continue;
            const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
            const h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
            if (w > 3 && h > 3) {
                // A fixed bar over scrolled content is expected; only report when both scroll together.
                const fixed = el => { for (let p = el; p; p = p.parentElement) if (getComputedStyle(p).position === 'fixed') return true; return false; };
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
        await signIn(tab, accountPassword());
        for (const [width, height, mobile] of WIDTHS) {
            await tab.viewport({ width, height, mobile });
            for (const path of PAGES.filter(p => !ONLY || p.includes(ONLY))) {
                // A slow page gets one more try; a page that never loads is a
                // fault of its own rather than the end of the run.
                let loaded = false;
                for (let attempt = 0; attempt < 2 && !loaded; attempt++) {
                    try { await tab.goto(BASE + path); await tab.settle(); loaded = true; } catch { await sleep(1000); }
                }
                if (!loaded) { console.log(`${width}px ${path}  did not finish loading`); total++; continue; }
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
    console.log(total ? `\n${total} fault(s)` : 'No layout faults.');
    process.exit(total ? 1 : 0);
}

main().catch(error => { console.error(error); process.exit(1); });
