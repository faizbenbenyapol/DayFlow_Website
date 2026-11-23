/* =====================================================
   ai.js — AI Content Generator
===================================================== */
(function () {
    'use strict';

    const DRAFT_KEY = 'ai_draft_v1';
    const FORM_IDS = ['aiKeyword', 'aiPlatform', 'aiStyle', 'aiLanguage', 'aiDuration', 'aiTextProvider', 'aiExtra'];

    // ---------- Tabs ----------
    /**
     * ผูกแท็บกับ pane และจำแท็บที่เลือกไว้ใน localStorage
     * เริ่มที่ "สั่งงาน" เมื่อมีแท็บนั้น ไม่งั้นเริ่มที่ "สร้างคอนเทนต์"
     */
    function initTabs() {
        const tabs = Array.from(document.querySelectorAll('.ai-tab'));
        const panes = document.querySelectorAll('.ai-pane');
        const select = (tab, focus) => {
            const name = tab.dataset.tab;
            tabs.forEach(x => {
                x.setAttribute('aria-selected', String(x === tab));
                x.tabIndex = x === tab ? 0 : -1;
            });
            panes.forEach(p => { p.hidden = p.id !== 'ai-pane-' + name; });
            try { localStorage.setItem('ai_tab', name); } catch (_) { /* not remembered */ }
            if (focus) tab.focus();
            if (name === 'keys') loadKeys();
            if (name === 'history') loadHistory();
        };
        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => select(tab));
            tab.addEventListener('keydown', e => {
                const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (!step) return;
                e.preventDefault();
                select(tabs[(i + step + tabs.length) % tabs.length], true);
            });
        });
        let saved = '';
        try { saved = localStorage.getItem('ai_tab') || ''; } catch (_) { /* first tab */ }
        // แท็บ "สั่งงาน" เป็นค่าเริ่มต้นเมื่อมีคีย์ ไม่มีก็กลับไปสร้างคอนเทนต์
        const fallback = tabs.some(t => t.dataset.tab === 'chat') ? 'chat' : 'generate';
        select(tabs.find(t => t.dataset.tab === saved) || tabs.find(t => t.dataset.tab === fallback) || tabs[0]);
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
    /**
     * วาดรายการคีย์จาก API และผูกปุ่มบันทึก/ทดสอบ/ลบ
     * เจ้าที่คุยได้มีช่องโมเดล, custom มีช่อง URL และโมเดลบังคับ, replicate ไม่มีช่องเพิ่ม
     * ผลข้างเคียง: เขียนทับ #aiKeysList และเติมตัวเลือก #aiTextProvider
     */
    async function loadKeys() {
        const box = document.getElementById('aiKeysList');
        box.innerHTML = '<div class="ai-note">กำลังโหลด...</div>';
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/keys');
            const providers = data.providers || ['openai', 'gemini', 'anthropic', 'replicate'];
            const existing = data.keys || {};
            const labels = data.labels || {};
            const chatProviders = data.chat_providers || [];
            const defaults = data.default_models || {};
            const labelOf = p => labels[p] || p;

            /** ช่องกรอกเพิ่มเติมของแถว พร้อม label และ aria-label */
            const field = (prov, text, name, value, placeholder, required) => `
                <div class="ai-key-field">
                    <label class="form-label" for="aiKey_${name}_${prov}">${escHtml(text)}</label>
                    <input type="text" class="form-control" id="aiKey_${name}_${prov}"
                           data-${name}="${escHtml(prov)}" value="${escHtml(value)}"
                           placeholder="${escHtml(placeholder)}"${required ? ' required' : ''}
                           aria-label="${escHtml(text)} ของ ${escHtml(labelOf(prov))}">
                </div>`;

            box.innerHTML = '';
            providers.forEach(p => {
                const row = document.createElement('div');
                row.className = 'ai-key-row';
                row.dataset.provider = p;
                const cur = existing[p];
                const extra = [];
                if (p === 'custom') {
                    extra.push(field(p, 'URL', 'base', (cur && cur.base_url) || '', 'https://example.com/v1'));
                    extra.push(field(p, 'ชื่อโมเดล (จำเป็น)', 'model', (cur && cur.model) || '', 'เช่น llama3.2', true));
                }
                if (chatProviders.includes(p) && p !== 'custom') {
                    const hint = defaults[p] ? 'ค่าเริ่มต้น: ' + defaults[p] : 'เช่น llama3.2';
                    extra.push(field(p, 'ชื่อโมเดล', 'model', (cur && cur.model) || '', hint));
                }
                row.innerHTML = `
                    <div class="ai-key-head">
                        <strong>${escHtml(labelOf(p))}</strong>
                        ${cur ? `<span class="badge badge-success">บันทึกแล้ว</span>` : `<span class="badge">ยังไม่ได้ตั้ง</span>`}
                    </div>
                    <div class="ai-key-body">
                        <input type="password" class="form-control" autocomplete="off" aria-label="API Key ของ ${escHtml(labelOf(p))}" placeholder="${cur ? escHtml(cur.masked) : 'sk-... / AIza... / r8_...'}" data-prov="${escHtml(p)}">
                        ${extra.join('')}
                        <button class="btn btn-primary btn-sm" data-save="${escHtml(p)}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-test="${escHtml(p)}">ทดสอบ</button>
                        ${cur ? `<button class="btn btn-ghost btn-sm" data-del="${escHtml(p)}">ลบ</button>` : ''}
                    </div>
                    <div class="ai-key-msg" data-msg="${escHtml(p)}"></div>
                `;
                box.appendChild(row);
            });

            /** ค่าช่อง URL/โมเดลของแถว (ช่องที่ไม่มี = '') */
            const fieldValue = (row, name) => {
                const el = row.querySelector('input[data-' + name + ']');
                return el ? el.value.trim() : '';
            };

            box.querySelectorAll('[data-save]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.save;
                    const row = box.querySelector(`.ai-key-row[data-provider="${prov}"]`);
                    const val = row.querySelector('input[data-prov]').value.trim();
                    const cur = existing[prov];
                    if (!val && !(cur && cur.set)) return toast('กรุณากรอก API key', 'error');

                    const body = { provider: prov, api_key: val };
                    body.model = fieldValue(row, 'model');
                    body.base_url = fieldValue(row, 'base');
                    if (!val) body.keep_key = true;

                    try {
                        await apiFetch(BASE_URL + '/api/ai/keys', {
                            method: 'POST',
                            body: JSON.stringify(body)
                        });
                        toast('บันทึก ' + labelOf(prov) + ' สำเร็จ');
                        // หน้าโหลดมาก่อนมีคีย์: ต้องโหลดใหม่ให้แท็บ "สั่งงาน" ขึ้น
                        if (!document.querySelector('.ai-tab[data-tab="chat"]') && chatProviders.includes(prov)) {
                            location.reload();
                            return;
                        }
                        loadKeys();
                    } catch (e) { toast(e.message, 'error'); }
                });
            });
            box.querySelectorAll('[data-test]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.test;
                    const row = box.querySelector(`.ai-key-row[data-provider="${prov}"]`);
                    const msgEl = box.querySelector(`[data-msg="${prov}"]`);
                    const body = { provider: prov, api_key: row.querySelector('input[data-prov]').value.trim() };
                    body.model = fieldValue(row, 'model');
                    body.base_url = fieldValue(row, 'base');

                    msgEl.innerHTML = '<span class="ai-note">กำลังทดสอบ…</span>';
                    btn.disabled = true;
                    try {
                        const r = await apiFetch(BASE_URL + '/api/ai/keys/test', {
                            method: 'POST',
                            body: JSON.stringify(body)
                        });
                        msgEl.innerHTML = '<span class="ai-ok">ใช้งานได้ · ' + escHtml(r.message || 'ผ่านการทดสอบ') + '</span>';
                    } catch (e) {
                        msgEl.innerHTML = '<span class="ai-bad">ใช้ไม่ได้ · ' + escHtml(e.message) + '</span>';
                    } finally {
                        btn.disabled = false;
                    }
                });
            });
            box.querySelectorAll('[data-del]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const prov = btn.dataset.del;
                    if (!await confirmAction('ลบ API key ของ ' + labelOf(prov) + '?', 'ลบ')) return;
                    try {
                        await apiFetch(BASE_URL + '/api/ai/keys/' + prov, { method: 'DELETE' });
                        toast('ลบแล้ว');
                        loadKeys();
                    } catch (e) { toast(e.message, 'error'); }
                });
            });

            fillTextProvider(data);
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger" role="alert">' + escHtml(e.message) + '</div>';
        }
    }

    /**
     * เติมตัวเลือก #aiTextProvider จากผู้ให้บริการที่คุยได้
     * เหลือเฉพาะเจ้าที่มีคีย์ ถ้าไม่มีคีย์เลยใช้ทุกเจ้า และคงค่าที่เลือกไว้เดิม
     */
    function fillTextProvider(data) {
        const sel = document.getElementById('aiTextProvider');
        if (!sel) return;
        const chat = data.chat_providers || [];
        const labels = data.labels || {};
        const keys = data.keys || {};
        const withKey = chat.filter(p => keys[p] && keys[p].set);
        const use = withKey.length ? withKey : chat;
        const current = sel.value;
        sel.textContent = '';
        use.forEach(p => {
            const option = document.createElement('option');
            option.value = p;
            option.textContent = labels[p] || p;
            sel.appendChild(option);
        });
        if (use.includes(current)) sel.value = current;
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
                                <span class="badge badge-dark">ฉาก ${escHtml(s.scene || (i + 1))}</span>
                                <span class="ai-note">${escHtml(s.time || '')}</span>
                                <button type="button" class="btn btn-ghost btn-sm ml-auto" data-use-visual="${i}">ใช้เป็น Prompt สร้างวิดีโอ</button>
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
        status.innerHTML = '<div class="ai-note">กำลังส่งคำขอ...</div>';
        document.getElementById('aiVideoPlayer').innerHTML = '';

        try {
            const data = await apiFetch(BASE_URL + '/api/ai/video', {
                method: 'POST',
                body: JSON.stringify({
                    prompt,
                    model: val('aiVideoModel')
                })
            });
            status.innerHTML = '<div class="ai-note">กำลังสร้างวิดีโอ... (โดยทั่วไป 1-5 นาที)</div>';
            pollVideoStatus(data.id, btn, status);
        } catch (e) {
            btn.disabled = false;
            status.innerHTML = '<div class="ai-bad">' + escHtml(e.message) + '</div>';
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
                    status.innerHTML = '<div class="ai-ok">วิดีโอเสร็จแล้ว</div>';
                    document.getElementById('aiVideoPlayer').innerHTML = `
                        <video class="ai-video" src="${escHtml(data.video_url)}" controls></video>
                        <div class="mt-2"><a href="${escHtml(data.video_url)}" download class="btn btn-ghost btn-sm">ดาวน์โหลด</a></div>
                    `;
                    return;
                }
                if (data.status === 'failed') {
                    btn.disabled = false;
                    status.innerHTML = '<div class="ai-bad">สร้างวิดีโอไม่สำเร็จ: ' + escHtml(data.error || 'ไม่ทราบสาเหตุ') + '</div>';
                    return;
                }
                if (tries >= MAX) {
                    btn.disabled = false;
                    status.innerHTML = '<div class="ai-warn">รอนานเกินไป ลองดูในแท็บ "ประวัติ" อีกครั้งภายหลัง</div>';
                    return;
                }
                status.innerHTML = `<div class="ai-note">กำลังสร้าง... (${tries * 3} วิ)</div>`;
                videoPollTimer = setTimeout(check, 3000);
            } catch (e) {
                btn.disabled = false;
                status.innerHTML = '<div class="ai-bad">' + escHtml(e.message) + '</div>';
            }
        };
        videoPollTimer = setTimeout(check, 3000);
    }

    // ---------- History ----------
    let historyItems = [];

    async function loadHistory() {
        const box = document.getElementById('aiHistoryList');
        box.innerHTML = '<div class="ai-note">กำลังโหลด...</div>';
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/history');
            historyItems = data.items || [];
            renderHistory(historyItems);
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger" role="alert">' + escHtml(e.message) + '</div>';
        }
    }

    function renderHistory(items) {
        const box = document.getElementById('aiHistoryList');
        if (!items.length) {
            box.innerHTML = '<div class="ai-note">ไม่พบรายการ</div>';
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
                        <div class="ai-note">${kindLabel} · ${escHtml(it.platform || '-')} · ${escHtml(it.created_at)} ${statusBadge}</div>
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
                if (genPane && !genPane.hidden) {
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
