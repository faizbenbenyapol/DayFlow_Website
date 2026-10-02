/* =====================================================
   stocks.js — the portfolio

   The totals, the holdings table, trades, and the charts. Money put in and
   taken out lives in stocks-capital.js, the screenshots in
   stocks-screenshots.js and the AI read of one ticker in stocks-analysis.js;
   all four share the state declared here.
===================================================== */

let stockTxns       = [];
let stockHoldings   = [];
let stockWatchlists = [];
let stockTotals     = {};
let stockCapitalFlows = [];
let stockScreenshots = [];
let editingStockId  = null;
let editingCapitalId = null;
let stkValueChart   = null;
let stkPlChart      = null;
let stkChartSeries  = [];
let stkAutoRefreshed = false;

const THAI_MONTHS_SHORT_STK = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
                               'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const STK_MINUS = '−';
const STK_DASH = '<span class="dim">—</span>';

document.addEventListener('DOMContentLoaded', async function () {
    if (IS_READ_ONLY) {
        // A shared page shows the totals, the cash and the latest screenshot.
        await Promise.all([loadStockPortfolio(), loadScreenshots()]);
        return;
    }

    wireTabs(document.getElementById('stkMainTabs'), () => renderUnifiedStocks());
    wireTabs(document.getElementById('stkSubTabs'), showStockPanel);
    initDropzone();
    onThemeChange(() => drawStockCharts());

    await Promise.all([
        loadStockPortfolio(),
        loadStockWatchlists(),
        loadStockTransactions(),
        loadStockChart(document.getElementById('stkChartYear')?.value),
        loadCapitalFlows(),
        loadScreenshots(),
    ]);
    // Fetch prices again when the oldest is over 15 minutes old, or one is missing.
    maybeAutoRefresh();
});

/* ── Tabs ── */

/**
 * Makes a row of [role=tab] buttons behave as one: click or arrow keys select,
 * only the selected one is in the tab order. `onSelect(button)` runs after.
 */
function wireTabs(list, onSelect) {
    if (!list) return;
    const tabs = Array.from(list.querySelectorAll('[role="tab"]'));
    const select = (tab, focus) => {
        tabs.forEach(t => {
            t.setAttribute('aria-selected', String(t === tab));
            t.tabIndex = t === tab ? 0 : -1;
        });
        if (focus) tab.focus();
        onSelect(tab);
    };
    tabs.forEach((tab, i) => {
        tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        tab.addEventListener('click', () => select(tab, false));
        tab.addEventListener('keydown', e => {
            const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!step) return;
            e.preventDefault();
            select(tabs[(i + step + tabs.length) % tabs.length], true);
        });
    });
}

function showStockPanel(tab) {
    const name = tab.dataset.stkTab;
    document.querySelectorAll('[data-stk-panel]').forEach(p => { p.hidden = p.dataset.stkPanel !== name; });
    if (name === 'chart') {
        if (stkValueChart) stkValueChart.resize();
        if (stkPlChart) stkPlChart.resize();
    }
}

/** Opens a sub-tab from code, as the AI button on a row does. */
function openStockTab(name) {
    document.querySelector('[data-stk-tab="' + name + '"]')?.click();
}

/* ── Numbers ── */

/** A plain amount with a true minus sign, in the currency's own form. */
function plainMoney(value, prefix = '', suffix = '') {
    const n = Number(value) || 0;
    return (n < 0 ? STK_MINUS : '') + prefix + formatMoney(Math.abs(n)) + suffix;
}

/** +1,234.00 or −1,234.00, so a loss reads as one without its colour. */
function signedMoney(value) {
    const n = Number(value) || 0;
    return (n > 0 ? '+' : n < 0 ? STK_MINUS : '') + formatMoney(Math.abs(n));
}

function signedPct(value) {
    const n = Number(value) || 0;
    return (n > 0 ? '+' : n < 0 ? STK_MINUS : '') + Math.abs(n).toFixed(2) + '%';
}

/** Colours a figure as a gain or a loss; zero and unknown stay ink. */
function markSigned(el, value) {
    if (!el) return;
    const n = value == null ? 0 : Number(value);
    el.classList.toggle('gain', n > 0);
    el.classList.toggle('loss', n < 0);
}

function fmtShares(n) {
    if (n == null) return '—';
    const f = parseFloat(n);
    if (Number.isInteger(f)) return f.toString();
    return f.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}

/* ── Portfolio summary ── */
async function loadStockPortfolio() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/summary');
        stockHoldings = data.holdings || [];
        stockTotals   = data.totals   || {};
        renderStockSummary();
        renderUnifiedStocks();
    } catch (err) {
        toast(err.message || 'โหลดพอร์ตไม่สำเร็จ', 'danger');
        const body = document.getElementById('stkUnifiedList');
        if (body) {
            body.innerHTML = '<tr><td colspan="13"><div class="stk-empty">โหลดพอร์ตไม่สำเร็จ '
                + '<button type="button" class="btn btn-sm" data-act="loadStockPortfolio">ลองอีกครั้ง</button></div></td></tr>';
        }
    }
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
    return el;
}

function renderStockSummary() {
    const t = stockTotals;

    setText('stkMarketValue', formatMoney(t.market_value || 0));
    setText('stkCostBasis', formatMoney(t.cost_basis || 0));

    const unrealized = t.unrealized_pl || 0;
    markSigned(setText('stkUnrealized', signedMoney(unrealized)), unrealized);

    const note = document.getElementById('stkUnrealizedPct');
    if (note) {
        const cost = t.cost_basis || 0;
        note.textContent = cost > 0 ? signedPct(unrealized / cost * 100) + ' ของต้นทุน' : '';
        markSigned(note, cost > 0 ? unrealized : 0);
    }

    const realized = t.realized_pl || 0;
    markSigned(setText('stkRealized', signedMoney(realized)), realized);

    // Money put in and taken out, by currency
    const cap = t.capital || {};
    const thb = cap.THB || { net_capital: 0, cash_balance: 0 };
    const usd = cap.USD || { net_capital: 0, cash_balance: 0 };

    setText('capNetTHB', plainMoney(thb.net_capital, '', ' บาท'));
    markSigned(setText('capCashTHB', plainMoney(thb.cash_balance, '', ' บาท')), thb.cash_balance);
    setText('capNetUSD', plainMoney(usd.net_capital, '$'));
    markSigned(setText('capCashUSD', plainMoney(usd.cash_balance, '$')), usd.cash_balance);
    setText('sideNetTHB', plainMoney(thb.net_capital, '', ' บาท'));
    markSigned(setText('sideCashTHB', plainMoney(thb.cash_balance, '', ' บาท')), thb.cash_balance);

    const latest = stockHoldings.map(h => h.fetched_at).filter(Boolean).sort().pop();
    setText('stkRefreshedAt', latest ? 'ราคาล่าสุด ' + formatDateTime(latest) : '');
}

/* ── Watchlist ── */
async function loadStockWatchlists() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/watchlists');
        stockWatchlists = data.watchlists || [];
        renderUnifiedStocks();
    } catch (err) {
        console.error(err);
    }
}

async function toggleWatchlist(ticker, market) {
    const isWl = stockWatchlists.some(w => w.ticker === ticker);
    const action = isWl ? 'remove' : 'add';

    // Show the change at once; it is put right again if the save fails.
    if (action === 'add') {
        stockWatchlists.push({ ticker, market, isTemp: true });
    } else {
        stockWatchlists = stockWatchlists.filter(w => w.ticker !== ticker);
    }
    renderUnifiedStocks();

    try {
        await apiFetch(BASE_URL + '/api/stocks/watchlists/toggle', {
            method: 'POST',
            body: JSON.stringify({ ticker, market, action })
        });
        loadStockWatchlists();
    } catch (err) {
        toast('บันทึกรายการที่ติดตามไม่สำเร็จ', 'danger');
        loadStockWatchlists();
    }
}

function analyzeStockInstantly(ticker, market) {
    const tInput = document.getElementById('stkAnalyzeTicker');
    const mSelect = document.getElementById('stkAnalyzeMarket');
    if (tInput) tInput.value = ticker;
    if (mSelect) mSelect.value = market;

    openStockTab('analysis');
    document.querySelector('[data-stk-panel="analysis"]')?.scrollIntoView({ block: 'start' });
    runStockAnalysis();
}

/* ── The holdings table ── */
function formatMetric(val) {
    if (val == null || val === '') return STK_DASH;
    const num = parseFloat(val);
    return isNaN(num) ? STK_DASH : num.toFixed(2);
}

/** What the table lists for a tab, each with the holding and watchlist rows merged. */
function stockRowsFor(tab) {
    const merged = (ticker, fallbackMarket) => {
        const holding = stockHoldings.find(h => h.ticker === ticker);
        const wl = stockWatchlists.find(w => w.ticker === ticker);
        return {
            ticker,
            market: holding ? holding.market : (wl ? wl.market : (fallbackMarket || 'US')),
            holding,
            wl,
            isWl: !!wl,
        };
    };

    if (tab === 'portfolio') {
        return stockHoldings.map(h => merged(h.ticker, h.market))
            .sort((a, b) => (b.holding?.market_value || 0) - (a.holding?.market_value || 0));
    }
    if (tab === 'watchlists') {
        return stockWatchlists.map(w => merged(w.ticker, w.market)).sort((a, b) => a.ticker.localeCompare(b.ticker));
    }
    const tickers = new Set([...stockHoldings.map(h => h.ticker), ...stockWatchlists.map(w => w.ticker)]);
    const rows = Array.from(tickers).map(t => merged(t));
    return (tab === 'all' ? rows : rows.filter(r => r.market === tab)).sort((a, b) => a.ticker.localeCompare(b.ticker));
}

const EMPTY_TABLE = {
    portfolio:  ['ยังไม่มีหุ้นในพอร์ต', 'บันทึกรายการซื้อ แล้วหุ้นจะมาอยู่ตรงนี้พร้อมกำไรขาดทุน'],
    watchlists: ['ยังไม่ได้ติดตามหุ้นตัวไหน', 'กดดาวหน้าชื่อหุ้นในแท็บ "ทั้งหมด" เพื่อดูราคาและตัวชี้วัดโดยไม่ต้องซื้อ'],
};

function stockRow(item, isPortfolio) {
    const h = item.holding || {};
    const wl = item.wl || {};
    const tickerAttr = escHtml(item.ticker);
    const args = escHtml(JSON.stringify([item.ticker, item.market]));

    const lastPrice = h.last_price ?? wl.last_price;
    const dayChange = h.day_change ?? wl.day_change;
    const dayChangePct = h.day_change_pct ?? wl.day_change_pct;
    const currency = h.currency || (item.market === 'SET' ? 'THB' : 'USD');

    const metric = key => formatMetric(h[key] ?? wl[key]);
    const hasHolding = h.shares != null && Number(h.shares) !== 0;

    const dayCell = dayChange == null
        ? '<td class="num">' + STK_DASH + '</td>'
        : '<td class="num ' + (dayChange > 0 ? 'gain' : dayChange < 0 ? 'loss' : '') + '">' + signedMoney(dayChange)
            + (dayChangePct != null ? '<small>' + signedPct(dayChangePct) + '</small>' : '') + '</td>';

    let cells = '<td class="stk-sticky"><button type="button" class="star" data-act="toggleWatchlist" data-args="' + args + '"'
        + ' aria-pressed="' + item.isWl + '" aria-label="' + (item.isWl ? 'เลิกติดตาม ' : 'ติดตาม ') + tickerAttr + '">'
        + '<svg class="icon" aria-hidden="true"><use href="#i-star"/></svg></button>'
        + '<span class="stk-ticker">' + tickerAttr + '</span><span class="tag">' + escHtml(item.market) + '</span></td>'
        + '<td class="num">' + (lastPrice == null ? STK_DASH : formatMoney(lastPrice)) + '</td>'
        + dayCell
        + '<td class="num">' + metric('pe_ratio') + '</td>'
        + '<td class="num">' + metric('forward_pe') + '</td>'
        + '<td class="num">' + metric('peg_ratio') + '</td>'
        + '<td class="num">' + metric('p_fcf_ratio') + '</td>'
        + '<td class="num">' + metric('eps') + '</td>';

    if (isPortfolio) {
        const pl = h.unrealized_pl;
        const plPct = h.unrealized_pct;
        cells += '<td class="num">' + (hasHolding ? fmtShares(h.shares) : STK_DASH) + '</td>'
            + '<td class="num">' + (h.avg_cost == null ? STK_DASH : formatMoney(h.avg_cost) + '<small>' + escHtml(currency) + '</small>') + '</td>'
            + '<td class="num">' + (h.market_value == null ? STK_DASH : formatMoney(h.market_value)) + '</td>'
            + (pl == null
                ? '<td class="num">' + STK_DASH + '</td>'
                : '<td class="num ' + (pl > 0 ? 'gain' : pl < 0 ? 'loss' : '') + '">' + signedMoney(pl)
                    + (plPct != null ? '<small>' + signedPct(plPct) + '</small>' : '') + '</td>');
    }

    cells += '<td class="stk-act"><button type="button" class="btn btn-sm" data-act="analyzeStockInstantly" data-args="' + args + '"'
        + ' aria-label="วิเคราะห์ ' + tickerAttr + ' ด้วย AI">วิเคราะห์</button></td>';
    return '<tr>' + cells + '</tr>';
}

function renderUnifiedStocks() {
    const tbody = document.getElementById('stkUnifiedList');
    if (!tbody) return;

    const tab = document.querySelector('#stkMainTabs [aria-selected="true"]')?.dataset.mainTab || 'portfolio';
    const isPortfolio = tab === 'portfolio';
    document.getElementById('stkTable').dataset.view = isPortfolio ? 'portfolio' : 'list';

    const rows = stockRowsFor(tab);
    if (!rows.length) {
        const [title, text] = EMPTY_TABLE[tab] || ['ไม่มีหุ้นในหมวดนี้', 'ยังไม่มีหุ้นที่บันทึกหรือติดตามไว้ในตลาดนี้'];
        const action = tab === 'portfolio' ? ' <button type="button" class="btn btn-sm btn-primary" data-act="openAddStock">บันทึกรายการแรก</button>' : '';
        tbody.innerHTML = '<tr><td colspan="' + (isPortfolio ? 13 : 9) + '"><div class="stk-empty"><strong>' + title + '</strong>' + text + action + '</div></td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(item => stockRow(item, isPortfolio)).join('');
}

/* ── What a ratio means ── */
const METRICS = {
    pe: {
        title: 'P/E (ราคาต่อกำไร)',
        meaning: 'ราคาหุ้นเทียบกับกำไรสุทธิต่อหุ้น (EPS) ของ 12 เดือนที่ผ่านมา',
        formula: 'ราคาหุ้นปัจจุบัน ÷ กำไรต่อหุ้น (EPS)',
        heading: 'อ่านค่าอย่างไร',
        points: [
            'ค่าต่ำมักหมายถึงราคาถูก หรือบริษัทโตช้า',
            'ค่าสูงหมายถึงนักลงทุนยอมจ่ายแพงเพราะคาดว่ากำไรจะโตต่อ',
            'เทียบกับบริษัทในอุตสาหกรรมเดียวกันจึงจะมีความหมาย',
        ],
    },
    forward_pe: {
        title: 'Forward P/E',
        meaning: 'ราคาหุ้นเทียบกับกำไรต่อหุ้นที่นักวิเคราะห์คาดไว้ในอีก 12 เดือนข้างหน้า',
        formula: 'ราคาหุ้นปัจจุบัน ÷ กำไรต่อหุ้นที่คาดการณ์',
        heading: 'อ่านค่าอย่างไร',
        points: [
            'ถ้าต่ำกว่า P/E ปัจจุบัน แปลว่านักวิเคราะห์คาดว่ากำไรจะเพิ่ม',
            'แม่นเท่าที่ประมาณการกำไรแม่น ประมาณการพลาดได้',
        ],
    },
    peg: {
        title: 'PEG (P/E เทียบกับการเติบโต)',
        meaning: 'ปรับ P/E ด้วยอัตราการเติบโตของกำไร เพื่อดูว่าราคาคุ้มกับการเติบโตหรือไม่',
        formula: 'P/E ÷ อัตราการเติบโตของกำไร (% ต่อปี)',
        heading: 'เกณฑ์คร่าวๆ',
        points: [
            'ต่ำกว่า 1 ราคาต่ำกว่าอัตราการเติบโต',
            'ราว 1 ราคาพอเหมาะกับการเติบโต',
            'สูงกว่า 1 ราคาแพงเมื่อเทียบกับการเติบโต',
        ],
    },
    p_fcf: {
        title: 'P/FCF (ราคาต่อกระแสเงินสดอิสระ)',
        meaning: 'ราคาหุ้นเทียบกับเงินสดที่บริษัทเหลือจริงหลังหักเงินลงทุนในสินทรัพย์',
        formula: 'ราคาหุ้นปัจจุบัน ÷ กระแสเงินสดอิสระต่อหุ้น',
        heading: 'ทำไมดู',
        points: [
            'ปรุงแต่งยากกว่ากำไรทางบัญชี เพราะเป็นเงินสดที่จ่ายปันผล ซื้อหุ้นคืน หรือลดหนี้ได้จริง',
            'ค่าต่ำ แปลว่าสร้างเงินสดได้ดีเมื่อเทียบกับราคา',
        ],
    },
    eps: {
        title: 'EPS (กำไรต่อหุ้น)',
        meaning: 'ส่วนแบ่งกำไรสุทธิของบริษัทที่ตกแก่หุ้นสามัญหนึ่งหุ้น',
        formula: '(กำไรสุทธิ − ปันผลหุ้นบุริมสิทธิ) ÷ จำนวนหุ้นสามัญ',
        heading: 'ดูอย่างไร',
        points: [
            'เป็นตัวตั้งของ P/E และการประเมินมูลค่าส่วนใหญ่',
            'ควรโตต่อเนื่องหลายปี และโตจากกำไรจริง ไม่ใช่จากการซื้อหุ้นคืนจนจำนวนหุ้นลด',
        ],
    },
};

function showMetricExplain(metric) {
    const m = METRICS[metric];
    if (!m) return;
    document.getElementById('metricTitle').textContent = m.title;
    document.getElementById('metricBody').innerHTML =
        '<p><strong>ความหมาย</strong> ' + escHtml(m.meaning) + '</p>'
        + '<p><strong>สูตร</strong> ' + escHtml(m.formula) + '</p>'
        + '<h3 class="subhead">' + escHtml(m.heading) + '</h3>'
        + '<ul>' + m.points.map(p => '<li>' + escHtml(p) + '</li>').join('') + '</ul>';
    openModal('metricModal');
}

/* ── Trades ── */
async function loadStockTransactions() {
    const params = new URLSearchParams();
    const ticker = document.getElementById('stkTickerFilter')?.value.trim();
    const market = document.getElementById('stkMarketFilter')?.value || '';
    if (ticker) params.set('ticker', ticker.toUpperCase());
    if (market) params.set('market', market);
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks?' + params.toString());
        stockTxns = data.transactions || [];
        renderStockTxnList();
    } catch (err) {
        toast(err.message || 'โหลดรายการไม่สำเร็จ', 'danger');
    }
}

function renderStockTxnList() {
    const tbody = document.getElementById('stkTxnList');
    if (!tbody) return;

    if (!stockTxns.length) {
        const filtered = document.getElementById('stkTickerFilter')?.value || document.getElementById('stkMarketFilter')?.value;
        tbody.innerHTML = '<tr><td colspan="8"><div class="stk-empty">'
            + (filtered ? 'ไม่พบรายการที่ตรงกับตัวกรอง' : '<strong>ยังไม่มีรายการซื้อขาย</strong>กด "บันทึกรายการ" ด้านบนเพื่อเพิ่มรายการแรก')
            + '</div></td></tr>';
        return;
    }

    tbody.innerHTML = stockTxns.map(t => {
        const value = parseFloat(t.quantity) * parseFloat(t.price);
        const side = t.side === 'buy' ? '<span class="badge badge-dark">ซื้อ</span>' : '<span class="badge badge-gray">ขาย</span>';
        return '<tr>'
            + '<td>' + escHtml(formatDate(t.txn_date)) + '</td>'
            + '<td><span class="stk-ticker">' + escHtml(t.ticker) + '</span><span class="tag">' + escHtml(t.market) + '</span></td>'
            + '<td>' + side + '</td>'
            + '<td class="num">' + fmtShares(t.quantity) + '</td>'
            + '<td class="num">' + formatMoney(t.price) + '<small>' + escHtml(t.currency) + '</small></td>'
            + '<td class="num">' + formatMoney(t.fee) + '</td>'
            + '<td class="num">' + formatMoney(value) + '</td>'
            + '<td class="stk-act mode-readonly-hide"><button type="button" class="icon-btn sm" data-act="openEditStock" data-args="[' + t.id + ']" aria-label="แก้ไขรายการ ' + escHtml(t.ticker) + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button></td>'
            + '</tr>';
    }).join('');
}

/* ── Charts ── */
async function loadStockChart(year) {
    if (typeof Chart === 'undefined') return;
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/chart?year=' + year);
        stkChartSeries = data.series || [];
        drawStockCharts();
    } catch (err) {
        toast(err.message || 'โหลดกราฟไม่สำเร็จ', 'danger');
    }
}

function stkChartOpts(theme) {
    const font = { family: theme.font, size: 12 };
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: chartAnimation(),
        plugins: {
            legend: { position: 'top', align: 'end', labels: { boxWidth: 12, boxHeight: 12, padding: 16, font, color: theme.ink } },
            tooltip: {
                padding: 10,
                cornerRadius: 6,
                backgroundColor: theme.ink,
                titleColor: theme.paper,
                bodyColor: theme.paper,
                titleFont: font,
                bodyFont: font,
                callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + formatMoney(ctx.parsed.y) + ' บาท' },
            },
        },
        scales: {
            x: { ticks: { font, color: theme.pencil }, grid: { display: false } },
            y: { ticks: { font, color: theme.pencil, callback: v => Number(v).toLocaleString('th-TH') }, grid: { color: theme.rule } },
        },
    };
}

function drawStockCharts() {
    if (typeof Chart === 'undefined') return;
    const theme = chartTheme();

    const valueCanvas = document.getElementById('stkValueChart');
    if (valueCanvas) {
        if (stkValueChart) stkValueChart.destroy();
        const line = (label, values, color, dash) => ({
            label,
            data: values,
            borderColor: color,
            backgroundColor: withAlpha(color, 0.1),
            borderWidth: 2,
            borderDash: dash,
            tension: 0.25,
            fill: false,
            pointRadius: 3,
        });
        stkValueChart = new Chart(valueCanvas, {
            type: 'line',
            data: {
                labels: stkChartSeries.map(s => THAI_MONTHS_SHORT_STK[s.month - 1]),
                datasets: [
                    line('ต้นทุนสะสม', stkChartSeries.map(s => s.cost_basis), theme.pencil, [5, 4]),
                    line('มูลค่าตลาด (ประมาณ)', stkChartSeries.map(s => s.market_value), theme.ink, []),
                ],
            },
            options: stkChartOpts(theme),
        });
    }

    const plCanvas = document.getElementById('stkPlChart');
    if (plCanvas) {
        if (stkPlChart) stkPlChart.destroy();
        const pls = stockHoldings.map(h => h.unrealized_pl || 0);
        stkPlChart = new Chart(plCanvas, {
            type: 'bar',
            data: {
                labels: stockHoldings.map(h => h.ticker),
                datasets: [{
                    label: 'กำไร/ขาดทุนที่ยังไม่ปิด',
                    data: pls,
                    backgroundColor: pls.map(v => v >= 0 ? theme.income : theme.expense),
                    borderWidth: 0,
                    borderRadius: 0,
                    maxBarThickness: 44,
                }],
            },
            options: (() => {
                // One legend swatch cannot stand for red and green bars; the caption says what it is.
                const options = stkChartOpts(theme);
                options.plugins.legend.display = false;
                return options;
            })(),
        });
    }
}

/* ── Refresh prices ── */
async function refreshPrices(silent = false) {
    const btn = document.getElementById('stkRefreshBtn');
    const label = btn?.querySelector('span');
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    if (label) label.textContent = 'กำลังดึงราคา…';
    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/refresh', {
            method: 'POST',
            body: JSON.stringify({ auto: silent === true })
        });
        await loadStockPortfolio();
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);

        if (silent !== true) {
            const errs = Object.keys(res.errors || {});
            const ok = (res.updated || []).length;
            const sk = (res.skipped || []).length;
            let msg = 'อัปเดต ' + ok + ' · ข้าม ' + sk + ' (อัปเดตใหม่ได้ในอีก 5 นาที)';
            if (errs.length) msg += ' · พลาด ' + errs.length;
            toast(msg, errs.length ? 'warning' : 'success');
        }
    } catch (err) {
        if (silent !== true) toast(err.message || 'รีเฟรชไม่สำเร็จ', 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
        if (label) label.textContent = 'รีเฟรชราคา';
    }
}

function maybeAutoRefresh() {
    if (stkAutoRefreshed) return;
    stkAutoRefreshed = true;
    if (!stockHoldings.length) return;
    const now = Date.now();
    const STALE_MS = 15 * 60 * 1000;
    const stale = stockHoldings.some(h => {
        if (!h.fetched_at) return true;
        const t = Date.parse(h.fetched_at.replace(' ', 'T'));
        return isNaN(t) || (now - t) > STALE_MS;
    });
    if (stale) refreshPrices(true);
}

/* ── The trade form ── */
function stkError(message) {
    const line = document.getElementById('stkError');
    line.textContent = message;
    line.hidden = message === '';
}

function stockSide() {
    return document.querySelector('input[name="stkSide"]:checked')?.value || 'buy';
}

function setStockSide(side) {
    const radio = document.querySelector('input[name="stkSide"][value="' + side + '"]');
    if (radio) radio.checked = true;
}

function openAddStock() {
    editingStockId = null;
    document.getElementById('stockModalTitle').textContent = 'บันทึกรายการหุ้น';
    document.getElementById('editStockId').value = '';
    document.getElementById('stkTicker').value   = '';
    document.getElementById('stkMarket').value   = 'US';
    setStockSide('buy');
    document.getElementById('stkCurrency').value = 'USD';
    document.getElementById('stkQty').value      = '';
    document.getElementById('stkPrice').value    = '';
    document.getElementById('stkFee').value      = 0;
    document.getElementById('stkDate').value     = todayISO();
    document.getElementById('stkNotes').value    = '';
    document.getElementById('deleteStockBtn').hidden = true;
    stkError('');
    openModal('stockModal');
}

function openEditStock(id) {
    const t = stockTxns.find(x => x.id === id);
    if (!t) return;
    editingStockId = id;
    document.getElementById('stockModalTitle').textContent = 'แก้ไขรายการหุ้น';
    document.getElementById('editStockId').value = id;
    document.getElementById('stkTicker').value   = t.ticker;
    document.getElementById('stkMarket').value   = t.market;
    setStockSide(t.side);
    document.getElementById('stkCurrency').value = t.currency;
    document.getElementById('stkQty').value      = t.quantity;
    document.getElementById('stkPrice').value    = t.price;
    document.getElementById('stkFee').value      = t.fee;
    document.getElementById('stkDate').value     = t.txn_date;
    document.getElementById('stkNotes').value    = t.notes || '';
    document.getElementById('deleteStockBtn').hidden = false;
    stkError('');
    openModal('stockModal');
}

function onStkMarketChange() {
    const m = document.getElementById('stkMarket').value;
    const cur = document.getElementById('stkCurrency');
    if (!cur.value || ['USD', 'THB'].includes(cur.value)) {
        cur.value = m === 'SET' ? 'THB' : (m === 'US' ? 'USD' : cur.value || 'USD');
    }
}

async function saveStock() {
    const body = {
        ticker:   document.getElementById('stkTicker').value.trim().toUpperCase(),
        market:   document.getElementById('stkMarket').value,
        side:     stockSide(),
        quantity: document.getElementById('stkQty').value,
        price:    document.getElementById('stkPrice').value,
        fee:      document.getElementById('stkFee').value || 0,
        currency: document.getElementById('stkCurrency').value.trim().toUpperCase(),
        txn_date: document.getElementById('stkDate').value,
        notes:    document.getElementById('stkNotes').value,
    };

    if (!body.ticker || !/^[A-Z0-9.\-]{1,20}$/.test(body.ticker)) { stkError('ชื่อหุ้น (Ticker) ไม่ถูกต้อง ใช้ตัวอักษรอังกฤษ ตัวเลข จุด หรือขีด'); return; }
    if (!body.quantity || parseFloat(body.quantity) <= 0) { stkError('ใส่จำนวนหุ้นที่มากกว่า 0'); return; }
    if (body.price === '' || parseFloat(body.price) < 0) { stkError('ใส่ราคาต่อหุ้น'); return; }
    if (!body.txn_date) { stkError('เลือกวันที่ก่อน'); return; }
    if (!/^[A-Z]{3}$/.test(body.currency)) { stkError('สกุลเงินต้องเป็นรหัส 3 ตัวอักษร เช่น USD, THB'); return; }

    try {
        const url = editingStockId ? BASE_URL + '/api/stocks/' + editingStockId : BASE_URL + '/api/stocks';
        await apiFetch(url, { method: editingStockId ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('stockModal');
        await Promise.all([loadStockPortfolio(), loadStockTransactions()]);
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);
        toast('บันทึกรายการแล้ว');
    } catch (err) {
        stkError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteStock() {
    if (!editingStockId || !await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้ และกำไรขาดทุนของหุ้นตัวนี้จะคำนวณใหม่', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/' + editingStockId, { method: 'DELETE' });
        closeModal('stockModal');
        await Promise.all([loadStockPortfolio(), loadStockTransactions()]);
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
