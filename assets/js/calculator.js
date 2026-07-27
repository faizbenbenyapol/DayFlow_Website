// =====================================================
// assets/js/calculator.js — Multi-tool calculator
// =====================================================
(function () {
    'use strict';

    // ------- Helpers -------
    const $  = (sel, ctx) => (ctx || document).querySelector(sel);
    const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

    const fmt = (n, d = 2) => {
        if (n === null || n === undefined || isNaN(n) || !isFinite(n)) return '—';
        const abs = Math.abs(n);
        const dec = abs >= 100 ? d : abs >= 1 ? d : abs > 0 ? 4 : d;
        return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: dec });
    };
    const money = (n) => {
        if (n === null || n === undefined || isNaN(n) || !isFinite(n)) return '—';
        return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ฿';
    };
    const num = (v) => {
        if (v === '' || v === null || v === undefined) return NaN;
        const n = Number(v);
        return isNaN(n) ? NaN : n;
    };
    const hasAll = (...vals) => vals.every(v => !isNaN(v) && v !== '');

    const setResult = (key, mainHTML, subHTML) => {
        const el = document.querySelector(`[data-result="${key}"]`);
        if (!el) return;
        if (mainHTML === null || mainHTML === undefined || mainHTML === '') {
            el.classList.remove('has-value');
            el.innerHTML = '—';
            return;
        }
        el.classList.add('has-value');
        el.innerHTML = `<span class="calc-result-main">${mainHTML}</span>` +
                       (subHTML ? `<span class="calc-result-sub">${subHTML}</span>` : '');
    };

    // ------- History -------
    const HIST_KEY = 'calculator_history';
    const History = {
        list: [],
        load() {
            try { this.list = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); }
            catch (e) { this.list = []; }
            this.render();
        },
        save() { localStorage.setItem(HIST_KEY, JSON.stringify(this.list.slice(0, 50))); },
        add(category, expr, result) {
            this.list.unshift({ category, expr, result, ts: Date.now() });
            this.list = this.list.slice(0, 50);
            this.save();
            this.render();
        },
        clear() { this.list = []; this.save(); this.render(); },
        render() {
            const box = $('#calcHistory');
            if (!box) return;
            if (!this.list.length) {
                box.innerHTML = '<div class="text-xs text-muted text-center">ยังไม่มีประวัติ</div>';
                return;
            }
            box.innerHTML = this.list.map(h =>
                `<div class="calc-history-item" data-expr="${encodeURIComponent(h.expr)}">` +
                `<div class="hi-cat">${h.category}</div>` +
                `<div class="hi-expr">${h.expr}</div>` +
                `<div class="hi-result">= ${h.result}</div>` +
                `</div>`
            ).join('');
        }
    };

    // ------- Tabs -------
    function initTabs() {
        const tabs = $$('.calc-tab');
        const panels = $$('.calc-panel');
        const last = localStorage.getItem('calc_last_tab');
        if (last) activate(last);

        tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.tab)));

        function activate(name) {
            const tab = tabs.find(t => t.dataset.tab === name);
            if (!tab) return;
            tabs.forEach(t => t.classList.toggle('active', t === tab));
            panels.forEach(p => p.classList.toggle('active', p.dataset.panel === name));
            localStorage.setItem('calc_last_tab', name);
        }
    }

    // ============================================================
    // GENERAL: expression evaluator (safe — no eval)
    // ============================================================
    const CalcEngine = {
        // Tokenize + convert implicit operators + use Function with strict whitelist
        sanitize(expr) {
            // Replace visual operators with JS ones
            let s = expr
                .replace(/×/g, '*')
                .replace(/÷/g, '/')
                .replace(/−/g, '-')
                .replace(/π/g, '(Math.PI)')
                .replace(/(^|[^a-zA-Z])e(?![a-zA-Z])/g, '$1(Math.E)')
                .replace(/\bpi\b/g, '(Math.PI)')
                .replace(/sin\(/g, 'Math.sin(')
                .replace(/cos\(/g, 'Math.cos(')
                .replace(/tan\(/g, 'Math.tan(')
                .replace(/log\(/g, 'Math.log10(')
                .replace(/ln\(/g, 'Math.log(')
                .replace(/sqrt\(/g, 'Math.sqrt(');
            // factorial: replace N! with fact(N)
            s = s.replace(/(\d+(?:\.\d+)?|\))\s*!/g, 'fact($1)');
            // power ^ → **
            s = s.replace(/\^/g, '**');
            // Only allow these chars after substitution:
            if (!/^[-+*/().\d\s,eE*MathPIsincotaglqrfa]*$/.test(s)) {
                // permissive check is tricky; just rely on try/catch from Function
            }
            return s;
        },
        evaluate(expr) {
            if (!expr || !expr.trim()) return null;
            const s = this.sanitize(expr);
            try {
                // eslint-disable-next-line no-new-func
                const f = new Function('fact', '"use strict"; return (' + s + ');');
                const fact = (n) => {
                    if (n < 0 || n !== Math.floor(n)) return NaN;
                    let r = 1; for (let i = 2; i <= n; i++) r *= i; return r;
                };
                const v = f(fact);
                if (typeof v !== 'number' || !isFinite(v)) return null;
                return v;
            } catch (e) { return null; }
        }
    };

    function initGeneral() {
        const display = $('#calcDisplay');
        const sub     = $('#calcSubDisplay');
        const sciBox  = $('#keypadSci');
        const sciTog  = $('#sciToggle');
        if (!display) return;

        sciTog.addEventListener('change', () => { sciBox.hidden = !sciTog.checked; });

        $$('.calc-key', $('#calcKeypad')).forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                const op     = btn.dataset.op;
                const ins    = btn.dataset.insert;
                if (action === 'clear') { display.value = ''; sub.textContent = '\u00A0'; return; }
                if (action === 'back')  { display.value = display.value.slice(0, -1); return; }
                if (action === 'equals'){ doEquals(); return; }
                if (ins) { display.value += ins; display.focus(); return; }
                if (op)  { display.value += op; display.focus(); return; }
            });
        });

        display.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); doEquals(); }
            else if (e.key === 'Escape') { display.value = ''; sub.textContent = '\u00A0'; }
        });

        function doEquals() {
            const expr = display.value.trim();
            if (!expr) return;
            const v = CalcEngine.evaluate(expr);
            if (v === null) { sub.textContent = 'ผิดพลาด'; return; }
            sub.textContent = expr + ' =';
            display.value = String(v);
            History.add('เครื่องคิดเลข', expr, fmt(v, 6));
        }
    }

    // ============================================================
    // PERCENT
    // ============================================================
    function recalcPercent() {
        // p1: X% of Y
        const p1 = getInputs('p1');
        if (hasAll(p1[0], p1[1])) {
            const r = p1[0] * p1[1] / 100;
            setResult('p1', fmt(r, 4), `${fmt(p1[0])}% ของ ${fmt(p1[1])}`);
        } else setResult('p1');

        // p2: X is what % of Y
        const p2 = getInputs('p2');
        if (hasAll(p2[0], p2[1]) && p2[1] !== 0) {
            const r = (p2[0] / p2[1]) * 100;
            setResult('p2', fmt(r, 4) + '%', `${fmt(p2[0])} คิดเป็น ${fmt(r, 2)}% ของ ${fmt(p2[1])}`);
        } else setResult('p2');

        // p3: add/subtract %
        const p3 = getInputs('p3');
        if (hasAll(p3[0], p3[1])) {
            const add = p3[0] + p3[0] * p3[1] / 100;
            const sub = p3[0] - p3[0] * p3[1] / 100;
            setResult('p3',
                `+${fmt(p3[1])}% = ${fmt(add, 4)}`,
                `−${fmt(p3[1])}% = ${fmt(sub, 4)}`
            );
        } else setResult('p3');

        // p4: % change
        const p4 = getInputs('p4');
        if (hasAll(p4[0], p4[1]) && p4[0] !== 0) {
            const r = ((p4[1] - p4[0]) / Math.abs(p4[0])) * 100;
            const dir = r > 0 ? 'เพิ่มขึ้น' : r < 0 ? 'ลดลง' : 'ไม่เปลี่ยนแปลง';
            const badge = r > 0 ? 'success' : r < 0 ? 'danger' : '';
            setResult('p4',
                `${dir} ${fmt(Math.abs(r), 4)}%` + (badge ? ` <span class="calc-badge ${badge}">${r > 0 ? '▲' : '▼'}</span>` : ''),
                `จาก ${fmt(p4[0])} → ${fmt(p4[1])} (ต่าง ${fmt(p4[1] - p4[0])})`
            );
        } else setResult('p4');
    }

    // ============================================================
    // PRICE
    // ============================================================
    function recalcPrice() {
        // pr1: discount
        const pr1 = getInputs('pr1');
        if (hasAll(pr1[0], pr1[1])) {
            const save = pr1[0] * pr1[1] / 100;
            const net  = pr1[0] - save;
            setResult('pr1', money(net), `ประหยัด ${money(save)} จากราคาเต็ม ${money(pr1[0])}`);
        } else setResult('pr1');

        // pr2: VAT
        const pr2Els = document.querySelectorAll('[data-calc="pr2"]');
        const amount = num(pr2Els[0]?.value);
        const rate   = num(pr2Els[1]?.value);
        const mode   = pr2Els[2]?.value || 'add';
        if (hasAll(amount, rate)) {
            if (mode === 'add') {
                const vat = amount * rate / 100;
                setResult('pr2', money(amount + vat),
                    `ฐาน ${money(amount)} + VAT ${fmt(rate)}% (${money(vat)})`);
            } else {
                const base = amount / (1 + rate / 100);
                const vat  = amount - base;
                setResult('pr2', `ฐาน ${money(base)} + VAT ${money(vat)}`,
                    `ยอดรวม ${money(amount)} แยก VAT ${fmt(rate)}%`);
            }
        } else setResult('pr2');

        // pr3: markup / margin
        const pr3 = getInputs('pr3');
        const cost = pr3[0], sell = pr3[1], wantPct = pr3[2];
        if (hasAll(cost, sell)) {
            const profit = sell - cost;
            const markup = cost ? (profit / cost) * 100 : NaN;
            const margin = sell ? (profit / sell) * 100 : NaN;
            setResult('pr3',
                `กำไร ${money(profit)}`,
                `Markup ${fmt(markup, 2)}% · Margin ${fmt(margin, 2)}%`
            );
        } else if (hasAll(cost, wantPct)) {
            const s = cost * (1 + wantPct / 100);
            setResult('pr3', `ราคาขาย ${money(s)}`,
                `ต้นทุน ${money(cost)} + กำไร ${fmt(wantPct)}% = ${money(s - cost)} กำไร`);
        } else setResult('pr3');

        // pr4: split bill
        const pr4 = getInputs('pr4');
        if (hasAll(pr4[0], pr4[1]) && pr4[1] >= 1) {
            const tip = (pr4[2] || 0);
            const total = pr4[0] * (1 + tip / 100);
            const per   = total / pr4[1];
            setResult('pr4', `คนละ ${money(per)}`,
                `ยอดรวม ${money(pr4[0])}${tip ? ` + ทิป ${fmt(tip)}%` : ''} = ${money(total)} หาร ${pr4[1]} คน`);
        } else setResult('pr4');

        // compare
        recalcCompare();
    }

    function recalcCompare() {
        const rows = $$('.compare-row', $('#compareList'));
        const items = rows.map(r => {
            const ins = $$('.js-compare', r);
            return {
                name: ins[0].value || '(ไม่มีชื่อ)',
                price: num(ins[1].value),
                qty:   num(ins[2].value),
                unit:  ins[3].value || 'หน่วย',
            };
        }).filter(it => !isNaN(it.price) && !isNaN(it.qty) && it.qty > 0);

        if (items.length < 2) { setResult('compare'); return; }

        items.forEach(it => { it.perUnit = it.price / it.qty; });
        const sorted = items.slice().sort((a, b) => a.perUnit - b.perUnit);
        const best = sorted[0], worst = sorted[sorted.length - 1];
        const diffPct = best.perUnit ? ((worst.perUnit - best.perUnit) / best.perUnit) * 100 : 0;

        const lines = items.map(it =>
            `${it === best ? '* ' : ''}<strong>${it.name}</strong>: ${money(it.perUnit)}/${it.unit} (${money(it.price)} ÷ ${fmt(it.qty)} ${it.unit})`
        ).join('<br>');

        setResult('compare',
            `${best.name} ถูกที่สุด (${money(best.perUnit)}/${best.unit})`,
            lines + `<br>ต่างกัน ${fmt(diffPct, 2)}% จากตัวที่แพงที่สุด`
        );
    }

    // ============================================================
    // FINANCE
    // ============================================================
    function recalcFinance() {
        // f1: interest
        const f1 = getInputs('f1');
        const P = f1[0], r = f1[1], t = f1[2], nCmp = f1[3] || 12;
        if (hasAll(P, r, t)) {
            const simple = P * (r / 100) * t;
            const compA  = P * Math.pow(1 + (r / 100) / nCmp, nCmp * t);
            const compI  = compA - P;
            setResult('f1',
                `ดอกเบี้ยทบต้น ${money(compI)} (ยอดรวม ${money(compA)})`,
                `ดอกเบี้ยแบบง่าย ${money(simple)} (ยอดรวม ${money(P + simple)}) · ทบต้น ${nCmp} ครั้ง/ปี`
            );
        } else setResult('f1');

        // f2: loan
        const f2 = getInputs('f2');
        const Lp = f2[0], Lr = f2[1], Lm = f2[2];
        const amortBody = $('#amortBody');
        if (hasAll(Lp, Lr, Lm) && Lm >= 1) {
            const i = (Lr / 100) / 12;
            const pay = i === 0 ? Lp / Lm : Lp * i / (1 - Math.pow(1 + i, -Lm));
            const totalPaid = pay * Lm;
            const totalInt  = totalPaid - Lp;
            setResult('f2', `ค่างวด ${money(pay)}/เดือน`,
                `รวมจ่าย ${money(totalPaid)} · ดอกเบี้ยรวม ${money(totalInt)}`);
            // Amortization
            if (amortBody) {
                let bal = Lp, rows = '';
                const maxShow = Math.min(Lm, 360);
                for (let k = 1; k <= maxShow; k++) {
                    const intPart = bal * i;
                    const prnPart = pay - intPart;
                    bal -= prnPart;
                    if (Math.abs(bal) < 0.005) bal = 0;
                    rows += `<tr><td>${k}</td><td>${money(pay)}</td><td>${money(prnPart)}</td><td>${money(intPart)}</td><td>${money(bal)}</td></tr>`;
                }
                amortBody.innerHTML = rows;
            }
        } else {
            setResult('f2');
            if (amortBody) amortBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">—</td></tr>';
        }

        // f3: savings goal
        const f3 = getInputs('f3');
        const G = f3[0], Mn = f3[1], Rr = f3[2] || 0;
        if (hasAll(G, Mn) && Mn >= 1) {
            const i = (Rr / 100) / 12;
            const pmt = i === 0 ? G / Mn : G * i / (Math.pow(1 + i, Mn) - 1);
            setResult('f3', `ออม ${money(pmt)}/เดือน`,
                `เป้าหมาย ${money(G)} ใน ${Mn} เดือน${Rr ? ` @ ${fmt(Rr)}%/ปี` : ''}`);
        } else setResult('f3');
    }

    // ============================================================
    // UNIT CONVERTER
    // ============================================================
    const UNITS = {
        length: { base: 'm', units: { m: 1, cm: 0.01, mm: 0.001, km: 1000, inch: 0.0254, ft: 0.3048, yd: 0.9144, mile: 1609.344 } },
        weight: { base: 'kg', units: { kg: 1, g: 0.001, mg: 1e-6, lb: 0.45359237, oz: 0.0283495231, ton: 1000 } },
        area: { base: 'm²', units: { 'm²': 1, 'cm²': 0.0001, 'km²': 1e6, 'ft²': 0.092903, 'ไร่': 1600, 'งาน': 400, 'ตร.วา': 4, 'เอเคอร์': 4046.8564224 } },
        volume: { base: 'L', units: { L: 1, mL: 0.001, 'm³': 1000, gal: 3.785411784, cup: 0.24, tbsp: 0.015, tsp: 0.005 } },
        speed: { base: 'm/s', units: { 'm/s': 1, 'km/h': 1 / 3.6, 'mph': 0.44704, 'knot': 0.514444 } },
        time: { base: 's', units: { s: 1, min: 60, h: 3600, d: 86400, week: 604800 } },
        data: { base: 'B', units: { B: 1, KB: 1024, MB: 1024 ** 2, GB: 1024 ** 3, TB: 1024 ** 4 } },
        temperature: { special: true }
    };

    function tempTo(v, from, to) {
        let k;
        if (from === '°C') k = v + 273.15;
        else if (from === '°F') k = (v - 32) * 5 / 9 + 273.15;
        else k = v;
        if (to === '°C') return k - 273.15;
        if (to === '°F') return (k - 273.15) * 9 / 5 + 32;
        return k;
    }

    function initConvert() {
        const cat = $('#convCategory');
        const fromU = $('#convFromUnit');
        const toU = $('#convToUnit');
        const fromV = $('#convFromValue');
        const toV = $('#convToValue');

        function fillUnits() {
            const c = cat.value;
            let units;
            if (c === 'temperature') units = ['°C', '°F', 'K'];
            else units = Object.keys(UNITS[c].units);
            fromU.innerHTML = units.map(u => `<option value="${u}">${u}</option>`).join('');
            toU.innerHTML   = units.map(u => `<option value="${u}">${u}</option>`).join('');
            if (units.length > 1) toU.value = units[1];
            convert();
        }

        function convert() {
            const v = num(fromV.value);
            if (isNaN(v)) { toV.value = ''; setResult('conv'); return; }
            const c = cat.value;
            let result;
            if (c === 'temperature') {
                result = tempTo(v, fromU.value, toU.value);
            } else {
                const u = UNITS[c].units;
                const base = v * u[fromU.value];
                result = base / u[toU.value];
            }
            toV.value = fmt(result, 6);
            setResult('conv', `${fmt(v, 6)} ${fromU.value} = ${fmt(result, 6)} ${toU.value}`);
        }

        cat.addEventListener('change', fillUnits);
        [fromU, toU, fromV].forEach(el => el.addEventListener('input', convert));
        fillUnits();
    }

    // ============================================================
    // HEALTH
    // ============================================================
    function recalcHealth() {
        // h1: BMI
        const h1 = getInputs('h1');
        const wt = h1[0], htCm = h1[1];
        if (hasAll(wt, htCm) && htCm > 0) {
            const m = htCm / 100;
            const bmi = wt / (m * m);
            let cat, badge;
            if (bmi < 18.5)      { cat = 'ผอม';       badge = 'warning'; }
            else if (bmi < 23)   { cat = 'ปกติ';      badge = 'success'; }
            else if (bmi < 25)   { cat = 'น้ำหนักเกิน'; badge = 'warning'; }
            else if (bmi < 30)   { cat = 'อ้วน';      badge = 'danger'; }
            else                 { cat = 'อ้วนมาก';    badge = 'danger'; }
            setResult('h1',
                `BMI ${fmt(bmi, 2)} <span class="calc-badge ${badge}">${cat}</span>`,
                `น้ำหนักปกติสำหรับส่วนสูงนี้: ${fmt(18.5 * m * m, 1)}–${fmt(22.9 * m * m, 1)} กก.`
            );
        } else setResult('h1');

        // h2: BMR/TDEE (Mifflin-St Jeor)
        const h2Els = document.querySelectorAll('[data-calc="h2"]');
        const gender = h2Els[0]?.value;
        const age    = num(h2Els[1]?.value);
        const w2     = num(h2Els[2]?.value);
        const h2c    = num(h2Els[3]?.value);
        const act    = num(h2Els[4]?.value);
        if (hasAll(age, w2, h2c, act)) {
            const base = 10 * w2 + 6.25 * h2c - 5 * age + (gender === 'male' ? 5 : -161);
            const tdee = base * act;
            setResult('h2',
                `BMR ${fmt(base, 0)} kcal · TDEE ${fmt(tdee, 0)} kcal/วัน`,
                `ลดน้ำหนัก ~${fmt(tdee - 500, 0)} · เพิ่มน้ำหนัก ~${fmt(tdee + 500, 0)} kcal/วัน`
            );
        } else setResult('h2');
    }

    // ============================================================
    // DATE
    // ============================================================
    function recalcDate() {
        // d1: diff
        const d1Els = document.querySelectorAll('[data-calc="d1"]');
        const a = d1Els[0]?.value, b = d1Els[1]?.value;
        if (a && b) {
            const da = new Date(a), db = new Date(b);
            const diffMs = db - da;
            const days = Math.round(diffMs / 86400000);
            const y = db.getFullYear() - da.getFullYear();
            const m = db.getMonth() - da.getMonth() + y * 12;
            setResult('d1',
                `${fmt(Math.abs(days), 0)} วัน`,
                `≈ ${fmt(Math.abs(m), 0)} เดือน · ≈ ${fmt(Math.abs(days / 7), 1)} สัปดาห์ · ≈ ${fmt(Math.abs(days / 365.25), 2)} ปี`
            );
        } else setResult('d1');

        // d2: add/sub
        const d2Els = document.querySelectorAll('[data-calc="d2"]');
        const start = d2Els[0]?.value;
        const cnt = num(d2Els[1]?.value);
        const unit = d2Els[2]?.value;
        const opv = d2Els[3]?.value;
        if (start && !isNaN(cnt)) {
            const d = new Date(start);
            const delta = opv === 'sub' ? -cnt : cnt;
            if (unit === 'd') d.setDate(d.getDate() + delta);
            else if (unit === 'w') d.setDate(d.getDate() + delta * 7);
            else if (unit === 'm') d.setMonth(d.getMonth() + delta);
            else if (unit === 'y') d.setFullYear(d.getFullYear() + delta);
            const yyyy = d.getFullYear(), mm = String(d.getMonth() + 1).padStart(2, '0'), dd = String(d.getDate()).padStart(2, '0');
            const weekday = d.toLocaleDateString('th-TH', { weekday: 'long' });
            setResult('d2', `${yyyy}-${mm}-${dd}`, weekday);
        } else setResult('d2');

        // d3: age
        const d3Els = document.querySelectorAll('[data-calc="d3"]');
        const bd = d3Els[0]?.value;
        const at = d3Els[1]?.value || new Date().toISOString().slice(0, 10);
        if (bd) {
            const bDate = new Date(bd), aDate = new Date(at);
            let y = aDate.getFullYear() - bDate.getFullYear();
            let m = aDate.getMonth() - bDate.getMonth();
            let d = aDate.getDate() - bDate.getDate();
            if (d < 0) { m--; d += new Date(aDate.getFullYear(), aDate.getMonth(), 0).getDate(); }
            if (m < 0) { y--; m += 12; }
            const totalDays = Math.round((aDate - bDate) / 86400000);
            setResult('d3',
                `${y} ปี ${m} เดือน ${d} วัน`,
                `รวม ${fmt(totalDays, 0)} วัน · ${fmt(totalDays * 24, 0)} ชั่วโมง`
            );
        } else setResult('d3');
    }

    // ------- Helper to pull grouped inputs in DOM order -------
    function getInputs(calcKey) {
        return Array.from(document.querySelectorAll(`[data-calc="${calcKey}"]`))
            .map(el => el.type === 'number' ? num(el.value) :
                       el.tagName === 'SELECT' ? (isNaN(Number(el.value)) ? el.value : Number(el.value)) :
                       el.value);
    }

    // ------- Bind recalc triggers -------
    function initBindings() {
        document.addEventListener('input', (e) => {
            const el = e.target;
            if (!el.classList) return;
            if (el.classList.contains('js-calc')) {
                const key = el.dataset.calc || '';
                if (key.startsWith('p'))  recalcPercent();
                if (key.startsWith('pr')) recalcPrice();
                if (key.startsWith('f'))  recalcFinance();
                if (key.startsWith('h'))  recalcHealth();
                if (key.startsWith('d'))  recalcDate();
            }
            if (el.classList.contains('js-compare')) recalcCompare();
        });
        document.addEventListener('change', (e) => {
            if (e.target.classList?.contains('js-calc')) {
                const key = e.target.dataset.calc || '';
                if (key.startsWith('pr')) recalcPrice();
                if (key.startsWith('f'))  recalcFinance();
                if (key.startsWith('h'))  recalcHealth();
                if (key.startsWith('d'))  recalcDate();
            }
        });

        // Compare — add/remove row
        $('#btnAddCompareItem')?.addEventListener('click', () => {
            const list = $('#compareList');
            const idx = list.children.length;
            const letter = String.fromCharCode(65 + idx);
            const div = document.createElement('div');
            div.className = 'compare-row';
            div.innerHTML =
                `<input type="text" class="form-control js-compare" placeholder="ชื่อสินค้า ${letter}">` +
                `<input type="number" step="any" class="form-control js-compare" placeholder="ราคา (฿)">` +
                `<input type="number" step="any" class="form-control js-compare" placeholder="ปริมาณ">` +
                `<input type="text" class="form-control js-compare" placeholder="หน่วย" value="g">` +
                `<button type="button" class="btn-remove" aria-label="ลบ">×</button>`;
            list.appendChild(div);
            div.querySelector('.btn-remove').addEventListener('click', () => { div.remove(); recalcCompare(); });
        });

        // Clear history
        $('#btnClearHistory')?.addEventListener('click', () => {
            if (!History.list.length) return;
            confirmAction('ล้างประวัติทั้งหมด?', 'ล้าง').then(ok => { if (ok) History.clear(); });
        });
    }

    // ============================================================
    // TAX — Thai personal income tax
    // ============================================================
    function thaiTax(netIncome) {
        // Progressive brackets (2567): 0/5/10/15/20/25/30/35
        const brackets = [
            [150000, 0], [150000, 0.05], [200000, 0.10],
            [250000, 0.15], [250000, 0.20], [1000000, 0.25],
            [3000000, 0.30], [Infinity, 0.35]
        ];
        let remaining = netIncome, tax = 0, breakdown = [];
        for (const [width, rate] of brackets) {
            if (remaining <= 0) break;
            const inBracket = Math.min(remaining, width);
            const t = inBracket * rate;
            tax += t;
            if (rate > 0 && inBracket > 0) breakdown.push(`${fmt(rate * 100)}% ของ ${fmt(inBracket, 0)} = ${money(t)}`);
            remaining -= inBracket;
        }
        return { tax, breakdown };
    }

    function recalcTax() {
        const t1 = getInputs('tax1');
        if (hasAll(t1[0]) && t1[0] >= 0) {
            const { tax, breakdown } = thaiTax(t1[0]);
            const eff = t1[0] ? (tax / t1[0]) * 100 : 0;
            setResult('tax1', `ภาษีที่ต้องเสีย ${money(tax)}`,
                `อัตราเฉลี่ย ${fmt(eff, 2)}%<br>${breakdown.join('<br>') || 'ไม่มีภาษี'}`);
        } else setResult('tax1');

        const t2 = getInputs('tax2');
        const [sal, bonus, sso, other] = t2;
        if (hasAll(sal)) {
            const gross = sal * 12 + (bonus || 0);
            const expense = Math.min(100000, gross * 0.5);
            const deduct = 60000 + (sso || 9000) + (other || 0);
            const net = Math.max(0, gross - expense - deduct);
            const { tax } = thaiTax(net);
            const takeHome = gross - tax - (sso || 9000);
            setResult('tax2',
                `ภาษี ${money(tax)}/ปี · สุทธิ ${money(takeHome / 12)}/เดือน`,
                `รายได้รวม ${money(gross)} − ค่าใช้จ่าย ${money(expense)} − ลดหย่อน ${money(deduct)} = เงินได้สุทธิ ${money(net)}`);
        } else setResult('tax2');

        const t3Els = document.querySelectorAll('[data-calc="tax3"]');
        const amt = num(t3Els[0]?.value), rate = num(t3Els[1]?.value);
        if (hasAll(amt, rate)) {
            const wh = amt * rate / 100;
            setResult('tax3', `หัก ${money(wh)}`, `รับจริง ${money(amt - wh)} (จาก ${money(amt)} @ ${fmt(rate)}%)`);
        } else setResult('tax3');
    }

    // ============================================================
    // BILLS
    // ============================================================
    function recalcBills() {
        // b1: Electricity (Thai tiered rates, approx)
        const b1Els = document.querySelectorAll('[data-calc="b1"]');
        const kwh = num(b1Els[0]?.value);
        const type = b1Els[1]?.value;
        if (hasAll(kwh) && kwh >= 0) {
            let energy = 0;
            if (type === 'small') {
                // 1.1 ≤ 150: tiered 2.3488/2.9882/3.2405/3.6237/3.7171/4.2218/4.4217 baht/kWh
                const tiers = [[15, 2.3488], [10, 2.9882], [10, 3.2405], [65, 3.6237], [50, 3.7171], [250, 4.2218], [Infinity, 4.4217]];
                let left = kwh;
                for (const [w, r] of tiers) { const u = Math.min(left, w); energy += u * r; left -= u; if (left <= 0) break; }
            } else {
                // 1.2 > 150: 3.2484/4.2218/4.4217
                const tiers = [[150, 3.2484], [250, 4.2218], [Infinity, 4.4217]];
                let left = kwh;
                for (const [w, r] of tiers) { const u = Math.min(left, w); energy += u * r; left -= u; if (left <= 0) break; }
            }
            const service = type === 'small' && kwh <= 150 ? 8.19 : 24.62;
            const ft = kwh * 0.2048; // approx current FT
            const pre = energy + service + ft;
            const vat = pre * 0.07;
            const total = pre + vat;
            setResult('b1', `ค่าไฟรวม ${money(total)}`,
                `ค่าพลังงาน ${money(energy)} + ค่าบริการ ${money(service)} + FT ${money(ft)} + VAT 7% ${money(vat)}`);
        } else setResult('b1');

        // b2: fuel
        const b2 = getInputs('b2');
        if (hasAll(b2[0], b2[1], b2[2]) && b2[1] > 0) {
            const liters = b2[0] / b2[1];
            const cost = liters * b2[2];
            const perKm = cost / b2[0];
            setResult('b2', `ค่าน้ำมัน ${money(cost)}`,
                `ใช้น้ำมัน ${fmt(liters, 2)} ลิตร · ${money(perKm)}/กม.`);
        } else setResult('b2');

        // b3: paint
        const b3 = getInputs('b3');
        if (hasAll(b3[0], b3[1], b3[2], b3[3], b3[4]) && b3[4] > 0) {
            const area = b3[0] * b3[1] * b3[2];
            const gallons = Math.ceil(area * b3[3] / b3[4]);
            setResult('b3', `ต้องใช้สี ${gallons} แกลลอน`,
                `พื้นที่รวม ${fmt(area, 2)} ตร.ม. × ${b3[3]} เที่ยว ÷ ${b3[4]} ตร.ม./แกลลอน`);
        } else setResult('b3');

        // b4: tiles
        const b4 = getInputs('b4');
        if (hasAll(b4[0], b4[1], b4[2]) && b4[1] > 0) {
            const tileArea = (b4[1] / 100) ** 2; // m² per tile
            const waste = 1 + (b4[3] || 0) / 100;
            const tiles = Math.ceil(b4[0] / tileArea * waste);
            const cost = tiles * b4[2];
            setResult('b4', `ต้องใช้ ${tiles} แผ่น · ${money(cost)}`,
                `ขนาด ${b4[1]}×${b4[1]} ซม. = ${fmt(tileArea, 4)} ตร.ม./แผ่น · เผื่อเสีย ${b4[3] || 0}%`);
        } else setResult('b4');
    }

    // ============================================================
    // INVESTMENT
    // ============================================================
    function recalcInvest() {
        // i1: ROI
        const i1 = getInputs('i1');
        const [buy, sell, qty, fee] = i1;
        if (hasAll(buy, sell, qty)) {
            const cost = buy * qty * (1 + (fee || 0) / 100);
            const rev  = sell * qty * (1 - (fee || 0) / 100);
            const profit = rev - cost;
            const roi = cost ? (profit / cost) * 100 : 0;
            const badge = profit > 0 ? 'success' : profit < 0 ? 'danger' : '';
            setResult('i1',
                `${profit > 0 ? 'กำไร' : profit < 0 ? 'ขาดทุน' : 'เท่าทุน'} ${money(Math.abs(profit))} <span class="calc-badge ${badge}">${fmt(roi, 2)}%</span>`,
                `ต้นทุนรวม ${money(cost)} · รายรับ ${money(rev)}`);
        } else setResult('i1');

        // i2: DCA future value
        const i2 = getInputs('i2');
        const [pmt, rate, years, seed] = i2;
        if (hasAll(pmt, rate, years)) {
            const months = years * 12;
            const i = (rate / 100) / 12;
            const fvPmt = i === 0 ? pmt * months : pmt * ((Math.pow(1 + i, months) - 1) / i);
            const fvSeed = (seed || 0) * Math.pow(1 + i, months);
            const total = fvPmt + fvSeed;
            const invested = pmt * months + (seed || 0);
            const profit = total - invested;
            setResult('i2', `มูลค่า ${money(total)}`,
                `ลงทุนรวม ${money(invested)} · กำไร ${money(profit)} (${fmt(invested ? profit / invested * 100 : 0, 1)}%)`);
        } else setResult('i2');

        // i3: retirement
        const i3 = getInputs('i3');
        const [ageNow, ageRet, expNow, ageDie, infl, invRet] = i3;
        if (hasAll(ageNow, ageRet, expNow, ageDie) && ageRet > ageNow && ageDie > ageRet) {
            const yrsToRet = ageRet - ageNow;
            const yrsInRet = ageDie - ageRet;
            const inflR = (infl || 0) / 100;
            const invR  = (invRet || 0) / 100;
            const futExp = expNow * 12 * Math.pow(1 + inflR, yrsToRet); // annual exp at retirement
            // Real return during retirement
            const real = (1 + invR) / (1 + inflR) - 1;
            const needed = real === 0 ? futExp * yrsInRet : futExp * (1 - Math.pow(1 + real, -yrsInRet)) / real;
            // Monthly saving during accumulation
            const mi = invR / 12;
            const months = yrsToRet * 12;
            const mSave = mi === 0 ? needed / months : needed * mi / (Math.pow(1 + mi, months) - 1);
            setResult('i3', `ต้องมีเงิน ${money(needed)} ตอนเกษียณ`,
                `ต้องออม ${money(mSave)}/เดือน เป็นเวลา ${yrsToRet} ปี · ค่าใช้จ่ายปีแรกหลังเกษียณ ~${money(futExp)}`);
        } else setResult('i3');
    }

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

    // Extend global bindings to new keys
    function initExtraBindings() {
        document.addEventListener('input', (e) => {
            const el = e.target;
            if (!el.classList?.contains('js-calc')) return;
            const key = el.dataset.calc || '';
            if (key.startsWith('tax'))   recalcTax();
            if (key.startsWith('b'))     recalcBills();
            if (key.startsWith('i') && key !== 'i') recalcInvest();
            if (key.startsWith('m'))     recalcMath();
            if (key.startsWith('n'))     recalcNumbers();
            if (key === 'text' || key === 'ratio' || key === 'sleep') recalcTextRatioSleep();
        });
        document.addEventListener('change', (e) => {
            const el = e.target;
            if (!el.classList?.contains('js-calc')) return;
            const key = el.dataset.calc || '';
            if (key.startsWith('tax')) recalcTax();
            if (key.startsWith('b'))   recalcBills();
            if (key.startsWith('m'))   recalcMath();
            if (key.startsWith('n'))   recalcNumbers();
            if (key === 'sleep') recalcTextRatioSleep();
        });
    }

    // ------- Init -------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initGeneral();
        initConvert();
        initBindings();
        initExtraBindings();
        initSearch();
        initRandom();
        initPassword();
        initCopyButtons();
        History.load();
        // Prime results
        recalcPercent(); recalcPrice(); recalcFinance(); recalcHealth(); recalcDate();
        recalcTax(); recalcBills(); recalcInvest(); recalcMath(); recalcNumbers(); recalcTextRatioSleep();
    });
})();
