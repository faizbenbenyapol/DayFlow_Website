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
        const copy = el.querySelector('.calc-copy-btn');
        if (mainHTML === null || mainHTML === undefined || mainHTML === '') {
            el.classList.remove('has-value');
            el.innerHTML = '—';
        } else {
            el.classList.add('has-value');
            el.innerHTML = `<span class="calc-result-main">${mainHTML}</span>` +
                           (subHTML ? `<span class="calc-result-sub">${subHTML}</span>` : '');
        }
        if (copy) el.appendChild(copy);
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
                box.innerHTML = '<p class="calc-history-empty">ยังไม่มีประวัติ ผลที่คำนวณจะมาอยู่ตรงนี้</p>';
                return;
            }
            box.innerHTML = this.list.map(h =>
                `<button type="button" class="calc-history-item" data-expr="${encodeURIComponent(h.expr)}" title="ใช้นิพจน์นี้อีกครั้ง">` +
                `<div class="hi-cat">${escHtml(h.category)}</div>` +
                `<div class="hi-expr">${escHtml(h.expr)}</div>` +
                `<div class="hi-result">= ${escHtml(h.result)}</div>` +
                `</button>`
            ).join('');
        }
    };

    // ------- Tabs -------
    function selectTab(name, remember) {
        const tabs = $$('#calcTabs .tab');
        const tab = tabs.find(t => t.dataset.tab === name);
        if (!tab) return;
        tabs.forEach(t => {
            t.setAttribute('aria-selected', String(t === tab));
            t.tabIndex = t === tab ? 0 : -1;
        });
        $$('.calc-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === name));
        if (remember) { try { localStorage.setItem('calc_last_tab', name); } catch (_) { /* not remembered */ } }
    }

    function initTabs() {
        const tabs = $$('#calcTabs .tab');
        let last = null;
        try { last = localStorage.getItem('calc_last_tab'); } catch (_) { /* first tab */ }
        selectTab(last && tabs.some(t => t.dataset.tab === last) ? last : tabs[0].dataset.tab);

        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => selectTab(tab.dataset.tab, true));
            tab.addEventListener('keydown', e => {
                const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (!step) return;
                e.preventDefault();
                const next = tabs[(i + step + tabs.length) % tabs.length];
                selectTab(next.dataset.tab, true);
                next.focus();
            });
        });
    }

    /** Ties each label to the field that follows it, so a click on the label reaches the field. */
    function linkLabels() {
        let n = 0;
        $$('.form-group').forEach(group => {
            const label = group.querySelector('label.form-label:not([for])');
            const field = group.querySelector('input:not([type="hidden"]), select, textarea');
            if (!label || !field) return;
            if (!field.id) field.id = 'calc-field-' + (++n);
            label.htmlFor = field.id;
        });
    }

    // ============================================================
    // GENERAL: expression evaluator (safe — no eval)
    // ============================================================
    const CalcEngine = {
        FUNCTIONS: {
            sin: Math.sin, cos: Math.cos, tan: Math.tan,
            log: Math.log10, ln: Math.log, sqrt: Math.sqrt,
        },

        tokenize(expr) {
            const s = expr.toLowerCase()
                .replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/π/g, ' pi ');
            const tokens = [];
            const pattern = /\s*(?:(\d+\.?\d*|\.\d+)|([a-z]+)|([-+*/^!(),]))/gy;
            let m;
            let at = 0;
            while (at < s.length && (m = pattern.exec(s)) !== null) {
                at = pattern.lastIndex;
                if (m[1] !== undefined) tokens.push({ n: parseFloat(m[1]) });
                else if (m[2] !== undefined) tokens.push({ w: m[2] });
                else tokens.push({ o: m[3] });
            }
            if (s.slice(at).trim() !== '') throw new Error('unexpected input');
            return tokens;
        },

        // expr = term (("+" | "-") term)* ; term = unary (("*" | "/") unary)* ;
        // unary = ("-" | "+") unary | power ; power = postfix ("^" unary)? ;
        // postfix = primary "!"* ; primary = number | constant | name "(" expr ")" | "(" expr ")"
        parse(tokens) {
            let i = 0;
            const peek = () => tokens[i];
            const isOp = o => tokens[i] && tokens[i].o === o;
            const fact = n => {
                if (n < 0 || n !== Math.floor(n) || n > 170) return NaN;
                let r = 1; for (let k = 2; k <= n; k++) r *= k; return r;
            };

            const expr = () => {
                let v = term();
                while (isOp('+') || isOp('-')) { const op = tokens[i++].o; const r = term(); v = op === '+' ? v + r : v - r; }
                return v;
            };
            const term = () => {
                let v = unary();
                while (isOp('*') || isOp('/')) { const op = tokens[i++].o; const r = unary(); v = op === '*' ? v * r : v / r; }
                return v;
            };
            const unary = () => {
                if (isOp('-')) { i++; return -unary(); }
                if (isOp('+')) { i++; return unary(); }
                return power();
            };
            const power = () => {
                const base = postfix();
                if (isOp('^')) { i++; return Math.pow(base, unary()); }
                return base;
            };
            const postfix = () => {
                let v = primary();
                while (isOp('!')) { i++; v = fact(v); }
                return v;
            };
            const primary = () => {
                const t = peek();
                if (!t) throw new Error('unexpected end');
                if (t.n !== undefined) { i++; return t.n; }
                if (t.o === '(') { i++; const v = expr(); if (!isOp(')')) throw new Error('missing )'); i++; return v; }
                if (t.w === 'pi') { i++; return Math.PI; }
                if (t.w === 'e')  { i++; return Math.E; }
                if (t.w && CalcEngine.FUNCTIONS[t.w]) {
                    i++;
                    if (!isOp('(')) throw new Error('missing (');
                    i++;
                    const v = expr();
                    if (!isOp(')')) throw new Error('missing )');
                    i++;
                    return CalcEngine.FUNCTIONS[t.w](v);
                }
                throw new Error('unexpected token');
            };

            const value = expr();
            if (i !== tokens.length) throw new Error('trailing input');
            return value;
        },

        evaluate(expr) {
            if (!expr || !expr.trim()) return null;
            try {
                const v = this.parse(this.tokenize(expr));
                return typeof v === 'number' && isFinite(v) ? v : null;
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

        // A history line goes back into the pocket calculator.
        $('#calcHistory')?.addEventListener('click', (e) => {
            const item = e.target.closest('.calc-history-item');
            if (!item) return;
            selectTab('general', true);
            const display = $('#calcDisplay');
            display.value = decodeURIComponent(item.dataset.expr);
            display.focus();
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
    window.DFCalc = { $, $$, fmt, money, num, hasAll, setResult, getInputs, selectTab, recalc: {}, init: {} };

    // ------- Init -------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        linkLabels();
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
