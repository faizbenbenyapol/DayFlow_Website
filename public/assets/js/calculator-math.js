// =====================================================
// assets/js/calculator-math.js — Math, numbers, text/ratio/sleep calculators, random, password, search, copy buttons
// Loaded after calculator.js, which provides window.DFCalc.
// =====================================================
(function () {
    'use strict';

    const DFCalc = window.DFCalc;
    const { $, $$, fmt, num, hasAll, setResult, getInputs } = DFCalc;

    // ============================================================
    // MATH
    // ============================================================
    function recalcMath() {
        // m1: quadratic
        const m1 = getInputs('m1');
        const [a, b, c] = m1;
        if (hasAll(a, b, c) && a !== 0) {
            const d = b * b - 4 * a * c;
            let main, sub;
            if (d > 0) {
                const r1 = (-b + Math.sqrt(d)) / (2 * a);
                const r2 = (-b - Math.sqrt(d)) / (2 * a);
                main = `x₁ = ${fmt(r1, 4)}, x₂ = ${fmt(r2, 4)}`;
                sub = `มีสองคำตอบจริง · discriminant = ${fmt(d, 2)}`;
            } else if (d === 0) {
                const r = -b / (2 * a);
                main = `x = ${fmt(r, 4)}`;
                sub = 'มีคำตอบเดียว (ซ้ำ)';
            } else {
                const re = (-b / (2 * a)).toFixed(4);
                const im = (Math.sqrt(-d) / (2 * a)).toFixed(4);
                main = `x = ${re} ± ${im}i`;
                sub = 'คำตอบเป็นจำนวนเชิงซ้อน';
            }
            setResult('m1', main, sub);
        } else setResult('m1');

        // m2: pythagoras
        const m2 = getInputs('m2');
        const [pa, pb, pc] = m2;
        let out = null;
        if (hasAll(pa, pb) && isNaN(pc)) {
            const h = Math.sqrt(pa * pa + pb * pb);
            const angA = Math.atan2(pa, pb) * 180 / Math.PI;
            out = [`c = ${fmt(h, 4)}`, `มุม A = ${fmt(angA, 2)}° · มุม B = ${fmt(90 - angA, 2)}° · พื้นที่ = ${fmt(pa * pb / 2, 4)}`];
        } else if (hasAll(pa, pc) && isNaN(pb) && pc > pa) {
            const bb = Math.sqrt(pc * pc - pa * pa);
            out = [`b = ${fmt(bb, 4)}`, `มุม A = ${fmt(Math.asin(pa / pc) * 180 / Math.PI, 2)}°`];
        } else if (hasAll(pb, pc) && isNaN(pa) && pc > pb) {
            const aa = Math.sqrt(pc * pc - pb * pb);
            out = [`a = ${fmt(aa, 4)}`, `มุม B = ${fmt(Math.asin(pb / pc) * 180 / Math.PI, 2)}°`];
        }
        if (out) setResult('m2', out[0], out[1]); else setResult('m2');

        // m3: circle
        const m3 = getInputs('m3');
        if (hasAll(m3[0])) {
            const r = m3[0];
            setResult('m3',
                `พื้นที่ = ${fmt(Math.PI * r * r, 4)}`,
                `เส้นรอบวง = ${fmt(2 * Math.PI * r, 4)} · เส้นผ่านศูนย์กลาง = ${fmt(2 * r)}`);
        } else setResult('m3');

        // m4: shape area
        const m4Els = document.querySelectorAll('[data-calc="m4"]');
        const shape = m4Els[0]?.value;
        const v1 = num(m4Els[1]?.value), v2 = num(m4Els[2]?.value), v3 = num(m4Els[3]?.value);
        let area = null, label = '';
        if (shape === 'rect' && hasAll(v1, v2)) { area = v1 * v2; label = 'กว้าง × ยาว'; }
        else if (shape === 'tri' && hasAll(v1, v2)) { area = v1 * v2 / 2; label = 'ฐาน × สูง ÷ 2'; }
        else if (shape === 'trap' && hasAll(v1, v2, v3)) { area = (v1 + v2) * v3 / 2; label = '(a+b) × h ÷ 2'; }
        else if (shape === 'para' && hasAll(v1, v2)) { area = v1 * v2; label = 'ฐาน × สูง'; }
        if (area !== null) setResult('m4', `พื้นที่ = ${fmt(area, 4)}`, label);
        else setResult('m4');
    }

    // ============================================================
    // NUMBERS
    // ============================================================
    function recalcNumbers() {
        // n1: base convert
        const n1Els = document.querySelectorAll('[data-calc="n1"]');
        const rawN1 = n1Els[0]?.value?.trim();
        const baseN1 = Number(n1Els[1]?.value);
        if (rawN1) {
            const dec = parseInt(rawN1, baseN1);
            if (!isNaN(dec)) {
                setResult('n1',
                    `DEC: ${dec.toLocaleString('en-US')}`,
                    `BIN: ${dec.toString(2)}<br>OCT: ${dec.toString(8)}<br>HEX: ${dec.toString(16).toUpperCase()}`);
            } else setResult('n1', null);
        } else setResult('n1');

        // n2: GCD/LCM
        const n2Raw = document.querySelector('[data-calc="n2"]')?.value || '';
        const nums = n2Raw.split(/[,\s]+/).map(Number).filter(n => !isNaN(n) && n > 0);
        if (nums.length >= 2) {
            const gcd = (a, b) => b === 0 ? a : gcd(b, a % b);
            const lcm = (a, b) => a * b / gcd(a, b);
            const g = nums.reduce(gcd), l = nums.reduce(lcm);
            setResult('n2', `ห.ร.ม. = ${g} · ค.ร.น. = ${l}`, `จากเลข: ${nums.join(', ')}`);
        } else setResult('n2');

        // n3: prime / factor
        const n3 = getInputs('n3');
        if (hasAll(n3[0]) && n3[0] >= 2 && n3[0] === Math.floor(n3[0])) {
            let n = n3[0];
            const factors = [];
            for (let p = 2; p * p <= n; p++) { while (n % p === 0) { factors.push(p); n /= p; } }
            if (n > 1) factors.push(n);
            const isPrime = factors.length === 1;
            setResult('n3',
                isPrime ? `${n3[0]} เป็นจำนวนเฉพาะ ✓` : `${n3[0]} ไม่ใช่จำนวนเฉพาะ`,
                `ตัวประกอบ: ${factors.join(' × ')}`);
        } else setResult('n3');

        // n4: statistics
        const n4Raw = document.querySelector('[data-calc="n4"]')?.value || '';
        const arr = n4Raw.split(/[,\s\n]+/).map(Number).filter(n => !isNaN(n));
        if (arr.length > 0) {
            const sum = arr.reduce((a, b) => a + b, 0);
            const mean = sum / arr.length;
            const sorted = arr.slice().sort((a, b) => a - b);
            const mid = Math.floor(sorted.length / 2);
            const median = sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
            const counts = {};
            arr.forEach(v => counts[v] = (counts[v] || 0) + 1);
            const maxC = Math.max(...Object.values(counts));
            const modes = Object.keys(counts).filter(k => counts[k] === maxC);
            const variance = arr.reduce((a, b) => a + (b - mean) ** 2, 0) / arr.length;
            const stdev = Math.sqrt(variance);
            setResult('n4',
                `ค่าเฉลี่ย ${fmt(mean, 4)}`,
                `n=${arr.length} · รวม=${fmt(sum, 4)} · มัธยฐาน=${fmt(median, 4)} · ฐานนิยม=${modes.join(',')}<br>`
                + `ต่ำสุด=${fmt(sorted[0])} · สูงสุด=${fmt(sorted[sorted.length - 1])} · SD=${fmt(stdev, 4)} · variance=${fmt(variance, 4)}`);
        } else setResult('n4');
    }

    // ============================================================
    // TOOLS (text, ratio, sleep, random, password)
    // ============================================================
    function recalcTextRatioSleep() {
        // text
        const txt = document.querySelector('[data-calc="text"]')?.value || '';
        if (txt) {
            const chars = [...txt].length;
            const charsNoSpace = [...txt.replace(/\s/g, '')].length;
            const words = txt.trim().split(/\s+/).filter(Boolean).length;
            const lines = txt.split('\n').length;
            const paragraphs = txt.split(/\n\s*\n/).filter(p => p.trim()).length;
            const readTime = Math.ceil(words / 200);
            setResult('text',
                `${chars.toLocaleString()} ตัวอักษร · ${words.toLocaleString()} คำ`,
                `ไม่รวมเว้นวรรค ${charsNoSpace.toLocaleString()} · ${lines} บรรทัด · ${paragraphs} ย่อหน้า · อ่านจบ ~${readTime} นาที`);
        } else setResult('text');

        // ratio
        const r = getInputs('ratio');
        if (hasAll(r[0], r[1], r[2]) && r[0] !== 0) {
            const x = r[1] * r[2] / r[0];
            setResult('ratio', `? = ${fmt(x, 4)}`, `${fmt(r[0])} : ${fmt(r[1])} = ${fmt(r[2])} : ${fmt(x, 4)}`);
        } else setResult('ratio');

        // sleep cycles
        const sEls = document.querySelectorAll('[data-calc="sleep"]');
        const mode = sEls[0]?.value;
        const time = sEls[1]?.value;
        if (time) {
            const [hh, mm] = time.split(':').map(Number);
            const base = new Date();
            base.setHours(hh, mm, 0, 0);
            const results = [];
            for (let c = 6; c >= 3; c--) {
                const t = new Date(base);
                const offset = 15 + c * 90; // fall-asleep + cycles
                if (mode === 'wake') t.setMinutes(t.getMinutes() - offset);
                else t.setMinutes(t.getMinutes() + offset);
                const hh2 = String(t.getHours()).padStart(2, '0');
                const mm2 = String(t.getMinutes()).padStart(2, '0');
                results.push(`${hh2}:${mm2} (${c} รอบ · ${(c * 1.5).toFixed(1)} ชม.)`);
            }
            setResult('sleep',
                mode === 'wake' ? 'ควรเข้านอนเวลา' : 'ควรตั้งนาฬิกาปลุก',
                results.join('<br>'));
        } else setResult('sleep');
    }

    function initRandom() {
        const render = (title, list) => {
            setResult('rand', title, list);
        };
        $('#btnRandom')?.addEventListener('click', () => {
            const min = parseInt($('#randMin').value) || 0;
            const max = parseInt($('#randMax').value) || 100;
            const cnt = Math.max(1, Math.min(100, parseInt($('#randCount').value) || 1));
            const uniq = $('#randUnique').value === '1';
            if (min > max) { setResult('rand', 'ค่า "ตั้งแต่" ต้องน้อยกว่า "ถึง"'); return; }
            const range = max - min + 1;
            if (uniq && cnt > range) { setResult('rand', `สุ่มไม่ซ้ำได้สูงสุด ${range} ตัว`); return; }
            const pool = [];
            if (uniq) {
                const all = Array.from({ length: range }, (_, i) => i + min);
                for (let i = all.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [all[i], all[j]] = [all[j], all[i]];
                }
                pool.push(...all.slice(0, cnt));
            } else {
                for (let i = 0; i < cnt; i++) pool.push(min + Math.floor(Math.random() * range));
            }
            render(pool.join(', '), `สุ่มจาก ${min}–${max} · ${cnt} ตัว${uniq ? ' ไม่ซ้ำ' : ''}`);
        });
        $('#btnFlipCoin')?.addEventListener('click', () => {
            const v = Math.random() < 0.5 ? 'หัว' : 'ก้อย';
            render(v, 'โยนเหรียญ');
        });
        $('#btnRollDice')?.addEventListener('click', () => {
            const v = 1 + Math.floor(Math.random() * 6);
            render(`${v}`, 'ทอยลูกเต๋า 6 หน้า');
        });
    }

    function initPassword() {
        $('#btnGenPw')?.addEventListener('click', () => {
            const len = Math.max(4, Math.min(128, parseInt($('#pwLen').value) || 16));
            const cnt = Math.max(1, Math.min(20, parseInt($('#pwCount').value) || 1));
            let pool = '';
            if ($('#pwUpper').checked) pool += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            if ($('#pwLower').checked) pool += 'abcdefghijklmnopqrstuvwxyz';
            if ($('#pwDigit').checked) pool += '0123456789';
            if ($('#pwSym').checked)   pool += '!@#$%^&*()-_=+[]{};:,.<>?';
            if ($('#pwExclude').checked) pool = pool.replace(/[0O1lI]/g, '');
            if (!pool) { setResult('pw', 'กรุณาเลือกประเภทตัวอักษร'); return; }
            const out = [];
            const crypto = window.crypto || window.msCrypto;
            for (let n = 0; n < cnt; n++) {
                let pw = '';
                const buf = new Uint32Array(len);
                if (crypto) crypto.getRandomValues(buf);
                for (let i = 0; i < len; i++) {
                    const r = crypto ? buf[i] : Math.floor(Math.random() * 0xffffffff);
                    pw += pool[r % pool.length];
                }
                out.push(pw);
            }
            // entropy
            const entropy = Math.log2(pool.length) * len;
            const strength = entropy < 40 ? 'อ่อน' : entropy < 60 ? 'ปานกลาง' : entropy < 80 ? 'แข็งแรง' : 'แข็งแรงมาก';
            const badge = entropy < 40 ? 'danger' : entropy < 60 ? 'warning' : 'success';
            setResult('pw',
                out.map(p => `<code style="font-size:1rem">${p}</code>`).join('<br>'),
                `<span class="calc-badge ${badge}">${strength}</span> entropy ${fmt(entropy, 0)} bits`);
        });
    }

    // ============================================================
    // SEARCH
    // ============================================================
    function initSearch() {
        const input = $('#calcSearch');
        if (!input) return;
        const tabs = $$('.calc-tab');
        const panels = $$('.calc-panel');
        const tools = $$('.calc-tool');

        function applySearch() {
            const q = input.value.trim().toLowerCase();
            if (!q) {
                tools.forEach(t => t.classList.remove('calc-hidden', 'calc-highlight'));
                panels.forEach(p => p.style.removeProperty('display'));
                return;
            }
            // Show all panels (so hits across tabs are visible)
            panels.forEach(p => { p.style.display = 'block'; });
            let firstHit = null;
            tools.forEach(t => {
                const title = t.querySelector('.card-title')?.textContent.toLowerCase() || '';
                const labels = Array.from(t.querySelectorAll('.form-label')).map(e => e.textContent.toLowerCase()).join(' ');
                const hit = title.includes(q) || labels.includes(q);
                t.classList.toggle('calc-hidden', !hit);
                if (hit && !firstHit) firstHit = t;
            });
            if (firstHit) {
                const panel = firstHit.closest('.calc-panel');
                const tabName = panel?.dataset.panel;
                tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
            }
        }

        input.addEventListener('input', applySearch);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { input.value = ''; applySearch(); input.blur(); }
        });
        // Global shortcut: "/" focuses search
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT' &&
                document.activeElement.tagName !== 'TEXTAREA' && document.activeElement.tagName !== 'SELECT') {
                e.preventDefault();
                input.focus();
            }
        });
    }

    // ============================================================
    // COPY BUTTONS
    // ============================================================
    function initCopyButtons() {
        // Inject copy button into every result box
        $$('.calc-result').forEach(el => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'calc-copy-btn';
            btn.textContent = 'คัดลอก';
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const text = el.innerText.replace('คัดลอก', '').trim();
                try {
                    await navigator.clipboard.writeText(text);
                    btn.textContent = '✓ แล้ว';
                    btn.classList.add('copied');
                    setTimeout(() => { btn.textContent = 'คัดลอก'; btn.classList.remove('copied'); }, 1500);
                } catch (_) {
                    const ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); } catch (_) {}
                    ta.remove();
                    btn.textContent = '✓';
                }
            });
            el.appendChild(btn);
        });
    }

    Object.assign(DFCalc.recalc, { recalcMath, recalcNumbers, recalcTextRatioSleep });
    DFCalc.init.random = initRandom;
    DFCalc.init.password = initPassword;
    DFCalc.init.search = initSearch;
    DFCalc.init.copyButtons = initCopyButtons;
})();
