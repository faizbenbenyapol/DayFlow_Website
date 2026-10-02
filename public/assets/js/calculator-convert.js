// =====================================================
// assets/js/calculator-convert.js — Unit conversion, health and date calculators
// Loaded after calculator.js, which provides window.DFCalc.
// =====================================================
(function () {
    'use strict';

    const DFCalc = window.DFCalc;
    const { $, fmt, num, hasAll, setResult, getInputs } = DFCalc;

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

    Object.assign(DFCalc.recalc, { recalcHealth, recalcDate });
    DFCalc.init.convert = initConvert;
})();
