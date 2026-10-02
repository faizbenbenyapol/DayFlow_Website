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
    const made = { tasks: [], items: [], finance: [], todos: [], events: [], subs: [], stocks: [], bookmarks: [], foods: [], workouts: [], skills: [], notes: [], files: [], appShares: [] };
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

        // ---------- the money pages ----------
        console.log('Finance');
        await tab.viewport({ width: 1366, height: 900, mobile: false });
        await tab.goto(BASE + '/finance');
        await tab.settle();
        const monthBefore = await tab.eval(`document.getElementById('monthLabel').textContent`);
        await click(tab, '#monthPrev');
        await sleep(500);
        check(await tab.eval(`document.getElementById('monthLabel').textContent`) !== monthBefore, 'the month arrow moves the month');
        check(await tab.eval(`!document.getElementById('monthNow').hidden`), 'and offers a way back to this month');
        await click(tab, '#monthNow');
        await sleep(500);
        check(await tab.eval(`document.getElementById('monthLabel').textContent`) === monthBefore, '"กลับมาเดือนนี้" returns');

        await click(tab, 'input[name="qaType"][value="income"]');
        await fill(tab, '#qaAmount', '123.45');
        await fill(tab, '#qaDesc', `รับทดสอบ ${STAMP}`);
        await click(tab, '#qaSubmit');
        await sleep(900);
        check(await tab.eval(`document.getElementById('transactionList').textContent.includes(${JSON.stringify(`รับทดสอบ ${STAMP}`)})`), 'an entry added on the line appears in the list');
        const monthList = await api(tab, '/api/finance?month=' + new Date().toISOString().slice(0, 7));
        const money = (monthList.body.transactions || []).find(t => (t.description || '').includes(STAMP));
        check(!!money && money.type === 'income', 'it reached the server as income');
        if (money) made.finance.push(money.id);

        await click(tab, '#typeFilter [data-type="expense"]');
        await sleep(500);
        check(await tab.eval(`document.querySelector('#typeFilter [data-type="expense"]').getAttribute('aria-pressed')`) === 'true', 'the expense filter is pressed');
        check(await tab.eval(`!document.getElementById('transactionList').textContent.includes(${JSON.stringify(`รับทดสอบ ${STAMP}`)})`), 'and the income entry is filtered out');

        console.log('Subscriptions');
        await tab.goto(BASE + '/subscriptions');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('.sub-band').length`) >= 1, 'the charges are grouped in bands');
        await click(tab, '[data-act="openAddSub"]');
        await sleep(300);
        check(await tab.eval(`document.getElementById('subModal').classList.contains('active')`), 'the add button opens the form');
        await click(tab, '[data-act="saveSub"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('subError').hidden`), 'saving it empty shows an error in the form');
        await fill(tab, '#subName', `รายการ ${STAMP}`);
        await fill(tab, '#subAmount', '99');
        await click(tab, '[data-act="saveSub"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('subGrid').textContent.includes(${JSON.stringify(STAMP)})`), 'a saved charge is listed');
        const subs = await api(tab, '/api/subscriptions');
        const sub = (subs.body.subscriptions || []).find(s => (s.name || '').includes(STAMP));
        check(!!sub, 'it reached the server');
        if (sub) made.subs.push(sub.id);

        console.log('Stocks');
        await tab.goto(BASE + '/stocks');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('#stkUnifiedList tr').length`) >= 1, 'the holdings are listed');
        check(await tab.eval(`document.getElementById('stkTable').dataset.view`) === 'portfolio', 'the portfolio view shows its extra columns');
        await click(tab, '[data-main-tab="all"]');
        await sleep(200);
        check(await tab.eval(`document.getElementById('stkTable').dataset.view`) === 'list', 'another group hides them');
        check(await tab.eval(`document.querySelector('[data-main-tab="all"]').getAttribute('aria-selected')`) === 'true', 'and marks its tab selected');
        await click(tab, '[data-stk-tab="capital"]');
        await sleep(200);
        check(await tab.eval(`document.querySelector('[data-stk-panel="capital"]').hidden`) === false, 'a sub-tab opens its panel');
        check(await tab.eval(`document.querySelector('[data-stk-panel="transactions"]').hidden`) === true, 'and closes the other');
        await click(tab, '[data-stk-tab="chart"]');
        await sleep(600);
        check(await tab.eval(`document.getElementById('stkValueChart').getBoundingClientRect().height`) > 100, 'the chart has a size once its tab is open');
        await click(tab, '.metric-help');
        await sleep(300);
        check(await tab.eval(`document.getElementById('metricModal').classList.contains('active')`), 'a ratio heading opens its explanation');
        await tab.eval(`closeModal('metricModal')`);

        await click(tab, '[data-act="openAddStock"]');
        await sleep(300);
        await click(tab, '[data-act="saveStock"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('stkError').hidden`), 'an empty trade shows an error in the form');
        await fill(tab, '#stkTicker', 'E2ETEST');
        await fill(tab, '#stkQty', '2');
        await fill(tab, '#stkPrice', '10');
        await click(tab, '[data-act="saveStock"]');
        await sleep(900);
        const trades = await api(tab, '/api/stocks?ticker=E2ETEST');
        const trade = (trades.body.transactions || [])[0];
        check(!!trade, 'a saved trade reached the server');
        if (trade) made.stocks.push(trade.id);
        await tab.eval(`document.querySelector('[data-stk-tab="transactions"]').click()`);
        check(await tab.eval(`document.getElementById('stkTxnList').textContent.includes('E2ETEST')`), 'and appears in the trades');

        // ---------- the notes pages ----------
        console.log('Review');
        await tab.goto(BASE + '/review');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('#rvTasksBody .ledger tr').length`) >= 4, 'the task ledger is drawn');
        const weekRange = await tab.eval(`document.getElementById('reviewRange').textContent`);
        await click(tab, '#reviewPeriods [data-period="month"]');
        await sleep(700);
        check(await tab.eval(`document.getElementById('reviewRange').textContent`) !== weekRange, 'the month button changes the period');
        check(await tab.eval(`document.querySelector('#reviewPeriods [data-period="month"]').getAttribute('aria-pressed')`) === 'true', 'and is marked pressed');
        check(await tab.eval(`document.querySelectorAll('.rv-days li').length`) >= 28, 'the focus chart has a column for every day');

        console.log('Bookmarks');
        await tab.goto(BASE + '/bookmarks');
        await tab.settle();
        await click(tab, '[data-act="openBookmark"]');
        await sleep(300);
        await click(tab, '#bookmarkForm [type="submit"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('bookmarkError').hidden`), 'an empty link shows an error in the form');
        await fill(tab, '#bookmarkName', `ลิงก์ ${STAMP}`);
        await fill(tab, '#bookmarkUrl', 'not a url');
        await click(tab, '#bookmarkForm [type="submit"]');
        await sleep(200);
        check(await tab.eval(`document.getElementById('bookmarkError').textContent.includes('http')`), 'a bad address is explained');
        await fill(tab, '#bookmarkUrl', 'https://example.com/e2e');
        await fill(tab, '#bookmarkCategory', 'e2e');
        await click(tab, '#bookmarkForm [type="submit"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('bookmarksGrid').textContent.includes(${JSON.stringify(STAMP)})`), 'a saved link is listed');
        const marks = await api(tab, '/api/bookmarks');
        const mark = (marks.body.bookmarks || []).find(b => b.title.includes(STAMP));
        check(!!mark, 'it reached the server');
        if (mark) made.bookmarks.push(mark.id);

        console.log('Food notes');
        await tab.goto(BASE + '/food-notes');
        await tab.settle();
        await click(tab, '[data-act="openAdd"]');
        await sleep(300);
        await click(tab, '[data-act="saveItem"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('fnError').hidden`), 'an empty item shows an error in the form');
        await fill(tab, '#fnName', `อาหาร ${STAMP}`);
        await fill(tab, '#fnReaction', 'caution');
        await click(tab, '[data-act="saveItem"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('fnList').textContent.includes(${JSON.stringify(STAMP)})`), 'a saved item is listed');
        const foods = await api(tab, '/api/food-notes');
        const food = (foods.body.items || []).find(i => i.name.includes(STAMP));
        check(!!food && food.reaction === 'caution', 'it reached the server with its reaction');
        if (food) made.foods.push(food.id);
        await click(tab, '#fnTypeFilter [data-type="drink"]');
        await sleep(600);
        check(await tab.eval(`!document.getElementById('fnList').textContent.includes(${JSON.stringify(STAMP)})`), 'the drink filter hides a food');
        check(await tab.eval(`document.querySelector('#fnTypeFilter [data-type="drink"]').getAttribute('aria-pressed')`) === 'true', 'and is marked pressed');

        console.log('Exercise');
        await tab.goto(BASE + '/exercise');
        await tab.settle();
        const exLabel = await tab.eval(`document.getElementById('monthLabel').textContent`);
        await click(tab, '[data-act="moveMonth"][data-args="[-1]"]');
        await sleep(600);
        check(await tab.eval(`document.getElementById('monthLabel').textContent`) !== exLabel, 'the month arrow moves the month');
        await click(tab, '[data-act="moveMonth"][data-args="[1]"]');
        await sleep(600);
        await click(tab, '[data-act="openAddWorkout"]');
        await sleep(300);
        await click(tab, '[data-act="saveWorkout"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('workoutError').hidden`), 'an empty workout shows an error in the form');
        await fill(tab, '#workoutType', `ทดสอบ ${STAMP}`);
        await fill(tab, '#workoutDuration', '20');
        await click(tab, '[data-act="saveWorkout"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('workoutList').textContent.includes(${JSON.stringify(STAMP)})`), 'a saved workout is listed');
        const works = await api(tab, '/api/exercise?limit=100');
        const work = (works.body.workouts || []).find(w => w.type.includes(STAMP));
        check(!!work, 'it reached the server');
        if (work) made.workouts.push(work.id);

        console.log('Skills');
        await tab.goto(BASE + '/skills');
        await tab.settle();
        check(await tab.eval(`getComputedStyle(document.getElementById('timerDisplay')).fontFamily.includes('Mono')`), 'the clock is set in the mono face');
        await click(tab, '#btnTimerToggle');
        await sleep(300);
        check(await tab.eval(`!document.getElementById('timerError').hidden`), 'starting without a skill explains what is missing');
        await click(tab, '[data-act="openSkillModal"]');
        await sleep(300);
        await fill(tab, '#skillName', `ทักษะ ${STAMP}`);
        await fill(tab, '#skillTargetHours', '50');
        await click(tab, '#skillForm [type="submit"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('skillsListContainer').textContent.includes(${JSON.stringify(STAMP)})`), 'a saved skill is listed with its goal');
        const skillList = await api(tab, '/api/skills');
        const skill = (skillList.body || []).find(s => s.name.includes(STAMP));
        check(!!skill, 'it reached the server');
        if (skill) {
            made.skills.push(skill.id);
            await fill(tab, '#timerSkillSelect', skill.id);
            await click(tab, '#btnTimerToggle');
            await sleep(1500);
            check(await tab.eval(`document.getElementById('btnTimerToggle').textContent.includes('หยุด')`), 'the button turns into a stop button');
            check(await tab.eval(`document.getElementById('timerDisplay').textContent !== '00:00:00'`), 'the clock counts');
            check(await tab.eval(`document.getElementById('timerSkillSelect').disabled`), 'the skill cannot be changed while it runs');
            await tab.goto(BASE + '/skills');
            await tab.settle();
            check(await tab.eval(`document.getElementById('timerDisplay').classList.contains('running')`), 'a reload finds the timer still running');
            await api(tab, '/api/skills/timer/stop', { method: 'POST' });
        }

        console.log('Notes');
        await tab.goto(BASE + '/notes');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('#notesGrid .note-row').length`) >= 1, 'the notes are listed');
        await click(tab, '[data-act="openCreateNote"][data-args="[true]"]');
        await sleep(300);
        check(await tab.eval(`!document.getElementById('createNotePwGroup').hidden`), 'an encrypted note asks for a password');
        await click(tab, '#createNoteForm [type="submit"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('createNoteError').hidden`), 'without one it shows an error in the form');
        await tab.eval(`closeModal('createNoteModal')`);
        await click(tab, '[data-act="openCreateNote"][data-args="[false]"]');
        await sleep(300);
        await fill(tab, '#createNoteTitle', `โน้ต ${STAMP}`);
        await click(tab, '#createNoteForm [type="submit"]');
        await sleep(1500);
        const editorPath = await tab.eval('location.pathname');
        check(/\/notes\/\d+$/.test(editorPath), 'a new note opens its editor');
        const newNoteId = Number(editorPath.split('/').pop());
        made.notes.push(newNoteId);
        await tab.settle();

        await click(tab, '.note-add button:nth-of-type(1)');
        await sleep(700);
        check(await tab.eval(`document.querySelectorAll('.block').length`) === 1, 'adding a text block shows it');
        check(await tab.eval(`document.activeElement.classList.contains('block-textarea')`), 'and puts the cursor in it');
        await fill(tab, '.block-textarea', `ข้อความ ${STAMP}`);
        await sleep(300);
        check(await tab.eval(`document.getElementById('saveStatus').textContent.includes('กำลังบันทึก')`), 'typing shows that a save is waiting');
        await sleep(1700);
        const saved = await api(tab, `/api/notes/${newNoteId}/blocks`);
        check((saved.body.blocks || []).some(b => (b.content || '').includes(STAMP)), 'the text was saved on its own');

        await click(tab, '.note-add button:nth-of-type(3)');
        await sleep(700);
        check(await tab.eval(`document.querySelectorAll('.block').length`) === 2, 'a checklist block can be added');
        await tab.eval(`document.querySelector('.block:last-child .checklist-add').click()`);
        await sleep(200);
        check(await tab.eval(`document.querySelectorAll('.block:last-child .checklist-row').length`) === 1, 'it takes a first item');
        await fill(tab, '.block:last-child .checklist-input', 'รายการแรก');
        await click(tab, '.block:last-child .checklist-box');
        await sleep(1700);
        const withList = await api(tab, `/api/notes/${newNoteId}/blocks`);
        const listBlock = (withList.body.blocks || []).find(b => b.type === 'checklist');
        check(!!listBlock && JSON.parse(listBlock.content)[0].checked === true, 'a ticked item is saved as ticked');

        await tab.eval(`document.getElementById('tagInput').focus()`);
        await fill(tab, '#tagInput', 'e2etag');
        await key(tab, 'Enter');
        await sleep(900);
        check(await tab.eval(`document.getElementById('noteTagList').textContent.includes('e2etag')`), 'a tag typed and entered becomes a chip');
        const tagged = await api(tab, '/api/notes');
        check((tagged.body.notes || []).some(n => n.id === newNoteId && (n.tags_list || '').includes('e2etag')), 'and is saved with the note');

        await click(tab, '.block:last-child [aria-label="ย้ายบล็อกขึ้น"]');
        await sleep(900);
        const moved = await api(tab, `/api/notes/${newNoteId}/blocks`);
        check((moved.body.blocks || [])[0].type === 'checklist', 'the move-up button reorders the blocks on the server');

        // ---------- the tools ----------
        console.log('Calculator');
        await tab.goto(BASE + '/calculator');
        await tab.settle();
        await fill(tab, '#calcDisplay', '2+3*4');
        await tab.eval(`document.querySelector('[data-action="equals"]').click()`);
        await sleep(200);
        check(await tab.eval(`document.getElementById('calcDisplay').value`) === '14', 'the pocket calculator works out 2+3*4 without eval');
        await fill(tab, '#calcDisplay', 'sqrt(16)+3!');
        await tab.eval(`document.querySelector('[data-action="equals"]').click()`);
        await sleep(200);
        check(await tab.eval(`document.getElementById('calcDisplay').value`) === '10', 'functions and factorials work');
        await fill(tab, '#calcDisplay', '2+*');
        await tab.eval(`document.querySelector('[data-action="equals"]').click()`);
        await sleep(200);
        check(await tab.eval(`document.getElementById('calcSubDisplay').textContent.includes('ผิดพลาด')`), 'a broken expression says so');
        check(await tab.eval(`document.querySelectorAll('.calc-history-item').length`) >= 2, 'worked-out sums go to the history');
        await click(tab, '#calcTabs [data-tab="percent"]');
        await sleep(200);
        check(await tab.eval(`document.querySelector('#calcTabs [data-tab="percent"]').getAttribute('aria-selected')`) === 'true', 'a tab marks itself selected');
        check(await tab.eval(`document.querySelector('.calc-panel.active').dataset.panel`) === 'percent', 'and shows its panel');
        await tab.eval(`document.querySelector('[data-calc="p1"]').value = '20'; document.querySelectorAll('[data-calc="p1"]')[1].value = '1500'; document.querySelectorAll('[data-calc="p1"]')[1].dispatchEvent(new Event('input', { bubbles: true }))`);
        await sleep(200);
        check(await tab.eval(`document.querySelector('[data-result="p1"]').textContent.includes('300')`), 'a percentage result is drawn as the figures are typed');
        check(await tab.eval(`(() => { const l = document.querySelector('.calc-panel.active .form-group label'); return !!l.htmlFor && document.getElementById(l.htmlFor) !== null; })()`), 'labels are tied to their fields');
        await fill(tab, '#calcSearch', 'BMI');
        await sleep(300);
        check(await tab.eval(`document.querySelectorAll('.calc-tool:not(.calc-hidden)').length`) >= 1, 'searching finds a tool on another tab');
        await fill(tab, '#calcSearch', '');

        console.log('AI assistant');
        await tab.goto(BASE + '/ai');
        await tab.settle();
        await click(tab, '.ai-tab[data-tab="keys"]');
        await sleep(500);
        check(await tab.eval(`document.getElementById('ai-pane-keys').hidden`) === false, 'the keys tab opens its pane');
        check(await tab.eval(`document.getElementById('ai-pane-generate').hidden`) === true, 'and hides the other');
        check(await tab.eval(`document.querySelectorAll('#aiKeysList .ai-key-row').length`) >= 1, 'a row for each service is listed');
        check(await tab.eval(`[...document.querySelectorAll('#aiKeysList input')].every(i => i.getAttribute('aria-label'))`), 'each key field has a label');
        await click(tab, '.ai-tab[data-tab="generate"]');
        await sleep(200);

        console.log('Transfer');
        await tab.goto(BASE + '/transfer');
        await tab.settle();
        check(await tab.eval(`document.querySelector('[data-panel="send"]').hidden`) === false, 'it opens on the send tab');
        check(await tab.eval(`document.getElementById('sendDrop').textContent.includes('MB')`), 'the real size limit is shown');
        await click(tab, '#tfTabs [data-tab="receive"]');
        await sleep(200);
        check(await tab.eval(`document.getElementById('btnReceive').disabled`), 'the receive button waits for six digits');
        await fill(tab, '#receiveCode', '12ab34');
        check(await tab.eval(`document.getElementById('receiveCode').value`) === '1234', 'only digits are accepted');
        await fill(tab, '#receiveCode', '000000');
        check(await tab.eval(`!document.getElementById('btnReceive').disabled`), 'six digits enable it');
        await click(tab, '#btnReceive');
        await sleep(900);
        check(await tab.eval(`!document.getElementById('receiveError').hidden`), 'an unknown code is explained in the form');
        await click(tab, '#tfTabs [data-tab="history"]');
        await sleep(700);
        check(await tab.eval(`document.getElementById('historyList').getAttribute('aria-busy')`) === null, 'the history loads');

        console.log('Files');
        await tab.goto(BASE + '/files');
        await tab.settle();
        check(await tab.eval(`document.querySelectorAll('#filesGrid .file-item').length`) >= 3, 'the folder lists its files and folders');
        check(await tab.eval(`document.querySelector('.file-item:first-child').dataset.fileType`) === 'folder', 'folders come first');
        check(await tab.eval(`[...document.querySelectorAll('.file-item')].every(i => i.querySelector('.file-name') && i.querySelector('.file-checkbox').getAttribute('aria-label'))`), 'every row has a name button and a labelled checkbox');
        await click(tab, '#btnCreateFolder');
        await sleep(300);
        await click(tab, '#nameForm [type="submit"]');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('nameError').hidden`), 'an empty folder name shows an error in the form');
        await fill(tab, '#nameInput', `โฟลเดอร์ ${STAMP}`);
        await click(tab, '#nameForm [type="submit"]');
        await sleep(900);
        check(await tab.eval(`document.getElementById('filesGrid').textContent.includes(${JSON.stringify(STAMP)})`), 'a new folder is listed');
        const folderList = await api(tab, '/api/files');
        const made1 = (folderList.body.files || []).find(f => f.name.includes(STAMP));
        check(!!made1, 'it reached the server');
        if (made1) made.files.push(made1.id);
        await click(tab, `.file-item[data-file-id="${made1.id}"] .file-more`);
        await sleep(200);
        check(await tab.eval(`!document.getElementById('ctxMenu').hidden`), 'the more button opens the menu');
        check(await tab.eval(`document.activeElement.classList.contains('ctx-item')`), 'with the first item focused');
        await key(tab, 'Escape');
        await sleep(200);
        check(await tab.eval(`document.getElementById('ctxMenu').hidden`), 'Escape closes it');
        await click(tab, `.file-item[data-file-id="${made1.id}"] .file-checkbox`);
        check(await tab.eval(`!document.getElementById('batchToolbar').hidden`), 'ticking a row shows the selection bar');
        await click(tab, '#btnBatchClear');
        await click(tab, '#btnGridView');
        check(await tab.eval(`!document.getElementById('filesGrid').classList.contains('list-view')`), 'the tile view can be chosen');
        await click(tab, '#btnListView');

        console.log('Settings');
        await tab.viewport({ width: 1366, height: 900, mobile: false });
        await tab.eval(`localStorage.removeItem('settings_last_tab')`);
        await tab.goto(BASE + '/settings');
        await tab.settle();
        await sleep(300);
        check(await tab.eval(`document.querySelector('.settings-tab[aria-selected="true"]').dataset.tab`) === 'profile', 'it opens on the profile topic');
        check(await tab.eval(`document.querySelectorAll('.settings-pane:not([hidden])').length`) === 1, 'only one pane is shown');
        check(await tab.eval(`document.querySelectorAll('.settings-tab').length`) === 14, 'every topic is listed');
        await click(tab, '.settings-tab[data-tab="categories"]');
        await sleep(600);
        check(await tab.eval(`document.getElementById('tab-categories').hidden`) === false, 'a topic opens its pane');
        check(await tab.eval(`document.querySelectorAll('#finCatListExpense .cat-item, #finCatListIncome .cat-item').length`) >= 1, 'and loads its list the first time');
        await tab.eval(`document.querySelector('.settings-tab[data-tab="categories"]').focus()`);
        await key(tab, 'ArrowDown');
        await sleep(300);
        check(await tab.eval(`document.querySelector('.settings-tab[aria-selected="true"]').dataset.tab`) === 'stock-api', 'the arrow keys move between topics');
        check(await tab.eval(`document.querySelectorAll('#stockKeysList .stock-key-row').length`) >= 1, 'the keys list loads when that topic opens');

        await click(tab, '.settings-tab[data-tab="password"]');
        await sleep(300);
        await fill(tab, '#pwNew', 'abcd1234XY!z');
        check(await tab.eval(`document.getElementById('pwStrength').dataset.level`) === 'good', 'a strong password reads as good');
        await fill(tab, '#pwConfirm', 'different');
        check(await tab.eval(`document.getElementById('pwMatchHint').classList.contains('bad')`), 'a mismatch is called out');
        check(await tab.eval(`getComputedStyle(document.getElementById('tfaOn')).display`) === 'none', 'the unused 2FA states are hidden');
        await fill(tab, '#pwNew', '');
        await fill(tab, '#pwConfirm', '');

        await click(tab, '.settings-tab[data-tab="menus"]');
        await sleep(300);
        check(await tab.eval(`document.querySelectorAll('.menu-group').length`) >= 5, 'menus are grouped like the rail');
        check(await tab.eval(`document.getElementById('mobileTab1').options.length`) >= 10, 'the bottom bar choices list every shown menu');
        await fill(tab, '#mobileTab1', 'notes');
        await fill(tab, '#mobileTab2', 'notes');
        check(await tab.eval(`document.getElementById('mobileTab1').value !== document.getElementById('mobileTab2').value`), 'the same menu cannot take both places');
        await fill(tab, '#mobileTab1', 'finance');
        await fill(tab, '#mobileTab2', 'notes');
        await click(tab, '#btnSaveMenus');
        await sleep(2000);
        await tab.settle();
        await tab.viewport({ width: 390, height: 844, mobile: true });
        await tab.goto(BASE + '/');
        await tab.settle();
        check(await tab.eval(`[...document.querySelectorAll('.tabbar .tab')].map(t => t.getAttribute('href') || '').join(' ').includes('/notes')`), 'a saved bottom bar choice shows on the phone');
        await tab.viewport({ width: 1366, height: 900, mobile: false });
        await tab.goto(BASE + '/settings');
        await tab.settle();
        // Put the choice back.
        const everyMenu = ['tasks', 'projects', 'planner', 'habits', 'focus', 'skills', 'notes', 'quick-notes', 'bookmarks', 'finance', 'subscriptions', 'stocks', 'exercise', 'food-notes', 'files', 'file-tools', 'transfer', 'ai', 'calculator'];
        await api(tab, '/api/settings/menus', { method: 'POST', body: JSON.stringify({ menus: everyMenu, order: everyMenu, mobile_tabs: ['tasks', 'finance'] }) });

        await click(tab, '.settings-tab[data-tab="app-shares"]');
        await sleep(600);
        await click(tab, '#btnCreateAppShare');
        await sleep(200);
        check(await tab.eval(`!document.getElementById('appShareError').hidden`), 'creating a menu share without a name shows an error in the page');
        await fill(tab, '#asNewLabel', `แชร์ ${STAMP}`);
        await tab.eval(`document.querySelector('input[name="as_menus[]"][value="tasks"]').click()`);
        await click(tab, '#btnCreateAppShare');
        await sleep(900);
        check(await tab.eval(`document.getElementById('appSharesTableBody').textContent.includes(${JSON.stringify(STAMP)})`), 'a new menu share is listed');
        const appShares = await api(tab, '/api/app-shares');
        const appShare = (appShares.body.shares || []).find(s => (s.label || '').includes(STAMP));
        check(!!appShare, 'it reached the server');
        if (appShare) made.appShares.push(appShare.id);

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
            for (const id of made.subs) await api(tab, `/api/subscriptions/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.stocks) await api(tab, `/api/stocks/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.bookmarks) await api(tab, `/api/bookmarks/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.foods) await api(tab, `/api/food-notes/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.workouts) await api(tab, `/api/exercise/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.skills) await api(tab, `/api/skills/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.notes) await api(tab, `/api/notes/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.files) await api(tab, `/api/files/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.appShares) await api(tab, `/api/app-shares/${id}`, { method: 'DELETE' }).catch(() => {});
            for (const id of made.events) await api(tab, `/api/planner/events/${id}`, { method: 'DELETE' }).catch(() => {});
        }
        stop();
    }

    console.log(`\n${checks} checks passed`);
}

main().catch(error => { console.error('\n' + error.message); process.exit(1); });
