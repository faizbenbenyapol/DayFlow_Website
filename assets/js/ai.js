/* =====================================================
   ai.js — AI Content Generator
===================================================== */
(function () {
    'use strict';

    const PROVIDER_LABEL = {
        openai: 'OpenAI',
        gemini: 'Google Gemini',
        anthropic: 'Anthropic Claude',
        replicate: 'Replicate (Video)',
        kimi: 'Moonshot Kimi',
        openrouter: 'OpenRouter.ai'
    };
    const DRAFT_KEY = 'ai_draft_v1';
    const FORM_IDS = ['aiKeyword', 'aiPlatform', 'aiStyle', 'aiLanguage', 'aiDuration', 'aiTextProvider', 'aiExtra'];

    // ---------- Tabs ----------
    function initTabs() {
        const tabs = document.querySelectorAll('.ai-tab');
        const panes = document.querySelectorAll('.ai-pane');
        tabs.forEach(t => t.addEventListener('click', () => {
            const name = t.dataset.tab;
            tabs.forEach(x => {
                x.classList.toggle('active', x === t);
                x.classList.toggle('btn-primary', x === t);
                x.classList.toggle('btn-ghost', x !== t);
            });
            panes.forEach(p => {
                p.style.display = (p.id === 'ai-pane-' + name) ? '' : 'none';
            });
            localStorage.setItem('ai_tab', name);
            if (name === 'keys') loadKeys();
            if (name === 'history') loadHistory();
        }));
        const saved = localStorage.getItem('ai_tab') || 'generate';
        const target = document.querySelector('.ai-tab[data-tab="' + saved + '"]');
        if (target) target.click();
    }

    // ---------- Draft autosave ----------
    function saveDraft() {
        const draft = {};
        FORM_IDS.forEach(id => {
            const el = document.getElementById(id);
            if (el) draft[id] = el.value;
        });
        localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    }
    function loadDraft() {
        try {
            const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || '{}');
            FORM_IDS.forEach(id => {
                const el = document.getElementById(id);
                if (el && d[id] !== undefined && d[id] !== '') el.value = d[id];
            });
        } catch {}
    }
    function initDraftAutosave() {
        loadDraft();
        FORM_IDS.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', saveDraft);
            if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
                el.addEventListener('input', debounce(saveDraft, 400));
            }
        });
    }
    function debounce(fn, ms) {
        let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }

    // ---------- Keys ----------
    async function loadKeys() {
        const box = document.getElementById('aiKeysList');
        box.innerHTML = '<div class="text-xs text-muted">กำลังโหลด...</div>';
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/keys');
            const providers = data.providers || ['openai', 'gemini', 'anthropic', 'replicate'];
            const existing = data.keys || {};

            box.innerHTML = '';
            providers.forEach(p => {
                const row = document.createElement('div');
                row.className = 'ai-key-row';
                row.dataset.provider = p;
                const cur = existing[p];
                row.innerHTML = `
                    <div class="ai-key-head">
                        <strong>${PROVIDER_LABEL[p] || p}</strong>
                        ${cur ? `<span class="badge badge-success">บันทึกแล้ว</span>` : `<span class="badge">ยังไม่ได้ตั้ง</span>`}
                    </div>
                    <div class="ai-key-body">
                        <input type="password" class="form-control" placeholder="${cur ? cur.masked : 'sk-... / AIza... / r8_...'}" data-prov="${p}">
                        <button class="btn btn-primary btn-sm" data-save="${p}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-test="${p}">ทดสอบ</button>
                        ${cur ? `<button class="btn btn-ghost btn-sm" data-del="${p}">ลบ</button>` : ''}
                    </div>
                    <div class="ai-key-msg" data-msg="${p}"></div>
                `;
                box.appendChild(row);
            });

            box.querySelectorAll('[data-save]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.save;
                    const input = box.querySelector(`input[data-prov="${prov}"]`);
                    const val = input.value.trim();
                    if (!val) return toast('กรุณากรอก API key', 'error');
                    try {
                        await apiFetch(BASE_URL + '/api/ai/keys', {
                            method: 'POST',
                            body: JSON.stringify({ provider: prov, api_key: val })
                        });
                        toast('บันทึก ' + PROVIDER_LABEL[prov] + ' สำเร็จ');
                        loadKeys();
                    } catch (e) { toast(e.message, 'error'); }
                });
            });
            box.querySelectorAll('[data-test]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.test;
                    const input = box.querySelector(`input[data-prov="${prov}"]`);
                    const msgEl = box.querySelector(`[data-msg="${prov}"]`);
                    const apiKey = input.value.trim(); // empty → use stored
                    msgEl.innerHTML = '<span class="text-xs text-muted">กำลังทดสอบ...</span>';
                    btn.disabled = true;
                    try {
                        const r = await apiFetch(BASE_URL + '/api/ai/keys/test', {
                            method: 'POST',
                            body: JSON.stringify({ provider: prov, api_key: apiKey })
                        });
                        msgEl.innerHTML = '<span class="text-xs" style="color:var(--color-success)">✓ ' + (r.message || 'ใช้งานได้') + '</span>';
                    } catch (e) {
                        msgEl.innerHTML = '<span class="text-xs" style="color:var(--color-danger)">✗ ' + escHtml(e.message) + '</span>';
                    } finally {
                        btn.disabled = false;
                    }
                });
            });
            box.querySelectorAll('[data-del]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.del;
                    if (!await confirmAction('ลบ API key ของ ' + PROVIDER_LABEL[prov] + '?', 'ลบ')) return;
                    try {
                        await apiFetch(BASE_URL + '/api/ai/keys/' + prov, { method: 'DELETE' });
                        toast('ลบแล้ว');
                        loadKeys();
                    } catch (e) { toast(e.message, 'error'); }
                });
            });
        } catch (e) {
            box.innerHTML = '<div class="text-xs" style="color:var(--color-danger)">' + escHtml(e.message) + '</div>';
        }
    }

    // ---------- Script Generation ----------
    let lastResult = null;

    async function generateScript() {
        const keyword = document.getElementById('aiKeyword').value.trim();
        if (!keyword) return toast('กรุณากรอกหัวข้อ', 'error');

        const btn = document.getElementById('btnGenScript');
        btn.disabled = true;
        document.getElementById('aiSkeleton').hidden = false;
        document.getElementById('aiResultCard').hidden = true;
        document.getElementById('aiVideoCard').hidden = true;

        const body = {
            keyword,
            platform: val('aiPlatform'),
            style: val('aiStyle'),
            language: val('aiLanguage'),
            duration_sec: parseInt(val('aiDuration')) || 30,
            provider: val('aiTextProvider'),
            extra_prompt: val('aiExtra')
        };

        try {
            const data = await apiFetch(BASE_URL + '/api/ai/script', {
                method: 'POST',
                body: JSON.stringify(body)
            });
            lastResult = data.result;
            renderResult(data.result);
            toast('สร้างสคริปต์สำเร็จ');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            btn.disabled = false;
            document.getElementById('aiSkeleton').hidden = true;
        }
    }

    function val(id) {
        const el = document.getElementById(id);
        return el ? el.value : '';
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderResult(r) {
        const card = document.getElementById('aiResultCard');
        const body = document.getElementById('aiResultBody');
        if (!r) { card.hidden = true; return; }

        const scenes = Array.isArray(r.script) ? r.script : [];
        const hashtags = Array.isArray(r.hashtags) ? r.hashtags.join(' ') : (r.hashtags || '');

        body.innerHTML = `
            <div class="ai-section" data-sec="title">
                <div class="ai-section-head">
                    <div class="ai-section-label">ชื่อคลิป</div>
                    <button class="btn btn-ghost btn-sm" data-regen="title">สุ่มใหม่</button>
                </div>
                <div class="ai-section-val" contenteditable="true" data-field="title">${escHtml(r.title || '')}</div>
                <button class="btn btn-ghost btn-sm" data-copyfield="title">คัดลอก</button>
            </div>
            <div class="ai-section" data-sec="hook">
                <div class="ai-section-head">
                    <div class="ai-section-label">Hook (3 วิแรก)</div>
                    <button class="btn btn-ghost btn-sm" data-regen="hook">สุ่มใหม่</button>
                </div>
                <div class="ai-section-val" contenteditable="true" data-field="hook">${escHtml(r.hook || '')}</div>
            </div>
            <div class="ai-section">
                <div class="ai-section-label">สคริปต์ฉาก</div>
                <div class="ai-scenes">
                    ${scenes.map((s, i) => `
                        <div class="ai-scene">
                            <div class="ai-scene-head">
                                <span class="badge badge-primary">ฉาก ${escHtml(s.scene || (i + 1))}</span>
                                <span class="text-xs text-muted">${escHtml(s.time || '')}</span>
                                <button class="btn btn-ghost btn-sm" data-use-visual="${i}" style="margin-left:auto">ใช้เป็น Prompt สร้างวิดีโอ</button>
                            </div>
                            <div class="ai-scene-row"><strong>บรรยาย:</strong> <span contenteditable="true" data-scene-narr="${i}">${escHtml(s.narration || '')}</span></div>
                            <div class="ai-scene-row"><strong>ภาพ:</strong> <em contenteditable="true" data-scene-vis="${i}">${escHtml(s.visual || '')}</em></div>
                        </div>
                    `).join('')}
                </div>
            </div>
            <div class="ai-section" data-sec="cta">
                <div class="ai-section-head">
                    <div class="ai-section-label">Call to Action</div>
                    <button class="btn btn-ghost btn-sm" data-regen="cta">สุ่มใหม่</button>
                </div>
                <div class="ai-section-val" contenteditable="true" data-field="cta">${escHtml(r.cta || '')}</div>
            </div>
            <div class="ai-section" data-sec="description">
                <div class="ai-section-head">
                    <div class="ai-section-label">คำบรรยายใต้คลิป</div>
                    <button class="btn btn-ghost btn-sm" data-regen="description">สุ่มใหม่</button>
                </div>
                <div class="ai-section-val" contenteditable="true" data-field="description">${escHtml(r.description || '')}</div>
                <button class="btn btn-ghost btn-sm" data-copyfield="description">คัดลอก</button>
            </div>
            <div class="ai-section" data-sec="hashtags">
                <div class="ai-section-head">
                    <div class="ai-section-label"># แฮชแท็ก</div>
                    <button class="btn btn-ghost btn-sm" data-regen="hashtags">สุ่มใหม่</button>
                </div>
                <div class="ai-section-val ai-hashtags" contenteditable="true" data-field="hashtags">${escHtml(hashtags)}</div>
                <button class="btn btn-ghost btn-sm" data-copyfield="hashtags">คัดลอก</button>
            </div>
            <div class="ai-section">
                <div class="ai-section-label">มู้ดเพลง</div>
                <div class="ai-section-val">${escHtml(r.music_mood || '')}</div>
            </div>
            <div class="ai-section">
                <div class="ai-section-label">ไอเดียภาพปก</div>
                <div class="ai-section-val">${escHtml(r.thumbnail_idea || '')}</div>
            </div>
        `;
        card.hidden = false;

        // Copy buttons
        body.querySelectorAll('[data-copyfield]').forEach(b => {
            b.addEventListener('click', () => {
                const f = b.dataset.copyfield;
                const target = body.querySelector('[data-field="' + f + '"]');
                const text = target ? target.innerText : '';
                navigator.clipboard.writeText(text);
                toast('คัดลอกแล้ว');
            });
        });

        // Edit tracking → update lastResult
        body.querySelectorAll('[data-field]').forEach(el => {
            el.addEventListener('input', () => {
                const f = el.dataset.field;
                if (f === 'hashtags') {
                    lastResult.hashtags = el.innerText.trim().split(/\s+/).filter(Boolean);
                } else {
                    lastResult[f] = el.innerText;
                }
            });
        });
        body.querySelectorAll('[data-scene-narr]').forEach(el => {
            el.addEventListener('input', () => {
                const i = parseInt(el.dataset.sceneNarr);
                if (lastResult.script && lastResult.script[i]) lastResult.script[i].narration = el.innerText;
            });
        });
        body.querySelectorAll('[data-scene-vis]').forEach(el => {
            el.addEventListener('input', () => {
                const i = parseInt(el.dataset.sceneVis);
                if (lastResult.script && lastResult.script[i]) lastResult.script[i].visual = el.innerText;
            });
        });

        // Use visual as video prompt
        body.querySelectorAll('[data-use-visual]').forEach(b => {
            b.addEventListener('click', () => {
                const i = parseInt(b.dataset.useVisual);
                const v = lastResult.script && lastResult.script[i] && lastResult.script[i].visual;
                if (v) {
                    document.getElementById('aiVideoPrompt').value = v;
                    toast('ใส่ใน prompt วิดีโอแล้ว');
                    document.getElementById('aiVideoCard').scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Regenerate buttons
        body.querySelectorAll('[data-regen]').forEach(b => {
            b.addEventListener('click', () => regenerateSection(b.dataset.regen, b));
        });

        // Auto-fill video prompt + show video card
        const firstVisual = scenes[0] && scenes[0].visual;
        if (firstVisual) {
            document.getElementById('aiVideoPrompt').value = firstVisual;
        }
        document.getElementById('aiVideoCard').hidden = false;
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function regenerateSection(section, btn) {
        if (!lastResult) return;
        const orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';
        try {
            const ctx = [lastResult.title, lastResult.hook, lastResult.description].filter(Boolean).join(' | ').slice(0, 300);
            const r = await apiFetch(BASE_URL + '/api/ai/regenerate', {
                method: 'POST',
                body: JSON.stringify({
                    section,
                    keyword: val('aiKeyword'),
                    platform: val('aiPlatform'),
                    style: val('aiStyle'),
                    language: val('aiLanguage'),
                    provider: val('aiTextProvider'),
                    context: ctx
                })
            });
            showOptions(section, r.options || []);
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = orig;
        }
    }

    function showOptions(section, options) {
        if (!options.length) return toast('ไม่มีตัวเลือก', 'error');
        const sec = document.querySelector('.ai-section[data-sec="' + section + '"]');
        if (!sec) return;
        let box = sec.querySelector('.ai-options');
        if (box) box.remove();
        box = document.createElement('div');
        box.className = 'ai-options';
        box.innerHTML = '<div class="ai-section-label">เลือก 1 จาก ' + options.length + ' ตัวเลือก:</div>' +
            options.map((o, i) => {
                const text = Array.isArray(o) ? o.join(' ') : String(o);
                return `<button type="button" class="ai-option-btn" data-opt="${i}">${escHtml(text)}</button>`;
            }).join('');
        sec.appendChild(box);
        box.querySelectorAll('[data-opt]').forEach(b => {
            b.addEventListener('click', () => {
                const idx = parseInt(b.dataset.opt);
                const val = options[idx];
                const target = sec.querySelector('[data-field="' + section + '"]');
                if (section === 'hashtags') {
                    const arr = Array.isArray(val) ? val : String(val).split(/\s+/);
                    lastResult.hashtags = arr;
                    if (target) target.textContent = arr.join(' ');
                } else {
                    const t = Array.isArray(val) ? val.join(' ') : String(val);
                    lastResult[section] = t;
                    if (target) target.textContent = t;
                }
                box.remove();
                toast('อัปเดตแล้ว');
            });
        });
    }

    function copyAll() {
        if (!lastResult) return toast('ยังไม่มีผลลัพธ์', 'error');
        const r = lastResult;
        const tags = Array.isArray(r.hashtags) ? r.hashtags.join(' ') : (r.hashtags || '');
        const text = [
            (r.title || ''),
            '',
            r.description || '',
            '',
            tags
        ].join('\n');
        navigator.clipboard.writeText(text);
        toast('คัดลอกทั้งหมดแล้ว');
    }

    // ---------- Video Generation ----------
    let videoPollTimer = null;

    async function generateVideo() {
        const prompt = document.getElementById('aiVideoPrompt').value.trim();
        if (!prompt) return toast('กรุณากรอก prompt', 'error');

        const btn = document.getElementById('btnGenVideo');
        btn.disabled = true;
        const status = document.getElementById('aiVideoStatus');
        status.innerHTML = '<div class="text-xs text-muted">กำลังส่งคำขอ...</div>';
        document.getElementById('aiVideoPlayer').innerHTML = '';

        try {
            const data = await apiFetch(BASE_URL + '/api/ai/video', {
                method: 'POST',
                body: JSON.stringify({
                    prompt,
                    model: val('aiVideoModel')
                })
            });
            status.innerHTML = '<div class="text-xs text-muted">กำลังสร้างวิดีโอ... (โดยทั่วไป 1-5 นาที)</div>';
            pollVideoStatus(data.id, btn, status);
        } catch (e) {
            btn.disabled = false;
            status.innerHTML = '<div style="color:var(--color-danger)">' + escHtml(e.message) + '</div>';
        }
    }

    function pollVideoStatus(id, btn, status) {
        clearTimeout(videoPollTimer);
        let tries = 0;
        const MAX = 200;

        const check = async () => {
            tries++;
            try {
                const data = await apiFetch(BASE_URL + '/api/ai/video/' + id + '/status');
                if (data.status === 'completed' && data.video_url) {
                    btn.disabled = false;
                    status.innerHTML = '<div style="color:var(--color-success)">✓ เสร็จแล้ว</div>';
                    document.getElementById('aiVideoPlayer').innerHTML = `
                        <video src="${escHtml(data.video_url)}" controls style="width:100%;max-width:480px;border-radius:var(--radius-md)"></video>
                        <div class="mt-2"><a href="${escHtml(data.video_url)}" download class="btn btn-ghost btn-sm">ดาวน์โหลด</a></div>
                    `;
                    return;
                }
                if (data.status === 'failed') {
                    btn.disabled = false;
                    status.innerHTML = '<div style="color:var(--color-danger)">✗ ล้มเหลว: ' + escHtml(data.error || '') + '</div>';
                    return;
                }
                if (tries >= MAX) {
                    btn.disabled = false;
                    status.innerHTML = '<div style="color:var(--color-warning)">หมดเวลารอ — ลองเช็คในประวัติภายหลัง</div>';
                    return;
                }
                status.innerHTML = `<div class="text-xs text-muted">กำลังสร้าง... (${tries * 3} วิ)</div>`;
                videoPollTimer = setTimeout(check, 3000);
            } catch (e) {
                btn.disabled = false;
                status.innerHTML = '<div style="color:var(--color-danger)">' + escHtml(e.message) + '</div>';
            }
        };
        videoPollTimer = setTimeout(check, 3000);
    }

    // ---------- History ----------
    let historyItems = [];

    async function loadHistory() {
        const box = document.getElementById('aiHistoryList');
        box.innerHTML = '<div class="text-xs text-muted text-center">กำลังโหลด...</div>';
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/history');
            historyItems = data.items || [];
            renderHistory(historyItems);
        } catch (e) {
            box.innerHTML = '<div class="text-xs" style="color:var(--color-danger)">' + escHtml(e.message) + '</div>';
        }
    }

    function renderHistory(items) {
        const box = document.getElementById('aiHistoryList');
        if (!items.length) {
            box.innerHTML = '<div class="text-xs text-muted text-center">ไม่พบรายการ</div>';
            return;
        }
        box.innerHTML = items.map(it => {
            const title = (it.result && it.result.title) || it.keyword || '(ไม่มีชื่อ)';
            const kindLabel = it.kind === 'video' ? 'วิดีโอ' : 'สคริปต์';
            const statusBadge = it.status === 'completed'
                ? '<span class="badge badge-success">สำเร็จ</span>'
                : it.status === 'failed'
                    ? '<span class="badge badge-danger">ล้มเหลว</span>'
                    : '<span class="badge">' + escHtml(it.status) + '</span>';
            return `
                <div class="ai-history-item" data-id="${it.id}">
                    <div class="ai-history-main">
                        <div class="ai-history-title">${escHtml(title)}</div>
                        <div class="text-xs text-muted">${kindLabel} · ${escHtml(it.platform || '-')} · ${escHtml(it.created_at)} ${statusBadge}</div>
                    </div>
                    <div class="ai-history-actions">
                        ${it.kind === 'script' && it.result ? `<button class="btn btn-ghost btn-sm" data-reopen="${it.id}">เปิดดู</button>` : ''}
                        ${it.video_url ? `<a class="btn btn-ghost btn-sm" href="${escHtml(it.video_url)}" target="_blank">ดู</a>` : ''}
                        <button class="btn btn-ghost btn-sm" data-histdel="${it.id}">ลบ</button>
                    </div>
                </div>
            `;
        }).join('');

        box.querySelectorAll('[data-reopen]').forEach(b => {
            b.addEventListener('click', () => {
                const id = parseInt(b.dataset.reopen);
                const item = items.find(x => x.id == id);
                if (item && item.result) {
                    document.querySelector('.ai-tab[data-tab="generate"]').click();
                    lastResult = item.result;
                    if (item.keyword) document.getElementById('aiKeyword').value = item.keyword;
                    renderResult(item.result);
                }
            });
        });
        box.querySelectorAll('[data-histdel]').forEach(b => {
            b.addEventListener('click', async () => {
                if (!await confirmAction('ลบรายการนี้?', 'ลบ')) return;
                try {
                    await apiFetch(BASE_URL + '/api/ai/history/' + b.dataset.histdel, { method: 'DELETE' });
                    toast('ลบแล้ว');
                    loadHistory();
                } catch (e) { toast(e.message, 'error'); }
            });
        });
    }

    function filterHistory(q) {
        const term = q.trim().toLowerCase();
        if (!term) return renderHistory(historyItems);
        const filtered = historyItems.filter(it => {
            const title = ((it.result && it.result.title) || '').toLowerCase();
            const kw = (it.keyword || '').toLowerCase();
            return title.includes(term) || kw.includes(term);
        });
        renderHistory(filtered);
    }

    // ---------- Init ----------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initDraftAutosave();

        document.getElementById('btnGenScript').addEventListener('click', generateScript);
        document.getElementById('btnClearGen').addEventListener('click', () => {
            document.getElementById('aiKeyword').value = '';
            document.getElementById('aiExtra').value = '';
            document.getElementById('aiResultCard').hidden = true;
            document.getElementById('aiVideoCard').hidden = true;
            document.getElementById('aiSkeleton').hidden = true;
            lastResult = null;
            localStorage.removeItem(DRAFT_KEY);
        });
        document.getElementById('btnCopyAll').addEventListener('click', copyAll);
        document.getElementById('btnGenVideo').addEventListener('click', generateVideo);

        // Preset chips
        document.querySelectorAll('#aiPresetChips [data-preset]').forEach(b => {
            b.addEventListener('click', () => {
                document.getElementById('aiKeyword').value = b.dataset.preset;
                document.getElementById('aiKeyword').focus();
                saveDraft();
            });
        });

        // History search
        const searchInput = document.getElementById('aiHistorySearch');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(e => filterHistory(e.target.value), 200));
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', e => {
            // Ctrl+Enter anywhere = generate
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const genPane = document.getElementById('ai-pane-generate');
                if (genPane && genPane.style.display !== 'none') {
                    e.preventDefault();
                    generateScript();
                }
            }
        });
        document.getElementById('aiKeyword').addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); generateScript(); }
        });
    });
})();
