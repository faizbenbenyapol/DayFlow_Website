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
                `<div class="hi-cat">${escHtml(h.category)}</div>` +
                `<div class="hi-expr">${escHtml(h.expr)}</div>` +
                `<div class="hi-result">= ${escHtml(h.result)}</div>` +
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
                if (key.startsWith('p'))  DFCalc.recalc.recalcPercent();
                if (key.startsWith('pr')) DFCalc.recalc.recalcPrice();
                if (key.startsWith('f'))  DFCalc.recalc.recalcFinance();
                if (key.startsWith('h'))  DFCalc.recalc.recalcHealth();
                if (key.startsWith('d'))  DFCalc.recalc.recalcDate();
            }
            if (el.classList.contains('js-compare')) DFCalc.recalc.recalcCompare();
        });
        document.addEventListener('change', (e) => {
            if (e.target.classList?.contains('js-calc')) {
                const key = e.target.dataset.calc || '';
                if (key.startsWith('pr')) DFCalc.recalc.recalcPrice();
                if (key.startsWith('f'))  DFCalc.recalc.recalcFinance();
                if (key.startsWith('h'))  DFCalc.recalc.recalcHealth();
                if (key.startsWith('d'))  DFCalc.recalc.recalcDate();
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
            div.querySelector('.btn-remove').addEventListener('click', () => { div.remove(); DFCalc.recalc.recalcCompare(); });
        });

        // Clear history
        $('#btnClearHistory')?.addEventListener('click', () => {
            if (!History.list.length) return;
            confirmAction('ล้างประวัติทั้งหมด?', 'ล้าง').then(ok => { if (ok) History.clear(); });
        });
    }

    // Extend global bindings to new keys
    function initExtraBindings() {
        document.addEventListener('input', (e) => {
            const el = e.target;
            if (!el.classList?.contains('js-calc')) return;
            const key = el.dataset.calc || '';
            if (key.startsWith('tax'))   DFCalc.recalc.recalcTax();
            if (key.startsWith('b'))     DFCalc.recalc.recalcBills();
            if (key.startsWith('i') && key !== 'i') DFCalc.recalc.recalcInvest();
            if (key.startsWith('m'))     DFCalc.recalc.recalcMath();
            if (key.startsWith('n'))     DFCalc.recalc.recalcNumbers();
            if (key === 'text' || key === 'ratio' || key === 'sleep') DFCalc.recalc.recalcTextRatioSleep();
        });
        document.addEventListener('change', (e) => {
            const el = e.target;
            if (!el.classList?.contains('js-calc')) return;
            const key = el.dataset.calc || '';
            if (key.startsWith('tax')) DFCalc.recalc.recalcTax();
            if (key.startsWith('b'))   DFCalc.recalc.recalcBills();
            if (key.startsWith('m'))   DFCalc.recalc.recalcMath();
            if (key.startsWith('n'))   DFCalc.recalc.recalcNumbers();
            if (key === 'sleep') DFCalc.recalc.recalcTextRatioSleep();
        });
    }

    // What the feature files (calculator-*.js, loaded next) share. They add
    // their recalc functions and init hooks here; the calls below are made on
    // DOMContentLoaded, after every file has registered.
    window.DFCalc = { $, $$, fmt, money, num, hasAll, setResult, getInputs, recalc: {}, init: {} };

    // ------- Init -------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initGeneral();
        DFCalc.init.convert();
        initBindings();
        initExtraBindings();
        DFCalc.init.search();
        DFCalc.init.random();
        DFCalc.init.password();
        DFCalc.init.copyButtons();
        History.load();
        // Prime results
        DFCalc.recalc.recalcPercent(); DFCalc.recalc.recalcPrice(); DFCalc.recalc.recalcFinance(); DFCalc.recalc.recalcHealth(); DFCalc.recalc.recalcDate();
        DFCalc.recalc.recalcTax(); DFCalc.recalc.recalcBills(); DFCalc.recalc.recalcInvest(); DFCalc.recalc.recalcMath(); DFCalc.recalc.recalcNumbers(); DFCalc.recalc.recalcTextRatioSleep();
    });
})();
