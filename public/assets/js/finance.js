/* =====================================================
   finance.js — the month's accounts

   One month is on screen at a time (currentMonth). Everything that depends on
   it is loaded together: the summary with where the expenses went, and the
   entries a page at a time. The year chart is separate, and is redrawn when the
   theme changes. The PDF report is in finance-report.js.
===================================================== */

let transactions = [];
let editingTxnId = null;
let financeChart = null;
let chartData = [];
let currentChartType = 'bar'; // 'bar' or 'line'
let currentMonth = thisMonth(); // YYYY-MM
let typeFilter = '';            // '', 'income' or 'expense'

const THAI_MONTHS_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const THAI_MONTHS_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const THAI_DAYS_SHORT = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
const MINUS = '−'; // a real minus sign, the width of a plus, so figures line up

// The list is read a page at a time: a few years of transactions is a lot of
// rows to send and even more to put in the DOM at once.
const TXN_PAGE_SIZE = 100;
let txnPagination = null;

const financeCats = window.financeCategories || [];

function thisMonth() {
    return todayISO().slice(0, 7);
}

/** Money as a column of figures wants it: two decimals, thousands separated. */
function amountText(amount) {
    return formatMoney(Math.abs(parseFloat(amount) || 0));
}

/** "2.5" or "1,250.25" as a number, or NaN. */
function parseAmount(text) {
    return parseFloat(String(text).replace(/,/g, '').trim());
}

document.addEventListener('DOMContentLoaded', function () {
    fillCategorySelect(document.getElementById('qaCategory'), 'expense', '');
    fillCategoryFilter();

    document.querySelectorAll('input[name="qaType"]').forEach(radio => radio.addEventListener('change', onQuickTypeChange));
    document.getElementById('txnType')?.addEventListener('change', () => {
        fillCategorySelect(document.getElementById('txnCategory'), document.getElementById('txnType').value, document.getElementById('txnCategory').value);
    });

    document.getElementById('monthPrev')?.addEventListener('click', () => moveMonth(-1));
    document.getElementById('monthNext')?.addEventListener('click', () => moveMonth(1));
    document.getElementById('monthNow')?.addEventListener('click', () => { currentMonth = thisMonth(); loadMonth(); });

    document.getElementById('searchFilter')?.addEventListener('input', filterTransactionsLocal);
    document.getElementById('categoryFilter')?.addEventListener('change', filterTransactionsLocal);
    document.querySelectorAll('#typeFilter button').forEach(btn => btn.addEventListener('click', () => {
        typeFilter = btn.dataset.type;
        document.querySelectorAll('#typeFilter button').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        loadTransactions();
    }));

    document.getElementById('txnLoadMore')?.addEventListener('click', async function (event) {
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = 'กำลังโหลด…';
        await loadTransactions(true);
        button.disabled = false;
        button.textContent = 'โหลดเพิ่ม';
    });

    loadMonth();
    loadChart(document.getElementById('chartYear')?.value);
    onThemeChange(() => drawChart());
});

/* ---------- the month ---------- */

function moveMonth(delta) {
    const [y, m] = currentMonth.split('-').map(Number);
    const moved = new Date(y, m - 1 + delta, 1);
    currentMonth = moved.getFullYear() + '-' + String(moved.getMonth() + 1).padStart(2, '0');
    loadMonth();
}

function renderMonthBar() {
    const [y, m] = currentMonth.split('-').map(Number);
    document.getElementById('monthLabel').textContent = THAI_MONTHS_FULL[m - 1] + ' ' + (y + 543);
    document.getElementById('monthNow').hidden = currentMonth === thisMonth();
}

function showFinanceError(message) {
    const box = document.getElementById('financeError');
    box.hidden = message === '';
    box.className = message === '' ? '' : 'alert alert-danger';
    box.innerHTML = message === '' ? '' : message + ' <button type="button" class="btn btn-sm" data-act="loadMonth">ลองอีกครั้ง</button>';
}

async function loadMonth() {
    renderMonthBar();
    showFinanceError('');
    await Promise.all([loadSummary(), loadTransactions()]);
}

async function loadSummary() {
    try {
        const data = await apiFetch(BASE_URL + '/api/finance/summary?month=' + currentMonth);
        const income = parseFloat(data.income || 0);
        const expense = parseFloat(data.expense || 0);
        const balance = parseFloat(data.balance || 0);

        document.getElementById('sumIncome').textContent = '+' + amountText(income);
        document.getElementById('sumExpense').textContent = MINUS + amountText(expense);
        document.getElementById('sumBalance').textContent = (balance < 0 ? MINUS : '') + amountText(balance);

        renderRatio(income, expense);
        renderCategoryBreakdown(data.by_category || [], expense);
    } catch {
        showFinanceError('โหลดสรุปของเดือนนี้ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่');
    }
}

/** What share of the month's income went out, as a bar and a sentence. */
function renderRatio(income, expense) {
    const box = document.getElementById('financeRatio');
    const bar = document.getElementById('spendingRatioBar');
    const text = document.getElementById('spendingRatioText');

    if (income <= 0 && expense <= 0) { box.hidden = true; return; }
    box.hidden = false;

    if (income <= 0) {
        bar.style.width = '100%';
        bar.classList.add('over');
        text.textContent = 'เดือนนี้มีแต่รายจ่าย ยังไม่มีรายรับ';
        return;
    }

    const ratio = expense / income * 100;
    bar.style.width = Math.min(ratio, 100).toFixed(1) + '%';
    bar.classList.toggle('over', ratio > 100);
    text.textContent = ratio > 100
        ? 'รายจ่ายมากกว่ารายรับ ' + (ratio - 100).toFixed(0) + '% (' + amountText(expense) + ' จาก ' + amountText(income) + ' บาท)'
        : 'รายจ่ายคิดเป็น ' + ratio.toFixed(0) + '% ของรายรับ (' + amountText(expense) + ' จาก ' + amountText(income) + ' บาท)';
}

function renderCategoryBreakdown(rows, totalExpense) {
    const container = document.getElementById('categoryBreakdown');
    if (!rows.length) {
        container.innerHTML = '<div class="empty-state"><p class="empty-state-text">ยังไม่มีรายจ่ายในเดือนนี้</p></div>';
        return;
    }

    container.innerHTML = rows.map(c => {
        const pct = totalExpense > 0 ? c.amount / totalExpense * 100 : 0;
        return '<div class="breakdown-row">'
            + '<div class="breakdown-info"><span class="breakdown-cat-name">' + escHtml(c.name) + '</span>'
            + '<span class="breakdown-cat-amount">' + amountText(c.amount) + ' <span class="breakdown-cat-pct">' + pct.toFixed(0) + '%</span></span></div>'
            + '<div class="progress" role="img" aria-label="' + pct.toFixed(0) + ' เปอร์เซ็นต์ของรายจ่าย"><div class="progress-bar" style="width:' + pct.toFixed(1) + '%"></div></div>'
            + '</div>';
    }).join('');
}

/* ---------- the entries ---------- */

async function loadTransactions(append = false) {
    const params = new URLSearchParams();
    params.set('month', currentMonth);
    if (typeFilter) params.set('type', typeFilter);
    params.set('limit', TXN_PAGE_SIZE);
    params.set('offset', append ? transactions.length : 0);

    const list = document.getElementById('transactionList');
    try {
        const data = await apiFetch(BASE_URL + '/api/finance?' + params.toString());
        const page = data.transactions || [];
        transactions = append ? transactions.concat(page) : page;
        txnPagination = data.pagination || null;
        list.removeAttribute('aria-busy');

        renderTally();
        filterTransactionsLocal();
        renderLoadMore();
    } catch {
        list.removeAttribute('aria-busy');
        list.innerHTML = '';
        showFinanceError('โหลดรายการไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่');
    }
}

function renderTally() {
    const total = txnPagination && typeof txnPagination.total === 'number' ? txnPagination.total : transactions.length;
    document.getElementById('financeTally').textContent = total === 0
        ? 'ยังไม่มีรายการในเดือนนี้'
        : total + ' รายการในเดือนนี้';
    document.getElementById('txnCount').textContent = total > 0 ? String(total) : '';
}

function renderLoadMore() {
    const wrap = document.getElementById('txnLoadMoreWrap');
    if (!wrap || !txnPagination) return;
    wrap.hidden = !txnPagination.has_more;
}

function dayHeading(iso) {
    const d = new Date(iso + 'T12:00:00');
    return THAI_DAYS_SHORT[d.getDay()] + ' ' + d.getDate() + ' ' + THAI_MONTHS_SHORT[d.getMonth()] + ' ' + (d.getFullYear() + 543);
}

function renderTransactions(list) {
    const el = document.getElementById('transactionList');

    if (!list.length) {
        const filtered = transactions.length > 0;
        el.innerHTML = filtered
            ? '<div class="empty-state"><p class="empty-state-text">ไม่มีรายการที่ตรงกับตัวกรอง</p><button type="button" class="btn btn-sm" data-act="clearTransactionFilters">ล้างตัวกรอง</button></div>'
            : '<div class="empty-state"><p class="empty-state-title">ยังไม่มีรายการในเดือนนี้</p>'
              + '<p class="empty-state-text">บันทึกรายรับหรือรายจ่ายแรก แล้วยอดคงเหลือจะขึ้นด้านบน</p>'
              + '<button type="button" class="btn" data-act="openAddTransaction">บันทึกรายการแรก</button></div>';
        return;
    }

    // Entries come newest first; a heading goes in wherever the day changes.
    let html = '';
    let day = null;
    list.forEach(t => {
        if (t.txn_date !== day) {
            if (day !== null) html += '</ul>';
            day = t.txn_date;
            html += '<div class="subhead">' + dayHeading(day) + '</div><ul class="ruled-list">';
        }

        const income = t.type === 'income';
        const text = escHtml(t.description || t.category_name || 'ไม่มีรายละเอียด');
        html += '<li class="ruled-row txn-row">'
            + '<span class="grow"><span class="title">' + text + '</span>'
            + '<span class="meta">' + escHtml(t.category_name || 'ไม่ระบุหมวด') + (income ? ' · รายรับ' : '') + '</span></span>'
            + '<span class="side num ' + (income ? 'in' : 'out') + '">' + (income ? '+' : MINUS) + amountText(t.amount) + '</span>'
            + '<span class="txn-actions">'
            + '<button type="button" class="icon-btn sm" data-act="openEditTransaction" data-args="[' + t.id + ']" aria-label="แก้ไข: ' + text + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
            + '<button type="button" class="icon-btn sm danger" data-act="deleteTxn" data-args="[' + t.id + ']" aria-label="ลบ: ' + text + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</span></li>';
    });
    el.innerHTML = html + '</ul>';
}

function filterTransactionsLocal() {
    const q = document.getElementById('searchFilter').value.trim().toLowerCase();
    const cat = document.getElementById('categoryFilter').value;

    renderTransactions(transactions.filter(t => {
        const matchesQuery = !q
            || (t.description && t.description.toLowerCase().includes(q))
            || (t.category_name && t.category_name.toLowerCase().includes(q))
            || (t.amount && String(t.amount).includes(q));
        return matchesQuery && (!cat || t.category_name === cat);
    }));
}

function clearTransactionFilters() {
    document.getElementById('searchFilter').value = '';
    document.getElementById('categoryFilter').value = '';
    filterTransactionsLocal();
}

/* ---------- the year chart ---------- */

async function loadChart(year) {
    if (typeof Chart === 'undefined') return;
    try {
        const data = await apiFetch(BASE_URL + '/api/finance/chart?year=' + year);
        chartData = data.chart || [];
        drawChart();
    } catch { /* the page is complete without the chart */ }
}

function drawChart() {
    const canvas = document.getElementById('financeChart');
    if (!canvas || typeof Chart === 'undefined') return;
    if (financeChart) financeChart.destroy();

    const theme = chartTheme();
    const line = currentChartType === 'line';
    const dataset = (label, values, color) => ({
        label,
        data: values,
        backgroundColor: line ? withAlpha(color, 0.12) : color,
        borderColor: color,
        borderWidth: line ? 2 : 0,
        tension: 0.3,
        fill: line,
        borderRadius: 0,
        pointRadius: line ? 3 : 0,
    });

    financeChart = new Chart(canvas, {
        type: currentChartType,
        data: {
            labels: chartData.map(c => THAI_MONTHS_SHORT[c.month - 1]),
            datasets: [
                dataset('รายรับ', chartData.map(c => c.income), theme.income),
                dataset('รายจ่าย', chartData.map(c => c.expense), theme.expense),
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: chartAnimation(),
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: { usePointStyle: false, boxWidth: 12, boxHeight: 12, padding: 16, font: { family: theme.font, size: 13 }, color: theme.ink },
                },
                tooltip: {
                    padding: 10,
                    cornerRadius: 6,
                    backgroundColor: theme.ink,
                    titleColor: theme.paper,
                    bodyColor: theme.paper,
                    titleFont: { family: theme.font, size: 13, weight: '500' },
                    bodyFont: { family: theme.font, size: 13 },
                    callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + formatMoney(ctx.parsed.y) + ' บาท' },
                },
            },
            scales: {
                x: { ticks: { font: { family: theme.font, size: 12 }, color: theme.pencil, maxRotation: 0, autoSkip: true }, grid: { display: false }, border: { color: theme.pencil } },
                y: { ticks: { font: { family: theme.font, size: 12 }, color: theme.pencil, callback: v => Number(v).toLocaleString('th-TH') }, grid: { color: theme.rule }, border: { display: false } },
            },
        },
    });
}

function changeChartType(type) {
    if (currentChartType === type) return;
    currentChartType = type;
    document.querySelectorAll('#chartTypeToggle button').forEach(btn => {
        btn.setAttribute('aria-pressed', btn.dataset.chartType === type ? 'true' : 'false');
    });
    drawChart();
}

/* ---------- categories in the forms ---------- */

/** Offers the categories that fit an entry type (income, expense, or both), keeping the choice if it still fits. */
function fillCategorySelect(select, type, selected) {
    if (!select) return;
    select.innerHTML = '<option value="">ไม่ระบุหมวด</option>';
    financeCats
        .filter(c => c.type === type || c.type === 'both')
        .forEach(c => {
            const option = document.createElement('option');
            option.value = c.id;
            option.textContent = c.name;
            select.appendChild(option);
        });
    select.value = [...select.options].some(o => o.value === String(selected)) ? String(selected) : '';
}

function fillCategoryFilter() {
    const select = document.getElementById('categoryFilter');
    [...new Set(financeCats.map(c => c.name))].forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        select.appendChild(option);
    });
}

function quickType() {
    return document.querySelector('input[name="qaType"]:checked')?.value === 'income' ? 'income' : 'expense';
}

function onQuickTypeChange() {
    const type = quickType();
    document.getElementById('qaSubmit').textContent = type === 'income' ? 'บันทึกรายรับ' : 'บันทึกรายจ่าย';
    fillCategorySelect(document.getElementById('qaCategory'), type, document.getElementById('qaCategory').value);
}

/* ---------- the dialog ---------- */

function txnError(message) {
    const line = document.getElementById('txnError');
    line.textContent = message;
    line.hidden = message === '';
}

function openAddTransaction() {
    editingTxnId = null;
    document.getElementById('txnModalTitle').textContent = 'บันทึกรายการ';
    document.getElementById('editTxnId').value = '';
    document.getElementById('txnType').value = 'expense';
    document.getElementById('txnAmount').value = '';
    document.getElementById('txnDate').value = todayISO();
    document.getElementById('txnDesc').value = '';
    fillCategorySelect(document.getElementById('txnCategory'), 'expense', '');
    txnError('');
    openModal('txnModal');
}

function openEditTransaction(id) {
    const t = transactions.find(x => x.id === id);
    if (!t) return;
    editingTxnId = id;
    document.getElementById('txnModalTitle').textContent = 'แก้ไขรายการ';
    document.getElementById('editTxnId').value = id;
    document.getElementById('txnType').value = t.type;
    document.getElementById('txnAmount').value = t.amount;
    fillCategorySelect(document.getElementById('txnCategory'), t.type, t.category_id || '');
    document.getElementById('txnDate').value = t.txn_date;
    document.getElementById('txnDesc').value = t.description || '';
    txnError('');
    openModal('txnModal');
}

/** Reload what an entry changes: the month, and the year's chart. */
async function refreshAfterChange() {
    await loadMonth();
    await loadChart(document.getElementById('chartYear')?.value);
}

async function saveTransaction() {
    const amount = parseAmount(document.getElementById('txnAmount').value);
    if (!(amount > 0)) { txnError('ใส่จำนวนเงินเป็นตัวเลขที่มากกว่า 0'); return; }

    const body = {
        type: document.getElementById('txnType').value,
        amount,
        category_id: document.getElementById('txnCategory').value,
        txn_date: document.getElementById('txnDate').value,
        description: document.getElementById('txnDesc').value,
    };

    try {
        const url = editingTxnId ? BASE_URL + '/api/finance/' + editingTxnId : BASE_URL + '/api/finance';
        await apiFetch(url, { method: editingTxnId ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('txnModal');
        // Show the month the entry belongs to, so it does not seem to vanish.
        if (body.txn_date && body.txn_date.slice(0, 7) !== currentMonth) currentMonth = body.txn_date.slice(0, 7);
        await refreshAfterChange();
        toast(body.type === 'income' ? 'บันทึกรายรับแล้ว' : 'บันทึกรายจ่ายแล้ว');
    } catch (err) {
        txnError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function saveQuickTransaction() {
    const amount = parseAmount(document.getElementById('qaAmount').value);
    if (!(amount > 0)) {
        toast('ใส่จำนวนเงินเป็นตัวเลขที่มากกว่า 0', 'danger');
        document.getElementById('qaAmount').focus();
        return;
    }

    const type = quickType();
    try {
        await apiFetch(BASE_URL + '/api/finance', {
            method: 'POST',
            body: JSON.stringify({
                type,
                amount,
                category_id: document.getElementById('qaCategory').value,
                txn_date: todayISO(), // a line added here is for today
                description: document.getElementById('qaDesc').value.trim(),
            }),
        });

        document.getElementById('qaAmount').value = '';
        document.getElementById('qaDesc').value = '';
        currentMonth = thisMonth(); // today's entry belongs to this month
        await refreshAfterChange();
        toast(type === 'income' ? 'บันทึกรายรับแล้ว' : 'บันทึกรายจ่ายแล้ว');
        document.getElementById('qaAmount').focus();
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function deleteTxn(id) {
    if (!await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/finance/' + id, { method: 'DELETE' });
        await refreshAfterChange();
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
