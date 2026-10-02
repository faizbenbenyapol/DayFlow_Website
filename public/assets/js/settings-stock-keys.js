// =====================================================
// settings-stock-keys.js — Settings: stock price and stock AI keys
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Stock API keys ----------
    const STOCK_PROVIDERS = [
        { id: 'finnhub',      label: 'Finnhub',       help: 'ฟรี 60 req/min รองรับ US + SET (.BK)' },
        { id: 'alphavantage', label: 'Alpha Vantage', help: 'ฟรี 25 req/day' },
        { id: 'twelvedata',   label: 'Twelve Data',   help: 'ฟรี 800 req/day' },
    ];

    async function loadStockKeys() {
        const container = $('#stockKeysList');
        if (!container) return;
        try {
            const data = await apiFetch(BASE_URL + '/api/stocks/keys');
            const existing = data.keys || {};
            container.innerHTML = STOCK_PROVIDERS.map(p => {
                const info = existing[p.id] || { set: false, masked: '', updated_at: null };
                const statusClass = info.set ? 'set' : '';
                const statusText = info.set
                    ? ('ตั้งค่าแล้ว · ' + (info.masked || '') + (info.updated_at ? ' · อัปเดต ' + info.updated_at : ''))
                    : 'ยังไม่ได้ตั้ง';
                return `
                <div class="stock-key-row" data-provider="${p.id}">
                    <div>
                        <div class="stock-key-provider">${p.label}</div>
                        <div class="stock-key-status ${statusClass}">${escHtml(statusText)}</div>
                        <div class="text-xs text-muted" style="margin-top:2px">${escHtml(p.help)}</div>
                    </div>
                    <input type="password" class="form-control" placeholder="${info.set ? 'กรอกเพื่อเปลี่ยน' : 'API key'}" data-key-input="${p.id}">
                    <div class="stock-key-actions">
                        <button class="btn btn-primary btn-sm" data-key-act="save" data-provider="${p.id}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-key-act="test" data-provider="${p.id}">ทดสอบ</button>
                        ${info.set ? `<button class="btn btn-ghost btn-sm" style="color:var(--color-danger)" data-key-act="delete" data-provider="${p.id}">ลบ</button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch (err) {
            container.innerHTML = '<div class="text-sm" style="color:var(--color-danger)">โหลดไม่สำเร็จ: ' + escHtml(err.message || '') + '</div>';
        }
    }

    async function saveStockKey(provider) {
        const input = document.querySelector(`[data-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/stocks/keys', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            if (input) input.value = '';
            toast(res.deleted ? 'ลบแล้ว' : 'บันทึกแล้ว');
            await loadStockKeys();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function testStockKey(provider) {
        const input = document.querySelector(`[data-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/stocks/keys/test', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            toast(res.message || 'Key ใช้งานได้');
        } catch (err) { toast(err.message || 'ทดสอบไม่ผ่าน', 'danger'); }
    }

    async function deleteStockKey(provider) {
        if (!await confirmAction('ลบ API key ของ ' + provider + '?', 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/stocks/keys/' + encodeURIComponent(provider), { method: 'DELETE' });
            toast('ลบแล้ว');
            await loadStockKeys();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    // ---------- Stock AI keys ----------
    const STOCK_AI_PROVIDERS = [
        { id: 'gemini',    label: 'Google Gemini (แนะนำ)',  help: 'สมัครใช้งานฟรีที่ Google AI Studio คุ้มค่าและเร็วที่สุด' },
        { id: 'openai',    label: 'OpenAI (ChatGPT)',      help: 'ผู้ให้บริการยอดนิยม เช่น gpt-4o-mini' },
        { id: 'anthropic', label: 'Anthropic Claude',      help: 'ผู้ให้บริการประสิทธิภาพสูง เช่น Claude 3.5 Sonnet' },
        { id: 'kimi',      label: 'Moonshot Kimi AI',      help: 'ผู้ให้บริการโมเดล Kimi AI ยอดนิยม (รองรับ moonshot-v1)' },
        { id: 'openrouter', label: 'OpenRouter.ai',         help: 'เข้าถึง LLM ทุกค่ายด้วย API เดียว เช่น Gemini, Claude, GPT' }
    ];

    async function loadStockAiKeys() {
        const container = $('#stockAiKeysList');
        if (!container) return;
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/keys');
            const existing = data.keys || {};
            container.innerHTML = STOCK_AI_PROVIDERS.map(p => {
                const info = existing[p.id] || { set: false, masked: '', updated_at: null };
                const statusClass = info.set ? 'set' : '';
                const statusText = info.set
                    ? ('ตั้งค่าแล้ว · ' + (info.masked || '') + (info.updated_at ? ' · อัปเดต ' + info.updated_at : ''))
                    : 'ยังไม่ได้ตั้ง';
                
                let borderColor = '#8b5cf6'; // default gemini purple
                if (p.id === 'openai') borderColor = '#10b981'; // openai green
                else if (p.id === 'anthropic') borderColor = '#f97316'; // anthropic orange
                else if (p.id === 'kimi') borderColor = '#06b6d4'; // kimi cyan
                else if (p.id === 'openrouter') borderColor = '#6366f1'; // openrouter indigo

                return `
                <div class="stock-key-row" data-provider="${p.id}" style="border-left-color: ${borderColor}">
                    <div>
                        <div class="stock-key-provider">${p.label}</div>
                        <div class="stock-key-status ${statusClass}">${escHtml(statusText)}</div>
                        <div class="text-xs text-muted" style="margin-top:2px">${escHtml(p.help)}</div>
                    </div>
                    <input type="password" class="form-control" placeholder="${info.set ? 'กรอกเพื่อเปลี่ยน' : 'API key'}" data-ai-key-input="${p.id}">
                    <div class="stock-key-actions">
                        <button class="btn btn-primary btn-sm" data-ai-key-act="save" data-provider="${p.id}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-ai-key-act="test" data-provider="${p.id}">ทดสอบ</button>
                        ${info.set ? `<button class="btn btn-ghost btn-sm" style="color:var(--color-danger)" data-ai-key-act="delete" data-provider="${p.id}">ลบ</button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch (err) {
            container.innerHTML = '<div class="text-sm" style="color:var(--color-danger)">โหลดไม่สำเร็จ: ' + escHtml(err.message || '') + '</div>';
        }
    }

    async function saveStockAiKey(provider) {
        const input = document.querySelector(`[data-ai-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/ai/keys', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            if (input) input.value = '';
            toast(res.deleted ? 'ลบแล้ว' : 'บันทึกแล้ว');
            await loadStockAiKeys();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function testStockAiKey(provider) {
        const input = document.querySelector(`[data-ai-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/ai/keys/test', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            toast(res.message || 'Key ใช้งานได้');
        } catch (err) { toast(err.message || 'ทดสอบไม่ผ่าน', 'danger'); }
    }

    async function deleteStockAiKey(provider) {
        if (!await confirmAction('ลบ API key ของ ' + provider + '?', 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/ai/keys/' + encodeURIComponent(provider), { method: 'DELETE' });
            toast('ลบแล้ว');
            await loadStockAiKeys();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    function initStockKeys() {
        // Delegated click handler for stock price keys
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-key-act]');
            if (!btn) return;
            if (!btn.closest('#stockKeysList')) return;
            const act = btn.dataset.keyAct;
            const provider = btn.dataset.provider;
            if (act === 'save')   saveStockKey(provider);
            else if (act === 'test')   testStockKey(provider);
            else if (act === 'delete') deleteStockKey(provider);
        });

        // Delegated click handler for AI keys
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-ai-key-act]');
            if (!btn) return;
            const act = btn.dataset.aiKeyAct;
            const provider = btn.dataset.provider;
            if (act === 'save')   saveStockAiKey(provider);
            else if (act === 'test')   testStockAiKey(provider);
            else if (act === 'delete') deleteStockAiKey(provider);
        });

        let loaded = false;
        $$('.settings-tab').forEach(t => t.addEventListener('click', () => {
            if (t.dataset.tab === 'stock-api' && !loaded) {
                loaded = true;
                loadStockKeys();
                loadStockAiKeys();
            }
        }));
        if ($('#tab-stock-api')?.style.display !== 'none') {
            loaded = true;
            loadStockKeys();
            loadStockAiKeys();
        }
    }


    document.addEventListener('DOMContentLoaded', () => {
        initStockKeys();
    });
})();
