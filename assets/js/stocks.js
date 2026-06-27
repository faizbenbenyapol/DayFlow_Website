/* =====================================================
   stocks.js — Stock Portfolio Tracker
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
let stkAutoRefreshed = false;

const THAI_MONTHS_SHORT_STK = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
                                'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

document.addEventListener('DOMContentLoaded', async function () {
    initStockTabs();
    initMainTabs();
    initDropzone();
    const year = document.getElementById('stkChartYear')?.value;
    await Promise.all([
        loadStockPortfolio(),
        loadStockWatchlists(),
        loadStockTransactions(),
        loadStockChart(year),
        loadCapitalFlows(),
        loadScreenshots(),
    ]);
    // Auto-refresh prices if oldest fetch >15 min ago, or if any holding lacks price
    maybeAutoRefresh();
});

function initStockTabs() {
    document.querySelectorAll('[data-stk-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.stkTab;
            document.querySelectorAll('[data-stk-tab]').forEach(b => b.classList.toggle('active', b === btn));
            document.querySelectorAll('[data-stk-panel]').forEach(p => {
                const on = p.dataset.stkPanel === tab;
                p.style.display = on ? '' : 'none';
                p.classList.toggle('active', on);
            });
            // Filters only for transactions tab
            const filters = document.getElementById('stkTxnFilters');
            if (filters) filters.style.display = tab === 'transactions' ? '' : 'none';
        });
    });
}

function initMainTabs() {
    document.querySelectorAll('[data-main-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-main-tab]').forEach(b => b.classList.toggle('active', b === btn));
            renderUnifiedStocks();
        });
    });
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
    }
}

function renderStockSummary() {
    const t = stockTotals;
    const mv = document.getElementById('stkMarketValue');
    const cb = document.getElementById('stkCostBasis');
    const ur = document.getElementById('stkUnrealized');
    const urPct = document.getElementById('stkUnrealizedPct');
    const rl = document.getElementById('stkRealized');

    if (mv) mv.textContent = formatMoney(t.market_value || 0);
    if (cb) cb.textContent = formatMoney(t.cost_basis   || 0);

    if (ur) {
        ur.textContent = (t.unrealized_pl >= 0 ? '+' : '') + formatMoney(t.unrealized_pl || 0);
        ur.className = 'stk-stat-amount ' + ((t.unrealized_pl || 0) >= 0 ? 'pl-pos' : 'pl-neg');
    }
    if (urPct) {
        const cost = t.cost_basis || 0;
        if (cost > 0) {
            const pct = ((t.unrealized_pl || 0) / cost) * 100;
            urPct.textContent = (pct >= 0 ? '+' : '') + pct.toFixed(2) + '%';
            urPct.className = 'stk-stat-sub ' + (pct >= 0 ? 'pl-pos' : 'pl-neg');
        } else {
            urPct.textContent = '';
        }
    }

    if (rl) {
        rl.textContent = (t.realized_pl >= 0 ? '+' : '') + formatMoney(t.realized_pl || 0);
        rl.className = 'stk-stat-amount ' + ((t.realized_pl || 0) >= 0 ? 'pl-pos' : 'pl-neg');
    }

    // Render capital stats
    const cap = t.capital || {};
    
    const capNetTHB = document.getElementById('capNetTHB');
    const capCashTHB = document.getElementById('capCashTHB');
    const capNetUSD = document.getElementById('capNetUSD');
    const capCashUSD = document.getElementById('capCashUSD');

    const sideNetTHB = document.getElementById('sideNetTHB');
    const sideCashTHB = document.getElementById('sideCashTHB');

    if (capNetTHB) {
        const netTHB = cap.THB ? cap.THB.net_capital : 0;
        capNetTHB.textContent = formatMoney(netTHB) + ' THB';
    }
    if (capCashTHB) {
        const cashTHB = cap.THB ? cap.THB.cash_balance : 0;
        capCashTHB.textContent = formatMoney(cashTHB) + ' THB';
        capCashTHB.className = 'stk-cap-val ' + (cashTHB >= 0 ? 'stk-day-pos' : 'stk-day-neg');
    }
    if (sideNetTHB) {
        const netTHB = cap.THB ? cap.THB.net_capital : 0;
        sideNetTHB.textContent = formatMoney(netTHB) + ' THB';
    }
    if (sideCashTHB) {
        const cashTHB = cap.THB ? cap.THB.cash_balance : 0;
        sideCashTHB.textContent = formatMoney(cashTHB) + ' THB';
        sideCashTHB.className = 'stk-sidebar-value ' + (cashTHB >= 0 ? 'stk-day-pos' : 'stk-day-neg');
    }

    if (capNetUSD) {
        const netUSD = cap.USD ? cap.USD.net_capital : 0;
        capNetUSD.textContent = '$' + formatMoney(netUSD) + ' USD';
    }
    if (capCashUSD) {
        const cashUSD = cap.USD ? cap.USD.cash_balance : 0;
        capCashUSD.textContent = '$' + formatMoney(cashUSD) + ' USD';
        capCashUSD.className = 'stk-cap-val ' + (cashUSD >= 0 ? 'stk-day-pos' : 'stk-day-neg');
    }

    // Refreshed-at label
    const label = document.getElementById('stkRefreshedAt');
    if (label) {
        const latest = stockHoldings
            .map(h => h.fetched_at)
            .filter(Boolean)
            .sort()
            .pop();
        label.textContent = latest ? ('ล่าสุด ' + formatDateTime(latest)) : '';
    }
}

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
    
    // Optimistic UI update
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
        toast('บันทึก Watchlist ไม่สำเร็จ', 'danger');
        loadStockWatchlists(); // revert
    }
}

function analyzeStockInstantly(ticker, market) {
    const tInput = document.getElementById('stkAnalyzeTicker');
    const mSelect = document.getElementById('stkAnalyzeMarket');
    if (tInput) tInput.value = ticker;
    if (mSelect) mSelect.value = market;
    
    // Switch to Analysis tab
    const tabBtn = document.querySelector('[data-stk-tab="analysis"]');
    if (tabBtn) tabBtn.click();
    
    // Scroll to analysis section smoothly
    const section = document.querySelector('.stk-panel[data-stk-panel="analysis"]');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    
    // Trigger run
    runStockAnalysis();
}

function renderUnifiedStocks() {
    const tbody = document.getElementById('stkUnifiedList');
    if (!tbody) return;

    const activeTab = document.querySelector('.stk-main-tab.active')?.dataset.mainTab || 'portfolio';
    
    // Decide columns visibility
    const isPortfolio = activeTab === 'portfolio';
    const cols = document.querySelectorAll('.stk-col-portfolio');
    cols.forEach(c => c.style.display = isPortfolio ? '' : 'none');
    
    // Gather distinct tickers to display
    let displayList = [];
    
    // Helper to merge data for a ticker
    const getMergedData = (ticker, marketObj = null) => {
        const holding = stockHoldings.find(h => h.ticker === ticker);
        const wl = stockWatchlists.find(w => w.ticker === ticker);
        const market = holding ? holding.market : (wl ? wl.market : (marketObj ? marketObj.market : 'US'));
        return {
            ticker,
            market,
            holding,
            wl,
            isWl: !!wl
        };
    };

    if (activeTab === 'portfolio') {
        displayList = stockHoldings.map(h => getMergedData(h.ticker, h));
    } else if (activeTab === 'watchlists') {
        displayList = stockWatchlists.map(w => getMergedData(w.ticker, w));
    } else {
        // all, US, SET, OTHER
        const allTickers = new Set();
        stockHoldings.forEach(h => allTickers.add(h.ticker));
        stockWatchlists.forEach(w => allTickers.add(w.ticker));
        
        const arr = Array.from(allTickers).map(t => getMergedData(t));
        if (activeTab === 'all') {
            displayList = arr;
        } else {
            displayList = arr.filter(d => d.market === activeTab);
        }
    }

    // Sort: Portfolio uses market_value desc. Others use ticker asc.
    if (activeTab === 'portfolio') {
        // Already sorted by portfolio api, but let's re-sort in case
        displayList.sort((a, b) => {
            const vA = a.holding ? a.holding.market_value : 0;
            const vB = b.holding ? b.holding.market_value : 0;
            return vB - vA;
        });
    } else {
        displayList.sort((a, b) => a.ticker.localeCompare(b.ticker));
    }

    if (!displayList.length) {
        const colCount = isPortfolio ? 14 : 10;
        tbody.innerHTML = '<tr><td colspan="'+colCount+'" class="text-center text-muted" style="padding:2rem">ไม่มีรายการในหมวดนี้</td></tr>';
        return;
    }

    const formatMetric = (val) => {
        if (val == null || val === '') return '<span class="text-muted">—</span>';
        const num = parseFloat(val);
        if (isNaN(num)) return '<span class="text-muted">—</span>';
        return num.toFixed(2);
    };

    tbody.innerHTML = displayList.map(item => {
        const h = item.holding || {};
        const wl = item.wl || {};
        
        // Find price/change from holding or watchlist
        const lastPrice = h.last_price ?? wl.last_price;
        const dayChange = h.day_change ?? wl.day_change;
        const dayChangePct = h.day_change_pct ?? wl.day_change_pct;
        const currency = h.currency || (item.market === 'SET' ? 'THB' : 'USD');
        
        const peRatio = h.pe_ratio ?? wl.pe_ratio;
        const forwardPe = h.forward_pe ?? wl.forward_pe;
        const pegRatio = h.peg_ratio ?? wl.peg_ratio;
        const pFcfRatio = h.p_fcf_ratio ?? wl.p_fcf_ratio;
        const eps = h.eps ?? wl.eps;

        // Pl for portfolio
        const pl = h.unrealized_pl;
        const plPct = h.unrealized_pct;
        const plClass = pl == null ? 'flat' : (pl >= 0 ? 'pos' : 'neg');
        const plText = pl == null ? '—' :
            (pl >= 0 ? '+' : '') + formatMoney(pl) +
            (plPct != null ? ' · ' + (plPct >= 0 ? '+' : '') + plPct.toFixed(2) + '%' : '');

        const dayClass = dayChange == null ? 'stk-day-flat' : (dayChange >= 0 ? 'stk-day-pos' : 'stk-day-neg');
        const dayText = dayChange == null ? '—' :
            (dayChange >= 0 ? '+' : '') + formatMoney(dayChange) +
            (dayChangePct != null ? ' (' + (dayChangePct >= 0 ? '+' : '') + dayChangePct.toFixed(2) + '%)' : '');
            
        // Watchlist star
        const starColor = item.isWl ? 'var(--color-warning)' : 'var(--color-border)';
        const starFill = item.isWl ? 'var(--color-warning)' : 'none';
        
        const aiBtn = `<button class="btn btn-ai-sparkle btn-sm" onclick="analyzeStockInstantly('${item.ticker}','${item.market}')" title="วิเคราะห์ด้วย AI" style="padding:0.25rem 0.5rem">
                <svg class="sparkle-svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:2px"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C8.57 12.05 8 10.61 8 9c0-2.21 1.79-4 4-4s4 1.79 4 4c0 1.61-.57 3.05-2.15 4.1z"/></svg> วิเคราะห์
            </button>`;

        let html = `<tr>
            <td style="text-align:center">
                <svg onclick="toggleWatchlist('${item.ticker}', '${item.market}')" style="cursor:pointer;color:${starColor};fill:${starFill};transition:all 0.2s" width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </td>
            <td>
                <span class="stk-ticker">${escHtmlStk(item.ticker)}</span>
                <span class="stk-market-badge">${escHtmlStk(item.market)}</span>
            </td>
            <td class="stk-num">${lastPrice == null ? '<span class="text-muted">—</span>' : formatMoney(lastPrice)}</td>
            <td class="stk-num ${dayClass}">${dayText}</td>
            <td class="stk-num">${formatMetric(peRatio)}</td>
            <td class="stk-num">${formatMetric(forwardPe)}</td>
            <td class="stk-num">${formatMetric(pegRatio)}</td>
            <td class="stk-num">${formatMetric(pFcfRatio)}</td>
            <td class="stk-num">${formatMetric(eps)}</td>`;
            
        if (isPortfolio) {
            html += `
            <td class="stk-num" style="${!h.shares ? 'opacity:0.3' : ''}">${fmtShares(h.shares)}</td>
            <td class="stk-num" style="${!h.avg_cost ? 'opacity:0.3' : ''}">${h.avg_cost == null ? '—' : (formatMoney(h.avg_cost) + ' ' + currency)}</td>
            <td class="stk-num" style="${!h.market_value ? 'opacity:0.3' : ''}">${h.market_value == null ? '—' : formatMoney(h.market_value)}</td>
            <td style="text-align:right;${!h.shares ? 'opacity:0.3' : ''}"><span class="stk-pl-pill ${plClass}">${plText}</span></td>`;
        }
        
        html += `<td style="text-align:center">${aiBtn}</td></tr>`;
        return html;
    }).join('');
}

function showMetricExplain(metric) {
    let title = '';
    let html = '';
    
    switch(metric) {
        case 'pe':
            title = 'P/E Ratio (Price-to-Earnings)';
            html = `
                <div class="stk-metric-desc" style="text-align: left; font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin-bottom: 12px; font-weight: 500;"><strong>ความหมาย:</strong> อัตราส่วนราคาหุ้นเปรียบเทียบกับกำไรสุทธิต่อหุ้น (EPS) ของบริษัทในรอบ 12 เดือนที่ผ่านมา (Trailing 12 Months)</p>
                    <p style="margin-bottom: 12px;"><strong>สูตรคำนวณ:</strong> ราคาหุ้นปัจจุบัน / กำไรสุทธิต่อหุ้น (EPS)</p>
                    <div style="background: var(--color-surface-2, rgba(0,0,0,0.03)); border-left: 4px solid var(--color-accent); padding: 10px 12px; border-radius: 4px; margin-top: 14px;">
                        <strong>การวิเคราะห์:</strong>
                        <ul style="margin-top: 6px; padding-left: 20px; list-style-type: disc;">
                            <li>ค่าที่ต่ำกว่ามักสะท้อนว่าหุ้นราคาถูก หรือบริษัทอาจมีอัตราการเติบโตต่ำ</li>
                            <li>ค่าที่สูงสะท้อนว่านักลงทุนยอมจ่ายแพงขึ้นเพราะคาดหวังว่าบริษัทจะมีกำไรเติบโตสูงในอนาคต</li>
                            <li>ควรเปรียบเทียบกับคู่แข่งในอุตสาหกรรมเดียวกันเพื่อความถูกต้อง</li>
                        </ul>
                    </div>
                </div>
            `;
            break;
        case 'forward_pe':
            title = 'Forward P/E Ratio';
            html = `
                <div class="stk-metric-desc" style="text-align: left; font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin-bottom: 12px; font-weight: 500;"><strong>ความหมาย:</strong> อัตราส่วนราคาหุ้นเปรียบเทียบกับกำไรสุทธิต่อหุ้น (EPS) คาดการณ์ในอนาคต (โดยทั่วไปคือ 12 เดือนข้างหน้า)</p>
                    <p style="margin-bottom: 12px;"><strong>สูตรคำนวณ:</strong> ราคาหุ้นปัจจุบัน / กำไรสุทธิต่อหุ้นคาดการณ์ (Estimated EPS)</p>
                    <div style="background: var(--color-surface-2, rgba(0,0,0,0.03)); border-left: 4px solid var(--color-accent); padding: 10px 12px; border-radius: 4px; margin-top: 14px;">
                        <strong>การวิเคราะห์:</strong>
                        <ul style="margin-top: 6px; padding-left: 20px; list-style-type: disc;">
                            <li>ช่วยให้นักลงทุนประเมินมูลค่าหุ้นจากโอกาสการเติบโตจริงในอนาคต แทนที่จะอิงจากข้อมูลกำไรในอดีต</li>
                            <li>หาก Forward P/E ต่ำกว่า P/E ปัจจุบัน แสดงว่านักวิเคราะห์คาดว่าบริษัทจะมีกำไรเติบโตขึ้น</li>
                            <li>ความแม่นยำขึ้นอยู่กับความสมเหตุสมผลของประมาณการกำไรจากฝ่ายบริหารและนักวิเคราะห์</li>
                        </ul>
                    </div>
                </div>
            `;
            break;
        case 'peg':
            title = 'PEG Ratio (PE-to-Growth)';
            html = `
                <div class="stk-metric-desc" style="text-align: left; font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin-bottom: 12px; font-weight: 500;"><strong>ความหมาย:</strong> อัตราส่วนที่นำค่า P/E มาปรับอัตราการเติบโตของกำไรสุทธิของบริษัท เพื่อหาความคุ้มค่าของราคาเมื่อเทียบกับการเติบโต</p>
                    <p style="margin-bottom: 12px;"><strong>สูตรคำนวณ:</strong> P/E Ratio / อัตราการเติบโตของกำไรสุทธิ (Earnings Growth Rate %)</p>
                    <div style="background: var(--color-surface-2, rgba(0,0,0,0.03)); border-left: 4px solid var(--color-accent); padding: 10px 12px; border-radius: 4px; margin-top: 14px;">
                        <strong>เกณฑ์การวัดมูลค่า:</strong>
                        <ul style="margin-top: 6px; padding-left: 20px; list-style-type: disc;">
                            <li><strong>น้อยกว่า 1.00:</strong> หุ้นราคาถูกกว่าอัตราการเติบโต (น่าสนใจลงทุนสูง)</li>
                            <li><strong>เท่ากับ 1.00:</strong> มูลค่าเหมาะสมกับอัตราการเติบโต</li>
                            <li><strong>มากกว่า 1.00:</strong> หุ้นราคาแพงเกินไปเมื่อเทียบกับการเติบโตในปัจจุบัน (อาจต้องระวัง)</li>
                        </ul>
                    </div>
                </div>
            `;
            break;
        case 'p_fcf':
            title = 'P/FCF Ratio (Price-to-Free Cash Flow)';
            html = `
                <div class="stk-metric-desc" style="text-align: left; font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin-bottom: 12px; font-weight: 500;"><strong>ความหมาย:</strong> อัตราส่วนราคาหุ้นเปรียบเทียบกับกระแสเงินสดอิสระต่อหุ้น (Free Cash Flow) ที่กิจการทำได้จริงหลังจากหักเงินลงทุนในสินทรัพย์ถาวรแล้ว</p>
                    <p style="margin-bottom: 12px;"><strong>สูตรคำนวณ:</strong> ราคาหุ้นปัจจุบัน / กระแสเงินสดอิสระต่อหุ้น (FCF per Share)</p>
                    <div style="background: var(--color-surface-2, rgba(0,0,0,0.03)); border-left: 4px solid var(--color-accent); padding: 10px 12px; border-radius: 4px; margin-top: 14px;">
                        <strong>ทำไมจึงสำคัญ:</strong>
                        <ul style="margin-top: 6px; padding-left: 20px; list-style-type: disc;">
                            <li>กระแสเงินสดอิสระบิดเบือนได้ยากกว่ากำไรทางบัญชี เพราะสะท้อนเงินสดจริงในมือที่สามารถจ่ายปันผล ซื้อหุ้นคืน หรือลดหนี้ได้</li>
                            <li>ค่าที่ต่ำสะท้อนความสามารถในการผลิตเงินสดได้ดีเมื่อเทียบกับระดับราคาหุ้นปัจจุบัน</li>
                            <li>เหมาะอย่างยิ่งสำหรับใช้ประเมินบริษัทในกลุ่มพัฒนา/ผลิตที่มีการลงทุนสูงและเริ่มสร้างรายได้สม่ำเสมอ</li>
                        </ul>
                    </div>
                </div>
            `;
            break;
        case 'eps':
            title = 'EPS (Earnings Per Share)';
            html = `
                <div class="stk-metric-desc" style="text-align: left; font-size: 0.95rem; line-height: 1.6;">
                    <p style="margin-bottom: 12px; font-weight: 500;"><strong>ความหมาย:</strong> ส่วนแบ่งกำไรสุทธิของบริษัทที่จัดสรรให้แก่หุ้นสามัญแต่ละหุ้น สะท้อนความสามารถในการทำกำไรขั้นพื้นฐานที่สุด</p>
                    <p style="margin-bottom: 12px;"><strong>สูตรคำนวณ:</strong> (กำไรสุทธิ - ปันผลหุ้นบุริมสิทธิ) / จำนวนหุ้นสามัญทั้งหมดที่ถือครองโดยนักลงทุน</p>
                    <div style="background: var(--color-surface-2, rgba(0,0,0,0.03)); border-left: 4px solid var(--color-accent); padding: 10px 12px; border-radius: 4px; margin-top: 14px;">
                        <strong>การประเมิน:</strong>
                        <ul style="margin-top: 6px; padding-left: 20px; list-style-type: disc;">
                            <li>เป็นตัวแปรสำคัญที่สุดในการใช้คำนวณ P/E Ratio และการประเมินมูลค่าหุ้นส่วนใหญ่</li>
                            <li>บริษัทที่ดีควรมี EPS เติบโตอย่างมั่นคงและต่อเนื่องในทุกๆ ปี</li>
                            <li>การเพิ่มขึ้นของ EPS จากผลประกอบการจริงสะท้อนความเติบโตทางธุรกิจที่แท้จริง แตกต่างจากการเพิ่มขึ้นเพราะการลดจำนวนหุ้นลง</li>
                        </ul>
                    </div>
                </div>
            `;
            break;
    }

    if (window.Swal) {
        Swal.fire({
            title: title,
            html: html,
            confirmButtonText: 'ปิดคำอธิบาย',
            confirmButtonColor: '#8b5cf6',
            customClass: {
                popup: 'stk-premium-swal-popup',
                title: 'stk-premium-swal-title',
                confirmButton: 'stk-premium-swal-btn'
            }
        });
    } else {
        alert(title + '\n\n' + html.replace(/<[^>]*>/g, ''));
    }
}

function fmtShares(n) {
    if (n == null) return '—';
    const f = parseFloat(n);
    if (Number.isInteger(f)) return f.toString();
    return f.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}

/* ── Transactions ── */
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
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:2rem">ไม่มีรายการ</td></tr>';
        return;
    }

    tbody.innerHTML = stockTxns.map(t => {
        const value = parseFloat(t.quantity) * parseFloat(t.price);
        const sideBadge = t.side === 'buy'
            ? '<span class="stk-side-buy">ซื้อ</span>'
            : '<span class="stk-side-sell">ขาย</span>';
        return `
            <tr>
                <td class="text-sm">${escHtmlStk(formatDate(t.txn_date))}</td>
                <td>
                    <span class="stk-ticker">${escHtmlStk(t.ticker)}</span>
                    <span class="stk-market-badge">${escHtmlStk(t.market)}</span>
                </td>
                <td>${sideBadge}</td>
                <td class="stk-num">${fmtShares(t.quantity)}</td>
                <td class="stk-num">${formatMoney(t.price)} ${escHtmlStk(t.currency)}</td>
                <td class="stk-num">${formatMoney(t.fee)}</td>
                <td class="stk-num">${formatMoney(value)}</td>
                <td class="mode-readonly-hide">
                    <button class="btn-link" onclick="openEditStock(${t.id})">แก้ไข</button>
                </td>
            </tr>`;
    }).join('');
}

/* ── Chart ── */
async function loadStockChart(year) {
    if (typeof Chart === 'undefined') return;
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/chart?year=' + year);
        const series = data.series || [];

        const labels   = series.map(s => THAI_MONTHS_SHORT_STK[s.month - 1]);
        const costBasis   = series.map(s => s.cost_basis);
        const marketValue = series.map(s => s.market_value);

        const valueCtx = document.getElementById('stkValueChart');
        if (valueCtx) {
            if (stkValueChart) stkValueChart.destroy();
            
            // Create premium gradients
            const ctxVal = valueCtx.getContext('2d');
            
            const gradientCost = ctxVal.createLinearGradient(0, 0, 0, 240);
            gradientCost.addColorStop(0, 'rgba(107, 114, 128, 0.25)');
            gradientCost.addColorStop(1, 'rgba(107, 114, 128, 0.0)');

            const gradientMarket = ctxVal.createLinearGradient(0, 0, 0, 240);
            gradientMarket.addColorStop(0, 'rgba(59, 130, 246, 0.25)');
            gradientMarket.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

            stkValueChart = new Chart(valueCtx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'ต้นทุนสะสม',
                            data: costBasis,
                            borderColor: 'rgba(107, 114, 128, 1)',
                            backgroundColor: gradientCost,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: 'rgba(107, 114, 128, 1)',
                            pointBorderWidth: 2,
                        },
                        {
                            label: 'มูลค่าตลาด (ประมาณ)',
                            data: marketValue,
                            borderColor: 'rgba(59, 130, 246, 1)',
                            backgroundColor: gradientMarket,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: 'rgba(59, 130, 246, 1)',
                            pointBorderWidth: 2,
                        },
                    ],
                },
                options: stkChartOpts(),
            });
        }

        // Per-ticker P&L bar chart from current holdings
        const plCtx = document.getElementById('stkPlChart');
        if (plCtx) {
            if (stkPlChart) stkPlChart.destroy();
            const tickers = stockHoldings.map(h => h.ticker);
            const pls     = stockHoldings.map(h => h.unrealized_pl || 0);
            
            const ctxPl = plCtx.getContext('2d');
            const colors = pls.map(v => {
                const grad = ctxPl.createLinearGradient(0, 0, 0, 240);
                if (v >= 0) {
                    grad.addColorStop(0, 'rgba(34, 197, 94, 0.8)');
                    grad.addColorStop(1, 'rgba(34, 197, 94, 0.15)');
                } else {
                    grad.addColorStop(0, 'rgba(239, 68, 68, 0.8)');
                    grad.addColorStop(1, 'rgba(239, 68, 68, 0.15)');
                }
                return grad;
            });
            const borderColors = pls.map(v => v >= 0 ? 'rgba(34, 197, 94, 1)' : 'rgba(239, 68, 68, 1)');

            stkPlChart = new Chart(plCtx, {
                type: 'bar',
                data: {
                    labels: tickers,
                    datasets: [{
                        label: 'กำไร/ขาดทุน ยังไม่ปิด',
                        data: pls,
                        backgroundColor: colors,
                        borderColor: borderColors,
                        borderWidth: 1.5,
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 45,
                    }],
                },
                options: stkChartOpts(),
            });
        }
    } catch (err) {
        toast(err.message || 'โหลดกราฟไม่สำเร็จ', 'danger');
    }
}

function stkChartOpts() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const style = getComputedStyle(document.documentElement);
    const textColor = style.getPropertyValue('--color-text').trim() || '#1d1d1f';
    const mutedColor = style.getPropertyValue('--color-muted').trim() || '#6e6e73';
    const borderColor = style.getPropertyValue('--color-border').trim() || '#eaeaea';
    
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    font: { family: "'Inter', 'IBM Plex Sans Thai', sans-serif", size: 12, weight: '500' },
                    color: textColor,
                    boxWidth: 10,
                    boxHeight: 10,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            },
            tooltip: {
                backgroundColor: isDark ? '#121212' : '#ffffff',
                titleColor: isDark ? '#f5f5f7' : '#1d1d1f',
                bodyColor: isDark ? '#f5f5f7' : '#1d1d1f',
                borderColor: isDark ? '#2d2d30' : '#eaeaea',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
                boxPadding: 6,
                usePointStyle: true,
                titleFont: { family: "'Inter', 'IBM Plex Sans Thai', sans-serif", size: 12, weight: '600' },
                bodyFont: { family: "'Inter', 'IBM Plex Sans Thai', sans-serif", size: 12 },
                callbacks: {
                    label: (ctx) => ' ' + ctx.dataset.label + ': ' + formatMoney(ctx.parsed.y || ctx.parsed) + ' บาท'
                }
            }
        },
        scales: {
            x: {
                ticks: { 
                    font: { family: "'Inter', 'IBM Plex Sans Thai', sans-serif", size: 11 },
                    color: mutedColor
                },
                grid: { display: false }
            },
            y: {
                ticks: {
                    font: { family: "'Inter', 'IBM Plex Sans Thai', sans-serif", size: 11 },
                    color: mutedColor,
                    callback: v => formatMoney(v)
                },
                grid: { 
                    color: borderColor,
                    drawBorder: false,
                    borderDash: [4, 4]
                }
            }
        }
    };
}

/* ── Refresh prices ── */
async function refreshPrices(silent = false) {
    const btn = document.getElementById('stkRefreshBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="btn-spinner"></span>กำลังดึงราคา...'; }
    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/refresh', {
            method: 'POST',
            body: JSON.stringify({})
        });
        await loadStockPortfolio();
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);

        if (!silent) {
            const errs = Object.keys(res.errors || {});
            const ok = (res.updated || []).length;
            const sk = (res.skipped || []).length;
            let msg = 'อัปเดต ' + ok + ' · ข้าม ' + sk + ' (อัปเดตใหม่ได้ในอีก 5 นาที)';
            if (errs.length) msg += ' · พลาด ' + errs.length;
            toast(msg, errs.length ? 'warning' : 'success');
        }
    } catch (err) {
        if (!silent) toast(err.message || 'รีเฟรชไม่สำเร็จ', 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;vertical-align:text-bottom"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v6h6"/></svg>รีเฟรชราคา'; }
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

/* ── Modal ── */
function openAddStock() {
    editingStockId = null;
    document.getElementById('stockModalTitle').textContent = 'บันทึกรายการหุ้น';
    document.getElementById('editStockId').value = '';
    document.getElementById('stkTicker').value   = '';
    document.getElementById('stkMarket').value   = 'US';
    document.getElementById('stkSide').value     = 'buy';
    document.getElementById('stkCurrency').value = 'USD';
    document.getElementById('stkQty').value      = '';
    document.getElementById('stkPrice').value    = '';
    document.getElementById('stkFee').value      = 0;
    document.getElementById('stkDate').value     = todayISO();
    document.getElementById('stkNotes').value    = '';
    document.getElementById('deleteStockBtn').style.display = 'none';
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
    document.getElementById('stkSide').value     = t.side;
    document.getElementById('stkCurrency').value = t.currency;
    document.getElementById('stkQty').value      = t.quantity;
    document.getElementById('stkPrice').value    = t.price;
    document.getElementById('stkFee').value      = t.fee;
    document.getElementById('stkDate').value     = t.txn_date;
    document.getElementById('stkNotes').value    = t.notes || '';
    document.getElementById('deleteStockBtn').style.display = '';
    openModal('stockModal');
}

function onStkMarketChange() {
    const m = document.getElementById('stkMarket').value;
    const cur = document.getElementById('stkCurrency');
    if (!cur.value || ['USD','THB'].includes(cur.value)) {
        cur.value = m === 'SET' ? 'THB' : (m === 'US' ? 'USD' : cur.value || 'USD');
    }
}

async function saveStock() {
    const body = {
        ticker:   document.getElementById('stkTicker').value.trim().toUpperCase(),
        market:   document.getElementById('stkMarket').value,
        side:     document.getElementById('stkSide').value,
        quantity: document.getElementById('stkQty').value,
        price:    document.getElementById('stkPrice').value,
        fee:      document.getElementById('stkFee').value || 0,
        currency: document.getElementById('stkCurrency').value.trim().toUpperCase(),
        txn_date: document.getElementById('stkDate').value,
        notes:    document.getElementById('stkNotes').value,
    };

    if (!body.ticker || !/^[A-Z0-9.\-]{1,20}$/.test(body.ticker)) {
        toast('Ticker ไม่ถูกต้อง', 'danger'); return;
    }
    if (!body.quantity || parseFloat(body.quantity) <= 0) {
        toast('กรุณากรอกจำนวน', 'danger'); return;
    }
    if (body.price === '' || parseFloat(body.price) < 0) {
        toast('ราคาไม่ถูกต้อง', 'danger'); return;
    }
    if (!body.txn_date) { toast('กรุณาเลือกวันที่', 'danger'); return; }
    if (!/^[A-Z]{3}$/.test(body.currency)) { toast('สกุลเงินไม่ถูกต้อง', 'danger'); return; }

    try {
        const url = editingStockId
            ? BASE_URL + '/api/stocks/' + editingStockId
            : BASE_URL + '/api/stocks';
        const method = editingStockId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('stockModal');
        await Promise.all([loadStockPortfolio(), loadStockTransactions()]);
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteStock() {
    if (!editingStockId) return;
    if (!await confirmAction('ต้องการลบรายการนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/' + editingStockId, { method: 'DELETE' });
        closeModal('stockModal');
        await Promise.all([loadStockPortfolio(), loadStockTransactions()]);
        const year = document.getElementById('stkChartYear')?.value;
        if (year) await loadStockChart(year);
        toast('ลบแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

function escHtmlStk(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── AI Stock Analysis ── */
let stkAnalyzeTextInterval = null;

async function runStockAnalysis() {
    const tickerInput = document.getElementById('stkAnalyzeTicker');
    const marketSelect = document.getElementById('stkAnalyzeMarket');
    const btn = document.getElementById('stkAnalyzeBtn');
    const loader = document.getElementById('stkAnalyzeLoading');
    const loaderText = document.getElementById('stkAnalyzeLoadingText');
    const resultBox = document.getElementById('stkAnalyzeResult');

    if (!tickerInput) return;

    const ticker = tickerInput.value.trim().toUpperCase();
    const market = marketSelect ? marketSelect.value : 'US';

    if (!ticker) {
        toast('กรุณากรอกสัญลักษณ์หุ้น (Ticker)', 'danger');
        return;
    }

    // Prepare UI states
    if (btn) btn.disabled = true;
    if (resultBox) { resultBox.innerHTML = ''; resultBox.style.display = 'none'; }
    if (loader) loader.style.display = 'flex';

    // Simulated high-end analysis scanning texts
    const loadingTexts = [
        'กำลังสืบค้นราคาตลาดล่าสุด...',
        'กำลังดึงพารามิเตอร์ของระบบวิเคราะห์...',
        'กำลังคำนวณแนวรับและแนวต้านสำคัญ...',
        'กำลังรวบรวมอัตราส่วนทางการเงิน P/E และ P/B...',
        'ขุมพลัง AI กำลังประเมินผลและสรุปความเห็นการลงทุนเชิงลึก...',
        'กำลังตรวจสอบความเสี่ยงและโอกาสที่เป็นไปได้...',
        'กำลังจัดเตรียมรายงานบทวิเคราะห์ระดับพรีเมียม...'
    ];
    let step = 0;
    if (loaderText) loaderText.textContent = loadingTexts[0];
    
    if (stkAnalyzeTextInterval) clearInterval(stkAnalyzeTextInterval);
    stkAnalyzeTextInterval = setInterval(() => {
        step = (step + 1) % loadingTexts.length;
        if (loaderText) loaderText.textContent = loadingTexts[step];
    }, 2500);

    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/analyze', {
            method: 'POST',
            body: JSON.stringify({ ticker, market })
        });
        
        if (stkAnalyzeTextInterval) clearInterval(stkAnalyzeTextInterval);
        if (loader) loader.style.display = 'none';
        
        if (res && res.result) {
            renderStockAnalysisResult(res);
            toast('วิเคราะห์หุ้น ' + ticker + ' สำเร็จแล้วด้วย AI', 'success');
        } else {
            throw new Error('โครงสร้างข้อมูลไม่ถูกต้อง');
        }
    } catch (err) {
        if (stkAnalyzeTextInterval) clearInterval(stkAnalyzeTextInterval);
        if (loader) loader.style.display = 'none';
        
        const errorMsg = err.message || 'เกิดข้อผิดพลาดในการวิเคราะห์หุ้น';
        if (errorMsg.includes('ตั้งค่า API Key') || errorMsg.includes('วิเคราะห์หุ้นด้วย AI')) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'ยังไม่ได้ตั้งค่า AI API Key',
                    text: 'กรุณาตั้งค่า API Key สำหรับ AI (แนะนำ Google Gemini ซึ่งเปิดใช้ฟรีได้ที่ Google AI Studio) ในส่วน "API สำหรับการวิเคราะห์หุ้นด้วย AI" ก่อนเริ่มต้นใช้งาน',
                    showCancelButton: true,
                    confirmButtonText: 'ไปหน้าตั้งค่าทันที',
                    cancelButtonText: 'ไว้ทีหลัง',
                    confirmButtonColor: '#8b5cf6',
                }).then((result) => {
                    if (result.isConfirmed) {
                        try { localStorage.setItem('settings_last_tab', 'stock-api'); } catch (_) {}
                        window.location.href = BASE_URL + '/settings';
                    }
                });
            } else {
                toast(errorMsg, 'danger');
            }
        } else {
            toast(errorMsg, 'danger');
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}

function renderStockAnalysisResult(data) {
    const r = data.result;
    const container = document.getElementById('stkAnalyzeResult');
    if (!container) return;

    // Determine recommendation styles
    let recColor = 'wait';
    let recIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:8px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    if (r.recommendation === 'BUY') {
        recColor = 'buy';
        recIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:8px"><polyline points="20 6 9 17 4 12"/></svg>';
    } else if (r.recommendation === 'HOLD') {
        recColor = 'hold';
        recIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:8px"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';
    }

    const provider = data.provider || '';
    let providerLabel = '';
    if (provider === 'gemini')         providerLabel = 'Google Gemini';
    else if (provider === 'openai')    providerLabel = 'OpenAI';
    else if (provider === 'anthropic') providerLabel = 'Anthropic Claude';
    else if (provider === 'kimi')      providerLabel = 'Moonshot Kimi';
    else if (provider === 'openrouter') providerLabel = 'OpenRouter.ai';

    const providerHtml = providerLabel ? ` · <span class="stk-rec-provider" style="color:var(--color-accent);font-weight:700">วิเคราะห์โดย ${providerLabel}</span>` : '';

    const oppsHtml = (r.opportunities || []).map(o => `<li><span class="stk-bullet-success">✦</span> ${escHtmlStk(o)}</li>`).join('');
    const risksHtml = (r.risks || []).map(rk => `<li><span class="stk-bullet-danger">⚠</span> ${escHtmlStk(rk)}</li>`).join('');

    container.innerHTML = `
        <div class="stk-analysis-dashboard">
            <!-- Top Row: Balanced Summary Dashboard -->
            <div class="stk-dash-row" style="grid-template-columns: 1.2fr 1fr;">
                <!-- 1. Recommendation Card -->
                <div class="stk-rec-card card-glow-${recColor}">
                    <div class="stk-rec-header">ผลประเมินและคำแนะนำ${providerHtml}</div>
                    <div class="stk-rec-pill stk-rec-${recColor}">
                        ${recIcon}
                        <span>${escHtmlStk(r.recommendation_label || r.recommendation)}</span>
                    </div>
                    <div class="stk-rec-summary">${escHtmlStk(r.summary)}</div>
                </div>

                <!-- 2. Strategic Targets -->
                <div class="stk-stats-box">
                    <h4 class="stk-box-title">กรอบราคากลยุทธ์</h4>
                    <div class="stk-price-targets">
                        <div class="stk-target-item">
                            <span class="stk-target-lbl">ราคาอ้างอิง</span>
                            <span class="stk-target-val">${escHtmlStk(r.current_price || '—')}</span>
                        </div>
                        <div class="stk-target-item">
                            <span class="stk-target-lbl" style="color:var(--color-success)">เป้าหมายทำกำไร</span>
                            <span class="stk-target-val txt-pos" style="font-weight:700">${escHtmlStk(r.target_price || '—')}</span>
                        </div>
                        <div class="stk-target-item">
                            <span class="stk-target-lbl" style="color:var(--color-danger)">จุดตัดขาดทุน (SL)</span>
                            <span class="stk-target-val txt-neg" style="font-weight:700">${escHtmlStk(r.stop_loss || '—')}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Row: 2 Balanced Columns for Details -->
            <div class="stk-details-grid">
                <!-- Lower Left: Fundamental Analysis + Opportunities & Risks + Multi-level S&R Widget -->
                <div class="stk-details-col">
                    <div class="stk-detailed-box">
                        <h4 class="stk-box-title"><span class="stk-title-decor bg-pos"></span>วิเคราะห์ปัจจัยพื้นฐาน (Fundamental Analysis)</h4>
                        <p class="stk-analysis-text">${escHtmlStk(r.fundamental_analysis).replace(/\n/g, '<br>')}</p>
                    </div>
                    
                    <div class="stk-opps-risks-vertical">
                        <div class="stk-opps-box">
                            <h4 class="stk-box-title opps-title">◈ โอกาสเชิงบวก (Opportunities)</h4>
                            <ul class="stk-bullet-list">
                                ${oppsHtml || '<li>ไม่มีข้อมูล</li>'}
                            </ul>
                        </div>
                        <div class="stk-risks-box">
                            <h4 class="stk-box-title risks-title">⚠ ปัจจัยความเสี่ยง (Key Risks)</h4>
                            <ul class="stk-bullet-list">
                                ${risksHtml || '<li>ไม่มีข้อมูล</li>'}
                            </ul>
                        </div>
                    </div>

                    <!-- 3. Technical Levels (Multi-level Support/Resistance Gauge) -->
                    <div class="stk-stats-box">
                        <h4 class="stk-box-title">แนวรับ - แนวต้านสำคัญ</h4>
                        <div class="stk-levels-container">
                            <div class="stk-level-group support">
                                <span class="stk-level-title">แนวรับสำคัญ (Support Levels)</span>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวรับที่ 1 (S1)</span>
                                    <span class="val text-pos font-semibold">${escHtmlStk(r.support_1 || '—')}</span>
                                </div>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวรับที่ 2 (S2)</span>
                                    <span class="val text-pos" style="opacity:0.8">${escHtmlStk(r.support_2 || '—')}</span>
                                </div>
                            </div>
                            <div class="stk-level-group resistance">
                                <span class="stk-level-title">แนวต้านสำคัญ (Resistance Levels)</span>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวต้านที่ 1 (R1)</span>
                                    <span class="val text-neg font-semibold">${escHtmlStk(r.resistance_1 || '—')}</span>
                                </div>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวต้านที่ 2 (R2)</span>
                                    <span class="val text-neg" style="opacity:0.8">${escHtmlStk(r.resistance_2 || '—')}</span>
                                </div>
                            </div>
                        </div>
                        <div class="stk-levels-meter" style="margin-top: var(--space-4)">
                            <div class="stk-level-range">
                                <span>แนวรับ (S1): <strong>${escHtmlStk(r.support_1 || '—')}</strong></span>
                                <span>แนวต้าน (R1): <strong>${escHtmlStk(r.resistance_1 || '—')}</strong></span>
                            </div>
                            <div class="stk-level-bar-container">
                                <div class="stk-level-bar-fill fill-${recColor}"></div>
                                <div class="stk-level-bar-indicator"></div>
                            </div>
                            <div class="text-xs text-muted text-center" style="margin-top:12px">เปรียบเทียบราคาปัจจุบันกับแนวรับ-แนวต้านหลัก</div>
                        </div>
                    </div>
                </div>

                <!-- Lower Right: Technical Analysis + Key Ratios & Valuation -->
                <div class="stk-details-col">
                    <div class="stk-detailed-box">
                        <h4 class="stk-box-title"><span class="stk-title-decor bg-accent"></span>วิเคราะห์เชิงเทคนิค (Technical Analysis)</h4>
                        <p class="stk-analysis-text">${escHtmlStk(r.technical_analysis).replace(/\n/g, '<br>')}</p>
                    </div>

                    <div class="stk-stats-box">
                        <h4 class="stk-box-title font-semibold">งบการเงินและอัตราส่วนหลัก</h4>
                        <table class="stk-ratios-table">
                            <tr>
                                <td>ชื่อกิจการ</td>
                                <td class="text-right"><strong>${escHtmlStk(r.name || '—')}</strong></td>
                            </tr>
                            <tr>
                                <td>เทรนด์หลัก (Trend)</td>
                                <td class="text-right"><span class="stk-trend-badge">${escHtmlStk(r.trend || '—')}</span></td>
                            </tr>
                            <tr>
                                <td>รายได้รวมล่าสุด</td>
                                <td class="text-right font-semibold">${escHtmlStk(r.revenue || '—')}</td>
                            </tr>
                            <tr>
                                <td>กำไรสุทธิ (Net Profit)</td>
                                <td class="text-right font-semibold text-pos">${escHtmlStk(r.net_profit || '—')}</td>
                            </tr>
                            <tr>
                                <td>กำไรต่อหุ้น (EPS)</td>
                                <td class="text-right font-mono">${escHtmlStk(r.eps || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน P/E Ratio</td>
                                <td class="text-right font-mono">${escHtmlStk(r.pe || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน P/B Ratio</td>
                                <td class="text-right font-mono">${escHtmlStk(r.pb || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน ROE</td>
                                <td class="text-right font-mono">${escHtmlStk(r.roe || '—')}</td>
                            </tr>
                            <tr>
                                <td>หนี้สินต่อทุน (D/E)</td>
                                <td class="text-right font-mono">${escHtmlStk(r.de_ratio || '—')}</td>
                            </tr>
                            <tr>
                                <td>กระแสเงินสดอิสระ (FCF)</td>
                                <td class="text-right font-semibold">${escHtmlStk(r.free_cash_flow || '—')}</td>
                            </tr>
                            <tr>
                                <td>ปันผล (Dividend Yield)</td>
                                <td class="text-right font-mono">${escHtmlStk(r.dividend_yield || '—')}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;

    container.style.display = 'block';
    
    // Add micro-animation scroll into view
    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ============================================================
   CAPITAL FLOWS
   ============================================================ */

async function loadCapitalFlows() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/capital');
        stockCapitalFlows = data.flows || [];
        renderCapitalList();
    } catch (err) {
        toast(err.message || 'โหลดข้อมูลเงินลงทุนไม่สำเร็จ', 'danger');
    }
}

function renderCapitalList() {
    const tbody = document.getElementById('stkCapitalList');
    if (!tbody) return;

    if (!stockCapitalFlows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:2rem">ไม่มีรายการเงินลงทุน</td></tr>';
        return;
    }

    tbody.innerHTML = stockCapitalFlows.map(f => {
        const typeBadge = f.flow_type === 'deposit'
            ? '<span class="stk-side-buy">เติมเงิน</span>'
            : '<span class="stk-side-sell">ถอนเงิน</span>';
        
        return `
            <tr>
                <td class="text-sm">${escHtmlStk(formatDate(f.flow_date))}</td>
                <td>${typeBadge}</td>
                <td class="stk-num" style="font-weight:700">${formatMoney(f.amount)}</td>
                <td>${escHtmlStk(f.currency)}</td>
                <td class="text-sm">${escHtmlStk(f.notes || '—')}</td>
                <td class="mode-readonly-hide">
                    <button class="btn-link" onclick="openEditCapital(${f.id})">แก้ไข</button>
                </td>
            </tr>`;
    }).join('');
}

function openAddCapital() {
    editingCapitalId = null;
    document.getElementById('capitalModalTitle').textContent = 'บันทึกรายการเงินลงทุน';
    document.getElementById('editCapitalId').value = '';
    document.getElementById('capType').value = 'deposit';
    document.getElementById('capCurrency').value = 'THB';
    document.getElementById('capAmount').value = '';
    document.getElementById('capDate').value = todayISO();
    document.getElementById('capNotes').value = '';
    document.getElementById('deleteCapitalBtn').style.display = 'none';
    openModal('capitalModal');
}

function openEditCapital(id) {
    const f = stockCapitalFlows.find(x => x.id === id);
    if (!f) return;
    editingCapitalId = id;
    document.getElementById('capitalModalTitle').textContent = 'แก้ไขรายการเงินลงทุน';
    document.getElementById('editCapitalId').value = id;
    document.getElementById('capType').value = f.flow_type;
    document.getElementById('capCurrency').value = f.currency;
    document.getElementById('capAmount').value = f.amount;
    document.getElementById('capDate').value = f.flow_date;
    document.getElementById('capNotes').value = f.notes || '';
    document.getElementById('deleteCapitalBtn').style.display = '';
    openModal('capitalModal');
}

async function saveCapital() {
    const body = {
        flow_type: document.getElementById('capType').value,
        currency:  document.getElementById('capCurrency').value,
        amount:    document.getElementById('capAmount').value,
        flow_date: document.getElementById('capDate').value,
        notes:     document.getElementById('capNotes').value,
    };

    if (!body.amount || parseFloat(body.amount) <= 0) {
        toast('กรุณากรอกจำนวนเงินที่ถูกต้อง', 'danger'); return;
    }
    if (!body.flow_date) {
        toast('กรุณาเลือกวันที่', 'danger'); return;
    }

    try {
        const url = editingCapitalId
            ? BASE_URL + '/api/stocks/capital/' + editingCapitalId
            : BASE_URL + '/api/stocks/capital';
        const method = editingCapitalId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('capitalModal');
        await Promise.all([
            loadStockPortfolio(),
            loadCapitalFlows()
        ]);
        toast('บันทึกเรียบร้อยแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteCapital() {
    if (!editingCapitalId) return;
    if (!await confirmAction('ต้องการลบรายการเงินลงทุนนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/capital/' + editingCapitalId, { method: 'DELETE' });
        closeModal('capitalModal');
        await Promise.all([
            loadStockPortfolio(),
            loadCapitalFlows()
        ]);
        toast('ลบเรียบร้อยแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

/* ============================================================
   SCREENSHOTS
   ============================================================ */

async function loadScreenshots() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/screenshots');
        stockScreenshots = data.screenshots || [];
        renderScreenshotsGrid();
    } catch (err) {
        toast(err.message || 'โหลดรูปภาพไม่สำเร็จ', 'danger');
    }
}

function renderScreenshotsGrid() {
    const grid = document.getElementById('stkScreenshotsGrid');
    if (!grid) {
        renderSidebarScreenshot();
        return;
    }

    if (!stockScreenshots.length) {
        grid.innerHTML = '<div class="text-center text-muted" style="grid-column: 1/-1; padding:3rem 1rem;">ไม่มีรูปภาพพอร์ตแนบไว้</div>';
        renderSidebarScreenshot();
        return;
    }

    grid.innerHTML = stockScreenshots.map(s => {
        const dateStr = formatDateTime(s.created_at);
        const imgSrc = BASE_URL + '/uploads/' + s.file_path;
        return `
            <div class="stk-screenshot-card">
                <div class="stk-screenshot-img-wrap" onclick="viewLightbox(${s.id})">
                    <img class="stk-screenshot-img" src="${imgSrc}" alt="${escHtmlStk(s.name)}">
                    <div class="stk-screenshot-overlay">
                        <button class="stk-screenshot-btn btn-del mode-readonly-hide" onclick="event.stopPropagation(); deleteScreenshot(${s.id})" title="ลบรูปภาพ">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        </button>
                    </div>
                </div>
                <div class="stk-screenshot-info">
                    <div class="stk-screenshot-name" title="${escHtmlStk(s.name)}">${escHtmlStk(s.name)}</div>
                    <div class="stk-screenshot-desc">${escHtmlStk(s.description || 'ไม่มีคำอธิบาย')}</div>
                    <div class="stk-screenshot-date">อัปโหลดเมื่อ ${escHtmlStk(dateStr)}</div>
                </div>
            </div>`;
    }).join('');

    renderSidebarScreenshot();
}

function renderSidebarScreenshot() {
    const container = document.getElementById('sideScreenshotContainer');
    if (!container) return;

    if (!stockScreenshots.length) {
        container.innerHTML = '<div class="text-center text-muted py-6">ไม่มีรูปภาพพอร์ตแนบไว้</div>';
        return;
    }

    const s = stockScreenshots[0];
    const imgSrc = BASE_URL + '/uploads/' + s.file_path;
    const dateStr = formatDateTime(s.created_at);

    if (IS_READ_ONLY) {
        container.innerHTML = `
            <div class="stk-share-img-wrap">
                <img class="stk-share-img" src="${imgSrc}" alt="${escHtmlStk(s.name)}">
            </div>
            <div class="mt-4" style="text-align: left;">
                <div class="stk-screenshot-name" style="font-size:0.9rem;" title="${escHtmlStk(s.name)}">${escHtmlStk(s.name)}</div>
                <div class="stk-screenshot-desc" style="font-size:0.8rem; height:auto; margin-bottom:4px; white-space: pre-wrap;">${escHtmlStk(s.description || 'ไม่มีคำอธิบาย')}</div>
                <div class="text-xs text-muted">อัปโหลดเมื่อ ${escHtmlStk(dateStr)}</div>
            </div>
        `;
    } else {
        container.innerHTML = `
            <div class="stk-sidebar-thumb-wrap" onclick="viewLightbox(${s.id})">
                <img class="stk-sidebar-thumb" src="${imgSrc}" alt="${escHtmlStk(s.name)}">
                <div class="stk-sidebar-thumb-overlay">
                    <span>คลิกเพื่อดูรูปภาพขนาดเต็ม</span>
                </div>
            </div>
            <div class="mt-4">
                <div class="stk-screenshot-name" style="font-size:0.85rem;" title="${escHtmlStk(s.name)}">${escHtmlStk(s.name)}</div>
                <div class="stk-screenshot-desc" style="font-size:0.75rem; height:auto; margin-bottom:4px;">${escHtmlStk(s.description || 'ไม่มีคำอธิบาย')}</div>
                <div class="text-xs text-muted">อัปโหลดเมื่อ ${escHtmlStk(dateStr)}</div>
            </div>
        `;
    }
}

async function uploadScreenshot(input) {
    const file = input.files[0];
    if (!file) return;

    let description = '';
    if (window.Swal) {
        const { value: text } = await Swal.fire({
            title: 'คำอธิบายรูปภาพ',
            input: 'text',
            inputLabel: 'กรอกคำอธิบายสำหรับภาพนี้ (ไม่ระบุก็ได้)',
            inputPlaceholder: 'เช่น พอร์ตประจำเดือนมิถุนายน, Dime พอร์ตแรก...',
            showCancelButton: true,
            confirmButtonText: 'อัปโหลด',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#8b5cf6',
        });
        if (text === undefined) {
            input.value = '';
            return;
        }
        description = text || '';
    } else {
        description = prompt('กรอกคำอธิบายสำหรับรูปภาพนี้:') || '';
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('description', description);

    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/screenshots', {
            method: 'POST',
            body: formData
        });
        toast('อัปโหลดสำเร็จ');
        await loadScreenshots();
    } catch (err) {
        toast(err.message || 'เกิดข้อผิดพลาดในการอัปโหลด', 'danger');
    } finally {
        input.value = '';
    }
}

async function deleteScreenshot(id) {
    if (!await confirmAction('ต้องการลบรูปภาพนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/screenshots/' + id, { method: 'DELETE' });
        await loadScreenshots();
        toast('ลบรูปภาพสำเร็จ');
    } catch (err) {
        toast(err.message || 'ลบรูปภาพไม่สำเร็จ', 'danger');
    }
}

function viewLightbox(id) {
    const s = stockScreenshots.find(x => x.id === id);
    if (!s) return;
    const title = document.getElementById('lightboxTitle');
    const img = document.getElementById('lightboxImage');
    const desc = document.getElementById('lightboxDesc');
    
    if (title) title.textContent = s.name;
    if (img) img.src = BASE_URL + '/uploads/' + s.file_path;
    if (desc) desc.textContent = s.description || 'ไม่มีคำอธิบาย';
    
    openModal('screenshotLightboxModal');
}

function initDropzone() {
    const dropzone = document.getElementById('stkDropzone');
    if (!dropzone) return;

    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.add('active');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.remove('active');
        }, false);
    });

    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        const fileInput = document.getElementById('stkFileSelect');
        if (files.length && fileInput) {
            // Programmatically assign files to input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(files[0]);
            fileInput.files = dataTransfer.files;
            uploadScreenshot(fileInput);
        }
    }, false);
}

