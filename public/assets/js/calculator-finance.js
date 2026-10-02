// =====================================================
// assets/js/calculator-finance.js — Percent, price, compare, finance, tax, bills and investment calculators
// Loaded after calculator.js, which provides window.DFCalc.
// =====================================================
(function () {
    'use strict';

    const DFCalc = window.DFCalc;
    const { $, $$, fmt, money, num, hasAll, setResult, getInputs } = DFCalc;

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
            `${it === best ? '* ' : ''}<strong>${escHtml(it.name)}</strong>: ${money(it.perUnit)}/${escHtml(it.unit)} (${money(it.price)} ÷ ${fmt(it.qty)} ${escHtml(it.unit)})`
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

    Object.assign(DFCalc.recalc, { recalcPercent, recalcPrice, recalcCompare, recalcFinance, recalcTax, recalcBills, recalcInvest });
})();
