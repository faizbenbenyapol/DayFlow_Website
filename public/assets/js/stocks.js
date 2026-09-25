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
        
        const aiBtn = `<button class="btn btn-ai-sparkle btn-sm" data-act="analyzeStockInstantly" data-args="[&quot;${item.ticker}&quot;,&quot;${item.market}&quot;]" title="วิเคราะห์ด้วย AI" style="padding:0.25rem 0.5rem">
                <svg class="sparkle-svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:2px"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C8.57 12.05 8 10.61 8 9c0-2.21 1.79-4 4-4s4 1.79 4 4c0 1.61-.57 3.05-2.15 4.1z"/></svg> วิเคราะห์
            </button>`;

        let html = `<tr>
            <td style="text-align:center">
                <svg data-act="toggleWatchlist" data-args="[&quot;${item.ticker}&quot;, &quot;${item.market}&quot;]" style="cursor:pointer;color:${starColor};fill:${starFill};transition:all 0.2s" width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </td>
            <td>
                <span class="stk-ticker">${escHtml(item.ticker)}</span>
                <span class="stk-market-badge">${escHtml(item.market)}</span>
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
                <td class="text-sm">${escHtml(formatDate(t.txn_date))}</td>
                <td>
                    <span class="stk-ticker">${escHtml(t.ticker)}</span>
                    <span class="stk-market-badge">${escHtml(t.market)}</span>
                </td>
                <td>${sideBadge}</td>
                <td class="stk-num">${fmtShares(t.quantity)}</td>
                <td class="stk-num">${formatMoney(t.price)} ${escHtml(t.currency)}</td>
                <td class="stk-num">${formatMoney(t.fee)}</td>
                <td class="stk-num">${formatMoney(value)}</td>
                <td class="mode-readonly-hide">
                    <button class="btn-link" data-act="openEditStock" data-args="[${t.id}]">แก้ไข</button>
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

