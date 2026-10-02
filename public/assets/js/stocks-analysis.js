/* =====================================================
   stocks-analysis.js — AI read of a single ticker
   Loaded after stocks.js on the stocks page; shares its state.

   The model's answer is shown as it came, labelled with who wrote it and
   with a reminder that it is an opinion, not advice.
===================================================== */

const AI_PROVIDERS = {
    gemini: 'Google Gemini',
    openai: 'OpenAI',
    anthropic: 'Anthropic Claude',
    kimi: 'Moonshot Kimi',
    openrouter: 'OpenRouter',
};

async function runStockAnalysis() {
    const tickerInput = document.getElementById('stkAnalyzeTicker');
    const marketSelect = document.getElementById('stkAnalyzeMarket');
    const btn = document.getElementById('stkAnalyzeBtn');
    const loader = document.getElementById('stkAnalyzeLoading');
    const result = document.getElementById('stkAnalyzeResult');
    if (!tickerInput) return;

    const ticker = tickerInput.value.trim().toUpperCase();
    const market = marketSelect ? marketSelect.value : 'US';

    if (!ticker) {
        showAnalysisNotice('ใส่ชื่อหุ้น (Ticker) ก่อน เช่น AAPL หรือ PTT.BK');
        tickerInput.focus();
        return;
    }

    btn.disabled = true;
    result.hidden = true;
    result.innerHTML = '';
    document.getElementById('stkAnalyzeLoadingText').textContent = 'กำลังวิเคราะห์ ' + ticker + ' อาจใช้เวลาครู่หนึ่ง';
    loader.hidden = false;

    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/analyze', {
            method: 'POST',
            body: JSON.stringify({ ticker, market })
        });
        if (!res || !res.result) throw new Error('ได้ผลลัพธ์ที่อ่านไม่ได้ ลองอีกครั้ง');
        renderStockAnalysisResult(res);
    } catch (err) {
        const message = err.message || 'วิเคราะห์ไม่สำเร็จ ลองอีกครั้ง';
        // The server says so when no AI key has been saved.
        if (message.includes('ตั้งค่า API Key') || message.includes('วิเคราะห์หุ้นด้วย AI')) {
            showAnalysisNotice('ยังไม่ได้ตั้งค่า API Key ของ AI ใส่ได้ในหน้าตั้งค่า (Google Gemini ใช้ได้ฟรี)', true);
        } else {
            showAnalysisNotice(message);
        }
    } finally {
        loader.hidden = true;
        btn.disabled = false;
    }
}

function showAnalysisNotice(message, withSettings) {
    const result = document.getElementById('stkAnalyzeResult');
    result.innerHTML = '<div class="alert alert-warning" role="alert">' + escHtml(message)
        + (withSettings ? ' <button type="button" class="btn btn-sm" data-act="goStockApiSettings">ไปหน้าตั้งค่า</button>' : '')
        + '</div>';
    result.hidden = false;
}

function goStockApiSettings() {
    try { localStorage.setItem('settings_last_tab', 'stock-api'); } catch (_) { /* the settings page opens on its first tab */ }
    window.location.href = BASE_URL + '/settings';
}

/** A label and a figure on one ruled line, or nothing when the model gave none. */
function analysisRow(label, value, cls) {
    return '<tr><td>' + label + '</td><td class="num' + (cls ? ' ' + cls : '') + '">' + escHtml(value || '—') + '</td></tr>';
}

function analysisList(items) {
    return items && items.length
        ? '<ul>' + items.map(i => '<li>' + escHtml(i) + '</li>').join('') + '</ul>'
        : '<p class="stk-note">ไม่มีข้อมูล</p>';
}

function renderStockAnalysisResult(data) {
    const r = data.result;
    const container = document.getElementById('stkAnalyzeResult');

    const kind = r.recommendation === 'BUY' ? 'buy' : r.recommendation === 'HOLD' ? 'hold' : 'wait';
    const provider = AI_PROVIDERS[data.provider];

    container.innerHTML =
        '<div class="stk-verdict">'
        + '<span class="rec rec-' + kind + '">' + escHtml(r.recommendation_label || r.recommendation || '—') + '</span>'
        + '<p class="stk-summary">' + escHtml(r.summary) + '</p>'
        + '<p class="stk-note">' + (r.name ? escHtml(r.name) + ' · ' : '') + (provider ? 'วิเคราะห์โดย ' + provider + ' · ' : '')
        + 'เป็นความเห็นของโมเดล ไม่ใช่คำแนะนำการลงทุน</p>'
        + '</div>'
        + '<div class="cols">'
        + '<div class="col-main">'
        + '<section class="sec"><div class="sec-head"><h3>ปัจจัยพื้นฐาน</h3></div><p class="stk-prose">' + escHtml(r.fundamental_analysis) + '</p></section>'
        + '<section class="sec"><div class="sec-head"><h3>แนวโน้มทางเทคนิค</h3></div><p class="stk-prose">' + escHtml(r.technical_analysis) + '</p></section>'
        + '<section class="sec"><div class="sec-head"><h3>โอกาส</h3></div>' + analysisList(r.opportunities) + '</section>'
        + '<section class="sec"><div class="sec-head"><h3>ความเสี่ยง</h3></div>' + analysisList(r.risks) + '</section>'
        + '</div>'
        + '<aside class="col-side">'
        + '<section class="sec"><div class="sec-head"><h3>กรอบราคา</h3></div><table class="ledger">'
        + analysisRow('ราคาอ้างอิง', r.current_price)
        + analysisRow('เป้าหมายทำกำไร', r.target_price, 'gain')
        + analysisRow('จุดตัดขาดทุน', r.stop_loss, 'loss')
        + analysisRow('แนวรับ 1', r.support_1)
        + analysisRow('แนวรับ 2', r.support_2)
        + analysisRow('แนวต้าน 1', r.resistance_1)
        + analysisRow('แนวต้าน 2', r.resistance_2)
        + '</table></section>'
        + '<section class="sec"><div class="sec-head"><h3>งบการเงินและอัตราส่วน</h3></div><table class="ledger">'
        + analysisRow('แนวโน้มหลัก', r.trend)
        + analysisRow('รายได้ล่าสุด', r.revenue)
        + analysisRow('กำไรสุทธิ', r.net_profit)
        + analysisRow('กำไรต่อหุ้น (EPS)', r.eps)
        + analysisRow('P/E', r.pe)
        + analysisRow('P/B', r.pb)
        + analysisRow('ROE', r.roe)
        + analysisRow('หนี้สินต่อทุน (D/E)', r.de_ratio)
        + analysisRow('กระแสเงินสดอิสระ', r.free_cash_flow)
        + analysisRow('ปันผล (Yield)', r.dividend_yield)
        + '</table></section>'
        + '</aside>'
        + '</div>';

    container.hidden = false;
    container.scrollIntoView({ block: 'start' });
}
