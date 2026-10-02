#!/usr/bin/env node
// Drives the app's frame in a real browser: the rail, the command palette, the
// quick-add sheet and the phone tab bar. PHP tests cannot see any of this, and
// it is where a broken data-act or a renamed id fails silently.
//
//   node scripts/e2e-shell.mjs [--base http://localhost:8089]
//
// Signs in as the account scripts/seed-screenshots.php creates, adds a task, a
// quick note and an expense through the sheet, checks each arrived through the
// API, and deletes what it made. Exits 1 on the first failed check.

import { Cdp, Tab, accountPassword, launch, openPage, signIn, sleep } from './screenshots.mjs';

const BASE = (process.argv.includes('--base') ? process.argv[process.argv.indexOf('--base') + 1] : 'http://localhost:8089').replace(/\/$/, '');
const STAMP = 'e2e-' + Date.now().toString(36);

let checks = 0;
function check(condition, label, detail = '') {
    checks++;
    if (!condition) throw new Error(`ล้มเหลว: ${label}${detail ? ' — ' + detail : ''}`);
    console.log('  ok  ' + label);
}

/** Fills a field the way typing would: the value, then the events a page listens for. */
const fill = (tab, selector, value) => tab.eval(`(() => {
    const el = document.querySelector(${JSON.stringify(selector)});
    el.value = ${JSON.stringify(value)};
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
})()`);

const click = (tab, selector) => tab.eval(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`);

async function key(tab, text, modifiers = 0) {
    for (const type of ['keyDown', 'keyUp']) {
        await tab.send('Input.dispatchKeyEvent', { type, key: text, code: 'Key' + text.toUpperCase(), modifiers, windowsVirtualKeyCode: text.toUpperCase().charCodeAt(0) });
    }
}

const api = (tab, path, options = {}) => tab.eval(`(async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch(${JSON.stringify(BASE)} + ${JSON.stringify(path)}, {
        credentials: 'same-origin',
        ...${JSON.stringify(options)},
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    });
    return { status: res.status, body: await res.json().catch(() => null) };
})()`);

async function main() {
    const { cdp, stop } = await launch();
    const made = { tasks: [], items: [], finance: [] };
    let tab;

    try {
        tab = new Tab(cdp, await openPage(cdp));
        await signIn(tab, accountPassword());

        // ---------- desktop: rail and palette ----------
        console.log('Desktop');
        await tab.viewport({ width: 1366, height: 900, mobile: false });
        await tab.goto(BASE + '/tasks');
        await tab.settle();

        check(await tab.eval(`getComputedStyle(document.getElementById('appRail')).display !== 'none'`), 'the rail is shown');
        check(await tab.eval(`getComputedStyle(document.querySelector('.tabbar')).display === 'none'`), 'the tab bar is hidden');
        check(await tab.eval(`document.querySelector('.nav-item[aria-current="page"]')?.dataset.menuKey`) === 'tasks', 'the open menu entry is marked');
        check(await tab.eval(`document.querySelectorAll('.nav-item[aria-current="page"]').length`) === 1, 'only one entry is marked');
        check(await tab.eval(`getComputedStyle(document.querySelector('.nav-item[aria-current="page"]')).borderLeftColor`)
            === await tab.eval(`(() => { const probe = document.createElement('i'); probe.style.color = 'var(--day)'; document.body.appendChild(probe); const c = getComputedStyle(probe).color; probe.remove(); return c; })()`),
            'the marker is the weekday colour');

        await key(tab, 'k', 2); // Ctrl+K
        await sleep(300);
        check(await tab.eval(`!document.querySelector('.cmdk-backdrop').hidden`), 'Ctrl+K opens the palette');
        check(await tab.eval(`document.querySelector('.cmdk-item')?.textContent.includes('เพิ่มงาน')`), 'it offers "เพิ่มงาน" first');
        await key(tab, 'Enter');
        await sleep(400);
        check(await tab.eval(`document.getElementById('quickSheet').open`), 'choosing it opens the quick-add sheet');
        check(await tab.eval(`document.querySelector('.cmdk-backdrop').hidden`), 'and closes the palette');

        // ---------- the sheet: a task ----------
        console.log('Quick add');
        check(await tab.eval(`document.getElementById('qaTab-task').getAttribute('aria-selected')`) === 'true', 'it opens on the task tab');
        check(await tab.eval(`document.activeElement.id`) === 'qaTaskTitle', 'the title field has focus');

        await click(tab, '#quickSubmit');
        await sleep(300);
        check(await tab.eval(`!document.getElementById('quickError').hidden`), 'an empty title shows an error');
        check(await tab.eval(`document.getElementById('quickSheet').open`), 'and the sheet stays open');

        await fill(tab, '#qaTaskTitle', `ทดสอบ ${STAMP}`);
        await click(tab, '#quickSubmit');
        await sleep(900);
        check(await tab.eval(`!document.getElementById('quickSheet').open`), 'a title sends and closes the sheet');

        let list = await api(tab, '/api/tasks');
        // Tasks come back grouped by quadrant.
        const task = Object.values(list.body.quadrants || {}).flat().find(t => t.title === `ทดสอบ ${STAMP}`);
        check(!!task, 'the task reached the server');
        if (task) made.tasks.push(task.id);

        // ---------- a quick note ----------
        await tab.goto(BASE + '/bookmarks'); // a page that does not reload itself
        await tab.settle();
        await tab.eval(`window.openQuickAdd('note')`);
        await sleep(200);
        check(await tab.eval(`document.getElementById('qaPane-note').hidden`) === false, 'openQuickAdd("note") shows the note tab');
        await fill(tab, '#qaNoteContent', `จดทดสอบ ${STAMP}`);
        check((await tab.eval(`document.getElementById('qaNoteCount').textContent`)).startsWith(String(`จดทดสอบ ${STAMP}`.length)), 'the counter follows the text');
        await click(tab, '#quickSubmit');
        await sleep(800);
        list = await api(tab, '/api/quick-items');
        const note = (list.body.items || []).find(i => (i.content || '').includes(STAMP));
        check(!!note, 'the quick note reached the server');
        if (note) made.items.push(note.id);

        // ---------- an expense ----------
        await tab.eval(`window.openQuickAdd('money')`);
        await sleep(600);
        check(await tab.eval(`document.getElementById('quickSubmit').textContent`) === 'บันทึกรายจ่าย', 'the button names what it does');
        await click(tab, 'input[name="type"][value="income"]');
        check(await tab.eval(`document.getElementById('quickSubmit').textContent`) === 'บันทึกรายรับ', 'switching to income renames it');
        await click(tab, 'input[name="type"][value="expense"]');
        await fill(tab, '#qaAmount', 'abc');
        await click(tab, '#quickSubmit');
        await sleep(300);
        check(await tab.eval(`!document.getElementById('quickError').hidden`), 'a non-number amount is refused');
        await fill(tab, '#qaAmount', '123.45');
        await fill(tab, '#qaDescription', `ทดสอบ ${STAMP}`);
        await click(tab, '#quickSubmit');
        await sleep(900);
        list = await api(tab, '/api/finance');
        const entry = (list.body.transactions || []).find(t => (t.description || '').includes(STAMP));
        check(!!entry, 'the expense reached the server', JSON.stringify(Object.keys(list.body || {})));
        if (entry) {
            made.finance.push(entry.id);
            check(Number(entry.amount) === 123.45 && entry.type === 'expense', 'with the amount and type typed');
        }

        // ---------- phone ----------
        console.log('Phone');
        await tab.viewport({ width: 390, height: 844, mobile: true });
        await tab.goto(BASE + '/tasks');
        await tab.settle();
        check(await tab.eval(`getComputedStyle(document.getElementById('appRail')).display === 'none'`), 'the rail is hidden');
        check(await tab.eval(`getComputedStyle(document.querySelector('.tabbar')).display !== 'none'`), 'the tab bar is shown');
        check(await tab.eval(`document.querySelectorAll('.tabbar .tab').length`) === 5, 'it has five places');
        check(await tab.eval(`document.querySelector('.tabbar [aria-current="page"] span')?.textContent`) === 'งาน', 'the current page is marked in it');
        check(await tab.eval(`Math.min(...[...document.querySelectorAll('.tabbar .tab')].map(t => t.getBoundingClientRect().height))`) >= 44, 'every place is at least 44px tall');

        await click(tab, '.tabbar [data-act="openMenuSheet"]');
        await sleep(300);
        check(await tab.eval(`document.getElementById('menuSheet').open`), '"ทั้งหมด" opens the menu sheet');
        check(await tab.eval(`document.querySelectorAll('#menuSheet .sheet-link').length`) >= 19, 'it lists every menu');
        await tab.eval(`document.getElementById('menuSheet').close()`);

        await click(tab, '.tabbar [data-act="openQuickAdd"]');
        await sleep(300);
        check(await tab.eval(`document.getElementById('quickSheet').open`), 'the plus button opens quick add');
        check(await tab.eval(`document.documentElement.scrollWidth <= window.innerWidth`), 'nothing scrolls sideways');
    } finally {
        // Leave the account as it was found.
        if (tab) {
            await tab.goto(BASE + '/bookmarks').catch(() => {});
            for (const id of made.tasks) await api(tab, `/api/tasks/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.items) await api(tab, `/api/quick-items/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.finance) await api(tab, `/api/finance/${id}`, { method: 'DELETE' }).catch(() => {});
        }
        stop();
    }

    console.log(`\n${checks} checks passed`);
}

main().catch(error => { console.error('\n' + error.message); process.exit(1); });
