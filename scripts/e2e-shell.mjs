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
        // Enter needs its character, or a form never sees the keypress that submits it.
        const typed = text === 'Enter' && type === 'keyDown' ? { text: String.fromCharCode(13), unmodifiedText: String.fromCharCode(13) } : {};
        await tab.send('Input.dispatchKeyEvent', { type, key: text, code: text === 'Enter' ? 'Enter' : 'Key' + text.toUpperCase(), modifiers, windowsVirtualKeyCode: text === 'Enter' ? 13 : text.toUpperCase().charCodeAt(0), ...typed });
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
    const made = { tasks: [], items: [], finance: [], todos: [], events: [] };
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

        // ---------- the Today page ----------
        console.log('Today');
        await tab.goto(BASE + '/');
        await tab.settle();

        check(await tab.eval(`document.querySelectorAll('[data-section]').length`) >= 6, 'the sections are on the page');
        check(await tab.eval(`document.querySelector('.date-block .d').textContent.trim()`) === String(new Date().getDate()), 'the date block shows today\'s date');
        check(await tab.eval(`document.querySelector('[aria-busy="true"]') === null`), 'every skeleton has been replaced');
        check(!(await tab.eval(`document.getElementById('todayTally').textContent`)).includes('undefined'), 'the summary line has no gaps');
        check(await tab.eval(`!!document.getElementById('todayError')`) === false, 'there is no error banner');
        check(await tab.eval(`document.querySelectorAll('.task-tick').length`) >= 1, 'tasks are listed');

        // A task: tick it, check the server, put it back.
        const firstTask = await tab.eval(`document.querySelector('.task-tick').closest('[data-task]').dataset.task`);
        await tab.eval(`document.querySelector('.task-tick').click()`);
        await sleep(700);
        check(await tab.eval(`document.querySelector('[data-task="${firstTask}"]').classList.contains('done')`), 'a ticked task is struck through');
        let tasks = await api(tab, '/api/tasks');
        check(Object.values(tasks.body.quadrants).flat().find(t => String(t.id) === firstTask)?.status === 'done', 'and is done on the server');
        await api(tab, `/api/tasks/${firstTask}`, { method: 'PUT', body: JSON.stringify({ status: 'open' }) });

        // A habit: the same.
        await tab.goto(BASE + '/');
        await tab.settle();
        const habitId = await tab.eval(`document.querySelector('.habit-tick:not(:checked)')?.closest('[data-habit]').dataset.habit`);
        check(!!habitId, 'a habit is waiting to be ticked');
        await tab.eval(`document.querySelector('[data-habit="${habitId}"] .habit-tick').click()`);
        await sleep(1200);
        check(await tab.eval(`document.querySelector('[data-habit="${habitId}"] .habit-tick').checked`), 'a ticked habit stays ticked after the refresh');
        await api(tab, `/api/habits/${habitId}/toggle`, { method: 'POST', body: '{}' });

        // A task typed into the line under the list.
        await tab.goto(BASE + '/');
        await tab.settle();
        await fill(tab, '#todayAddTask', `บนหน้าวันนี้ ${STAMP}`);
        await tab.eval(`document.getElementById('todayAddTask').focus()`);
        await key(tab, 'Enter');
        await sleep(1200);
        check(await tab.eval(`document.body.textContent.includes('บนหน้าวันนี้ ${STAMP}')`), 'a task typed under the list appears in it');
        tasks = await api(tab, '/api/tasks');
        const typed = Object.values(tasks.body.quadrants).flat().find(t => t.title === `บนหน้าวันนี้ ${STAMP}`);
        check(!!typed, 'and reached the server, due today');
        if (typed) made.tasks.push(typed.id);

        // Settings: hide the notes section, see it go, bring it back.
        await tab.goto(BASE + '/settings');
        await tab.settle();
        await click(tab, '[data-tab="dashboard-config"]');
        check(await tab.eval(`document.querySelectorAll('#dashboardWidgetsList [data-widget-key]').length`) === 10, 'settings lists all ten sections');
        await tab.eval(`document.getElementById('chk_notes').checked = false`);
        await click(tab, '#btnSaveDashboardCustomization');
        await sleep(1800);
        await tab.goto(BASE + '/');
        await tab.settle();
        check(await tab.eval(`document.querySelector('[data-section="notes"]') === null`), 'a hidden section is gone from the page');

        await tab.goto(BASE + '/settings');
        await tab.settle();
        await click(tab, '[data-tab="dashboard-config"]');
        await tab.eval(`document.getElementById('chk_notes').checked = true`);
        await click(tab, '#btnSaveDashboardCustomization');
        await sleep(1800);
        await tab.goto(BASE + '/');
        await tab.settle();
        check(await tab.eval(`document.querySelector('[data-section="notes"]') !== null`), 'and comes back when switched on');

        // ---------- phase 5A: the everyday pages ----------
        console.log('Tasks');
        await tab.goto(BASE + '/tasks');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('.quadrant').length`) === 4, 'four boxes');
        check(await tab.eval(`document.querySelectorAll('.task-item').length`) >= 1, 'tasks are listed');
        check(!(await tab.eval(`document.getElementById('tasksTally').textContent`)).includes('กำลัง'), 'the summary line is filled');

        await fill(tab, '.quick-add[data-quadrant="2"]', `ช่อง ${STAMP}`);
        await tab.eval(`document.querySelector('.quick-add[data-quadrant="2"]').focus()`);
        await key(tab, 'Enter');
        await sleep(1200);
        check(await tab.eval(`document.getElementById('q2-list').textContent.includes('ช่อง ${STAMP}')`), 'a task typed under a box lands in that box');
        const boxed = Object.values((await api(tab, '/api/tasks')).body.quadrants).flat().find(t => t.title === `ช่อง ${STAMP}`);
        check(boxed?.quadrant === 2, 'and is saved with that box\'s priority');
        if (boxed) made.tasks.push(boxed.id);

        await tab.eval(`[...document.querySelectorAll('.task-title')].find(b => b.textContent === 'ช่อง ${STAMP}').click()`);
        await sleep(400);
        check(await tab.eval(`document.getElementById('editTaskModal').classList.contains('active')`), 'clicking a title opens the edit dialog');
        const editValue = await tab.eval(`document.getElementById('editTaskTitle').value`);
        check(editValue === `ช่อง ${STAMP}`, 'filled with that task', JSON.stringify(editValue));
        check(await tab.eval(`!!document.querySelector('label[for="editTaskTitle"]')`), 'its fields have labels');
        await tab.eval(`document.querySelector('#editTaskModal [data-close-modal]').click()`);

        console.log('Planner');
        await tab.goto(BASE + '/planner');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('.calendar-cell').length`) >= 35, 'the month grid is drawn');
        check(await tab.eval(`document.querySelectorAll('.calendar-cell.today').length`) <= 1, 'at most one cell is today');
        check(await tab.eval(`document.querySelector('.calendar-cell').tagName`) === 'BUTTON', 'each day is a button');
        await tab.eval(`document.querySelectorAll('.calendar-cell:not(.other-month)')[14].click()`);
        await sleep(300);
        check(await tab.eval(`document.getElementById('dayNum').textContent`) === '15', 'picking a day turns the sheet to it');

        await fill(tab, '#todoInput', `ทำ ${STAMP}`);
        await tab.eval(`document.getElementById('todoInput').focus()`);
        await key(tab, 'Enter');
        await sleep(1000);
        check(await tab.eval(`document.getElementById('dayTodos').textContent.includes('${STAMP}')`), 'a to-do typed on the sheet is listed');
        const stamp15 = await tab.eval(`document.querySelectorAll('.calendar-cell:not(.other-month)')[14].getAttribute('aria-label')`);
        const todoDate = new Date(); todoDate.setDate(15);
        const iso15 = todoDate.getFullYear() + '-' + String(todoDate.getMonth() + 1).padStart(2, '0') + '-15';
        const todo = ((await api(tab, `/api/planner/todos?date=${iso15}`)).body.todos || []).find(t => t.title === `ทำ ${STAMP}`);
        check(!!todo, 'and saved on that day', stamp15);
        if (todo) made.todos.push(todo.id);

        await click(tab, '[data-act="openAddEvent"]');
        await sleep(300);
        check(await tab.eval(`document.getElementById('eventModal').classList.contains('active')`), '"เพิ่มกิจกรรม" opens the dialog');
        await click(tab, '.color-dot[aria-label="เขียว"]');
        check(await tab.eval(`document.getElementById('eventColor').value`) === '#10b981', 'a colour can be picked');
        check(await tab.eval(`document.querySelector('.color-dot[aria-label="เขียว"]').getAttribute('aria-checked')`) === 'true', 'and says it is chosen');
        await click(tab, '#eventAllDay');
        check(await tab.eval(`document.getElementById('dateOnlyFields').hidden === false && document.getElementById('dateTimeFields').hidden === true`), '"all day" swaps the time fields for a date');
        await fill(tab, '#eventTitle', `นัด ${STAMP}`);
        await click(tab, '[data-act="saveEvent"]');
        await sleep(1200);
        check(await tab.eval(`document.getElementById('dayEvents').textContent.includes('${STAMP}')`), 'a saved event shows on the sheet');
        const ev = ((await api(tab, `/api/planner/events?year=${todoDate.getFullYear()}&month=${todoDate.getMonth() + 1}`)).body.events || []).find(e => e.title === `นัด ${STAMP}`);
        check(!!ev && ev.color === '#10b981', 'with the colour that was picked');
        if (ev) made.events.push(ev.id);

        console.log('Habits');
        await tab.goto(BASE + '/habits');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('.habit-table tbody tr').length`) >= 1, 'habits are listed as rows');
        check(await tab.eval(`document.querySelectorAll('.habit-table thead th').length`) === 8, 'with a column for each day');
        check(await tab.eval(`document.querySelectorAll('.habit-cell.is-today').length`) >= 1, 'today\'s column is marked');
        check(await tab.eval(`[...document.querySelectorAll('.habit-cell.future input')].every(i => i.disabled)`), 'days to come cannot be ticked');

        const habitBox = await tab.eval(`(() => { const i = document.querySelector('.habit-cell.is-today input'); return { id: i.dataset.habit, was: i.checked }; })()`);
        await tab.eval(`document.querySelector('.habit-cell.is-today input').click()`);
        await sleep(1000);
        const habitsNow = (await api(tab, '/api/habits')).body.habits.find(h => String(h.id) === habitBox.id);
        check(Boolean(Number(habitsNow.completed_today)) === !habitBox.was, 'ticking today\'s box is saved');
        await tab.eval(`document.querySelector('.habit-cell.is-today input').click()`);
        await sleep(800);

        const label = await tab.eval(`document.getElementById('weekLabel').textContent`);
        await click(tab, '#weekPrev');
        await sleep(700);
        check(await tab.eval(`document.getElementById('weekLabel').textContent`) !== label, 'the previous week has its own dates');
        check(await tab.eval(`document.getElementById('weekToday').hidden`) === false, 'and a way back');
        await click(tab, '#weekToday');
        await sleep(700);
        check(await tab.eval(`document.getElementById('weekToday').hidden`) === true, 'back on this week the button goes away');

        console.log('Focus');
        await tab.goto(BASE + '/focus');
        await tab.eval(`localStorage.removeItem('dayflow.focus')`);
        await tab.goto(BASE + '/focus');
        await tab.settle();
        check(await tab.eval(`document.getElementById('timerDisplay').textContent`) === '25:00', 'the timer waits at 25:00');
        await click(tab, '#btnStartStop');
        await sleep(2400);
        const running = await tab.eval(`document.getElementById('timerDisplay').textContent`);
        check(running !== '25:00' && running.startsWith('24:'), 'it counts down');
        check((await tab.eval(`document.title`)).includes(running.slice(0, 2)), 'the countdown is in the tab title');
        check(await tab.eval(`document.getElementById('btnStartStop').textContent`) === 'หยุดชั่วคราว', 'the one button now says how to stop');

        await tab.goto(BASE + '/focus');
        await tab.settle();
        check(await tab.eval(`document.getElementById('btnStartStop').textContent`) === 'หยุดชั่วคราว', 'a reload does not lose a running timer');
        check(await tab.eval(`document.getElementById('timerDisplay').textContent`).then(t => t.startsWith('24:')), 'and it kept the time');
        await click(tab, '#btnStartStop');
        check(await tab.eval(`document.getElementById('btnStartStop').textContent`) === 'จับเวลาต่อ', 'stopping offers to carry on');
        check(await tab.eval(`localStorage.getItem('dayflow.focus')`) === null, 'a stopped timer is not remembered');
        await click(tab, '#btnResetTimer');
        check(await tab.eval(`document.getElementById('timerDisplay').textContent`) === '25:00', 'start over returns to 25:00');

        console.log('Quick notes');
        await tab.goto(BASE + '/quick-notes');
        await tab.settle();
        await fill(tab, '#quickInput', 'abc');
        check(await tab.eval(`document.getElementById('quickCount').textContent`) === '3 / 500', 'the counter follows the text');
        await fill(tab, '#quickInput', `ไม่ลืม ${STAMP}`);
        await tab.eval(`document.getElementById('quickInput').focus()`);
        await key(tab, 'Enter');
        await sleep(1000);
        check(await tab.eval(`document.getElementById('quickList').textContent.includes('${STAMP}')`), 'a typed line joins the list');
        const quick = ((await api(tab, '/api/quick-items')).body.items || []).find(i => (i.content || '').includes(STAMP));
        check(!!quick, 'and is saved');
        if (quick) made.items.push(quick.id);
        await tab.eval(`document.querySelector('[data-quick-toggle="${quick.id}"]').click()`);
        await sleep(900);
        check(await tab.eval(`document.querySelector('[data-quick-toggle="${quick.id}"]').checked`), 'a ticked line stays ticked after the list redraws');
        check(await tab.eval(`document.getElementById('quickList').textContent.includes('เสร็จแล้ว')`), 'under a "done" heading');

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
            for (const id of made.todos) await api(tab, `/api/planner/todos/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.events) await api(tab, `/api/planner/events/${id}`, { method: 'DELETE' }).catch(() => {});
        }
        stop();
    }

    console.log(`\n${checks} checks passed`);
}

main().catch(error => { console.error('\n' + error.message); process.exit(1); });
