/* =====================================================
   stocks-analysis.js — AI analysis of a single ticker
   Loaded after stocks.js on the stocks page; shares its state.
===================================================== */

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

    const oppsHtml = (r.opportunities || []).map(o => `<li><span class="stk-bullet-success">✦</span> ${escHtml(o)}</li>`).join('');
    const risksHtml = (r.risks || []).map(rk => `<li><span class="stk-bullet-danger">⚠</span> ${escHtml(rk)}</li>`).join('');

    container.innerHTML = `
        <div class="stk-analysis-dashboard">
            <!-- Top Row: Balanced Summary Dashboard -->
            <div class="stk-dash-row" style="grid-template-columns: 1.2fr 1fr;">
                <!-- 1. Recommendation Card -->
                <div class="stk-rec-card card-glow-${recColor}">
                    <div class="stk-rec-header">ผลประเมินและคำแนะนำ${providerHtml}</div>
                    <div class="stk-rec-pill stk-rec-${recColor}">
                        ${recIcon}
                        <span>${escHtml(r.recommendation_label || r.recommendation)}</span>
                    </div>
                    <div class="stk-rec-summary">${escHtml(r.summary)}</div>
                </div>

                <!-- 2. Strategic Targets -->
                <div class="stk-stats-box">
                    <h4 class="stk-box-title">กรอบราคากลยุทธ์</h4>
                    <div class="stk-price-targets">
                        <div class="stk-target-item">
                            <span class="stk-target-lbl">ราคาอ้างอิง</span>
                            <span class="stk-target-val">${escHtml(r.current_price || '—')}</span>
                        </div>
                        <div class="stk-target-item">
                            <span class="stk-target-lbl" style="color:var(--color-success)">เป้าหมายทำกำไร</span>
                            <span class="stk-target-val txt-pos" style="font-weight:700">${escHtml(r.target_price || '—')}</span>
                        </div>
                        <div class="stk-target-item">
                            <span class="stk-target-lbl" style="color:var(--color-danger)">จุดตัดขาดทุน (SL)</span>
                            <span class="stk-target-val txt-neg" style="font-weight:700">${escHtml(r.stop_loss || '—')}</span>
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
                        <p class="stk-analysis-text">${escHtml(r.fundamental_analysis).replace(/\n/g, '<br>')}</p>
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
                                    <span class="val text-pos font-semibold">${escHtml(r.support_1 || '—')}</span>
                                </div>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวรับที่ 2 (S2)</span>
                                    <span class="val text-pos" style="opacity:0.8">${escHtml(r.support_2 || '—')}</span>
                                </div>
                            </div>
                            <div class="stk-level-group resistance">
                                <span class="stk-level-title">แนวต้านสำคัญ (Resistance Levels)</span>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวต้านที่ 1 (R1)</span>
                                    <span class="val text-neg font-semibold">${escHtml(r.resistance_1 || '—')}</span>
                                </div>
                                <div class="stk-level-item">
                                    <span class="lbl">แนวต้านที่ 2 (R2)</span>
                                    <span class="val text-neg" style="opacity:0.8">${escHtml(r.resistance_2 || '—')}</span>
                                </div>
                            </div>
                        </div>
                        <div class="stk-levels-meter mt-4">
                            <div class="stk-level-range">
                                <span>แนวรับ (S1): <strong>${escHtml(r.support_1 || '—')}</strong></span>
                                <span>แนวต้าน (R1): <strong>${escHtml(r.resistance_1 || '—')}</strong></span>
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
                        <p class="stk-analysis-text">${escHtml(r.technical_analysis).replace(/\n/g, '<br>')}</p>
                    </div>

                    <div class="stk-stats-box">
                        <h4 class="stk-box-title font-semibold">งบการเงินและอัตราส่วนหลัก</h4>
                        <table class="stk-ratios-table">
                            <tr>
                                <td>ชื่อกิจการ</td>
                                <td class="text-right"><strong>${escHtml(r.name || '—')}</strong></td>
                            </tr>
                            <tr>
                                <td>เทรนด์หลัก (Trend)</td>
                                <td class="text-right"><span class="stk-trend-badge">${escHtml(r.trend || '—')}</span></td>
                            </tr>
                            <tr>
                                <td>รายได้รวมล่าสุด</td>
                                <td class="text-right font-semibold">${escHtml(r.revenue || '—')}</td>
                            </tr>
                            <tr>
                                <td>กำไรสุทธิ (Net Profit)</td>
                                <td class="text-right font-semibold text-pos">${escHtml(r.net_profit || '—')}</td>
                            </tr>
                            <tr>
                                <td>กำไรต่อหุ้น (EPS)</td>
                                <td class="text-right font-mono">${escHtml(r.eps || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน P/E Ratio</td>
                                <td class="text-right font-mono">${escHtml(r.pe || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน P/B Ratio</td>
                                <td class="text-right font-mono">${escHtml(r.pb || '—')}</td>
                            </tr>
                            <tr>
                                <td>อัตราส่วน ROE</td>
                                <td class="text-right font-mono">${escHtml(r.roe || '—')}</td>
                            </tr>
                            <tr>
                                <td>หนี้สินต่อทุน (D/E)</td>
                                <td class="text-right font-mono">${escHtml(r.de_ratio || '—')}</td>
                            </tr>
                            <tr>
                                <td>กระแสเงินสดอิสระ (FCF)</td>
                                <td class="text-right font-semibold">${escHtml(r.free_cash_flow || '—')}</td>
                            </tr>
                            <tr>
                                <td>ปันผล (Dividend Yield)</td>
                                <td class="text-right font-mono">${escHtml(r.dividend_yield || '—')}</td>
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

