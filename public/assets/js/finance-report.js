/* =====================================================
   finance-report.js — the PDF report

   The report is a document of its own, printed from a hidden frame, so it cannot
   borrow the page's stylesheet: it carries its own inline styles and its own
   plain palette (and Sarabun, the face fonts.css still ships for it). That is why
   it is the one place in the app that still writes colours into markup.
===================================================== */

/* =====================================================
   PDF Export Feature
   ===================================================== */

let currentExportType = 'month';

function openExportModal() {
    currentExportType = 'month';
    // Start from the month on screen.
    document.getElementById('exportMonthValue').value = currentMonth;

    // Set default dates for range selection
    const todayStr = todayISO();
    const [yr, mn] = todayStr.split('-');
    document.getElementById('exportStartDate').value = `${yr}-${mn}-01`;
    document.getElementById('exportEndDate').value = todayStr;

    setExportType('month');
    openModal('exportPdfModal');
}

function setExportType(type) {
    currentExportType = type;

    document.querySelectorAll('#exportTypeToggle button').forEach(btn => {
        btn.setAttribute('aria-pressed', btn.dataset.type === type ? 'true' : 'false');
    });

    document.getElementById('exportMonthGroup').hidden = type !== 'month';
    document.getElementById('exportRangeGroup').hidden = type !== 'range';
}

function buildPrintTemplate(list, totals, periodText) {
    const today = new Date().toLocaleDateString('th-TH', {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });

    // Sort transactions chronologically
    const sortedList = [...list].sort((a, b) => new Date(a.txn_date) - new Date(b.txn_date));

    // Group expenses by category
    const expenses = sortedList.filter(t => t.type === 'expense');
    const catTotals = {};
    let totalExpenseSum = 0;
    expenses.forEach(e => {
        const cat = e.category_name || 'ไม่ระบุ';
        const amt = parseFloat(e.amount || 0);
        catTotals[cat] = (catTotals[cat] || 0) + amt;
        totalExpenseSum += amt;
    });

    const categoriesArray = Object.keys(catTotals).map((name, index) => {
        const amt = catTotals[name];
        const pct = totalExpenseSum > 0 ? (amt / totalExpenseSum) * 100 : 0;
        return { name, amount: amt, percentage: pct, index };
    }).sort((a, b) => b.amount - a.amount);

    const spendingRate = totals.income > 0 ? (totals.expense / totals.income) * 100 : 0;
    let spendingRateText = 'ไม่มีข้อมูล';
    let spendingRateColor = '#6b7280';
    if (totals.income > 0) {
        spendingRateText = spendingRate.toFixed(1) + '%';
        if (spendingRate <= 50) spendingRateColor = '#22c55e';
        else if (spendingRate <= 70) spendingRateColor = '#3b82f6';
        else if (spendingRate <= 90) spendingRateColor = '#f59e0b';
        else spendingRateColor = '#ef4444';
    }

    const categoryRows = categoriesArray.map(c => {
        const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#f97316', '#ec4899', '#14b8a6', '#64748b'];
        const barColor = colors[c.index % colors.length];
        return `
            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px;">
                    <span style="font-weight: 500; color: #4b5563;">${escHtml(c.name)}</span>
                    <span style="font-weight: 600; color: #111827;">${formatMoney(c.amount)} บ. (${c.percentage.toFixed(0)}%)</span>
                </div>
                <div style="height: 6px; background: #e5e7eb; border-radius: 99px; overflow: hidden;">
                    <div style="width: ${c.percentage}%; background-color: ${barColor}; height: 100%;"></div>
                </div>
            </div>
        `;
    }).join('') || '<div style="font-size: 11px; color: #9ca3af; text-align: center; padding: 12px 0;">ไม่มีรายจ่ายในช่วงเวลานี้</div>';

    const transactionRows = sortedList.map(t => {
        const thaiDate = formatDate(t.txn_date);
        const typeLabel = t.type === 'income' ? 'รายรับ' : 'รายจ่าย';
        const typeColor = t.type === 'income' ? '#15803d' : '#b91c1c';
        const typeBg = t.type === 'income' ? '#d1fae5' : '#fee2e2';
        const amtPrefix = t.type === 'income' ? '+' : '-';
        const amtColor = t.type === 'income' ? '#16a34a' : '#111827';

        return `
            <tr style="border-bottom: 1px solid #e5e7eb;">
                <td style="padding: 8px 10px; font-size: 11px; color: #4b5563;">${escHtml(thaiDate)}</td>
                <td style="padding: 8px 10px;">
                    <span style="display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 9px; font-weight: 700; background: ${typeBg}; color: ${typeColor};">
                        ${typeLabel}
                    </span>
                </td>
                <td style="padding: 8px 10px; font-size: 11px; color: #4b5563;">
                    <span style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 500;">
                        ${escHtml(t.category_name || 'ไม่ระบุ')}
                    </span>
                </td>
                <td style="padding: 8px 10px; font-size: 11px; font-weight: 500; color: #111827; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    ${escHtml(t.description || '—')}
                </td>
                <td style="padding: 8px 10px; text-align: right; font-size: 11px; font-weight: 700; color: ${amtColor}; white-space: nowrap;">
                    ${amtPrefix}${formatMoney(t.amount)}
                </td>
            </tr>
        `;
    }).join('');

    return `
        <div style="font-family: 'Sarabun', sans-serif; color: #111827; background: #ffffff; padding: 20px; line-height: 1.5; box-sizing: border-box;">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e5e7eb; padding-bottom: 14px; margin-bottom: 20px;">
                <div>
                    <h1 style="font-size: 20px; font-weight: 700; margin: 0 0 6px 0; color: #1e3a8a; letter-spacing: -0.02em;">รายงานสรุปการเงิน</h1>
                    <div style="font-size: 11px; color: #4b5563; font-weight: 500;">
                        ระยะเวลารายงาน: <span style="color: #111827; font-weight: 700;">${escHtml(periodText)}</span>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 16px; font-weight: 700; color: #1e3a8a; letter-spacing: -0.01em;"></div>
                    <div style="font-size: 9px; color: #6b7280; margin-top: 2px;">ออกเอกสาร: ${escHtml(today)} น.</div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #166534; margin-bottom: 4px; letter-spacing: 0.05em;">รายรับ</div>
                    <div style="font-size: 14px; font-weight: 700; color: #166534;">+${formatMoney(totals.income)}</div>
                </div>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #991b1b; margin-bottom: 4px; letter-spacing: 0.05em;">รายจ่าย</div>
                    <div style="font-size: 14px; font-weight: 700; color: #991b1b;">-${formatMoney(totals.expense)}</div>
                </div>
                <div style="background: ${totals.balance >= 0 ? '#eff6ff' : '#fff7ed'}; border: 1px solid ${totals.balance >= 0 ? '#bfdbfe' : '#fed7aa'}; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: ${totals.balance >= 0 ? '#1e40af' : '#854d0e'}; margin-bottom: 4px; letter-spacing: 0.05em;">คงเหลือ</div>
                    <div style="font-size: 14px; font-weight: 700; color: ${totals.balance >= 0 ? '#1e40af' : '#b45309'};">${formatMoney(totals.balance)}</div>
                </div>
                <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #4b5563; margin-bottom: 4px; letter-spacing: 0.05em;">ใช้จ่าย</div>
                    <div style="font-size: 14px; font-weight: 700; color: ${spendingRateColor};">${spendingRateText}</div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div style="margin-bottom: 24px;">
                <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px;">
                    <h2 style="font-size: 12px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 1px dashed #e5e7eb; padding-bottom: 6px; color: #1e3a8a;">
                        สัดส่วนรายจ่ายแยกตามหมวดหมู่
                    </h2>
                    ${categoryRows}
                </div>
            </div>

            <!-- Detailed Transactions Table -->
            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                <h2 style="font-size: 12px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 1px dashed #e5e7eb; padding-bottom: 6px; color: #1e3a8a;">
                    ประวัติธุรกรรมการทำรายการโดยละเอียด
                </h2>
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e5e7eb; background: #f9fafb;">
                            <th style="padding: 6px 10px; font-size: 10px; font-weight: 700; color: #374151;">วันที่</th>
                            <th style="padding: 6px 10px; font-size: 10px; font-weight: 700; color: #374151;">ประเภท</th>
                            <th style="padding: 6px 10px; font-size: 10px; font-weight: 700; color: #374151;">หมวดหมู่</th>
                            <th style="padding: 6px 10px; font-size: 10px; font-weight: 700; color: #374151;">รายการ / หมายเหตุ</th>
                            <th style="padding: 6px 10px; font-size: 10px; font-weight: 700; color: #374151; text-align: right;">จำนวน (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${transactionRows}
                    </tbody>
                </table>
            </div>

            <!-- Footer Note -->
            <div style="margin-top: 40px; text-align: center; font-size: 9px; color: #9ca3af; border-top: 1px solid #f3f4f6; padding-top: 12px;">
                เอกสารนี้สรุปข้อมูลผ่านระบบการจัดการการเงินส่วนบุคคล ปลอดภัย เป็นข้อมูลส่วนตัว และไม่มีการนำออกภายนอกระบบ
            </div>
        </div>
    `;
}

async function generatePdfReport() {
    const btn = document.getElementById('btnExportSubmit');
    if (!btn) return;
    const originalText = btn.textContent;

    try {
        btn.disabled = true;
        btn.textContent = 'กำลังเตรียมเอกสาร...';

        let url = BASE_URL + '/api/finance';
        let periodText = '';

        if (currentExportType === 'month') {
            const mVal = document.getElementById('exportMonthValue').value;
            if (!mVal) {
                toast('เลือกเดือนที่จะส่งออกก่อน', 'danger');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            url += '?month=' + encodeURIComponent(mVal);

            const [y, m] = mVal.split('-');
            const thaiMonthsFull = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
                'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
            periodText = `ประจำเดือน ${thaiMonthsFull[parseInt(m) - 1]} ${parseInt(y) + 543}`;
        } else {
            const start = document.getElementById('exportStartDate').value;
            const end = document.getElementById('exportEndDate').value;
            if (!start || !end) {
                toast('เลือกวันที่เริ่มและวันที่สิ้นสุดให้ครบ', 'danger');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            if (new Date(start) > new Date(end)) {
                toast('วันที่เริ่มต้องไม่หลังวันที่สิ้นสุด', 'danger');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            url += `?start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
            periodText = `ช่วงวันที่ ${formatDate(start)} ถึง ${formatDate(end)}`;
        }

        // Fetch transactions for report
        const data = await apiFetch(url);
        const txns = data.transactions || [];

        if (txns.length === 0) {
            toast('ไม่มีรายการในช่วงที่เลือก ลองเลือกช่วงอื่น', 'danger');
            btn.disabled = false;
            btn.textContent = originalText;
            return;
        }

        // Calculate totals
        let income = 0;
        let expense = 0;
        txns.forEach(t => {
            const amt = parseFloat(t.amount || 0);
            if (t.type === 'income') income += amt;
            else if (t.type === 'expense') expense += amt;
        });
        const summaryInfo = {
            income,
            expense,
            balance: income - expense
        };

        // Build report HTML
        btn.textContent = 'กำลังสร้างรายงาน...';
        const reportHtml = buildPrintTemplate(txns, summaryInfo, periodText);

        // Use hidden iframe + browser print for perfect Thai text rendering
        const iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:none;opacity:0;pointer-events:none;';
        document.body.appendChild(iframe);

        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        iframeDoc.open();
        iframeDoc.write(`<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานการเงิน — ${escHtml(periodText)}</title>
    <link href="${BASE_URL}/assets/css/fonts.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Sarabun', sans-serif;
            background: #ffffff;
            color: #111827;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        @media print {
            body { margin: 0; padding: 12mm; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>${reportHtml}</body>
</html>`);
        iframeDoc.close();

        // Wait for iframe content and fonts to load, then print
        closeModal('exportPdfModal');

        const triggerPrint = () => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            // Cleanup after print dialog closes
            const cleanup = () => {
                try { document.body.removeChild(iframe); } catch (e) { }
            };
            // Listen for focus return (print dialog closed)
            window.addEventListener('focus', function onFocus() {
                window.removeEventListener('focus', onFocus);
                setTimeout(cleanup, 500);
            }, { once: true });
            // Fallback cleanup
            setTimeout(cleanup, 60000);
        };

        // Wait for font loading in iframe
        if (iframe.contentDocument.fonts && iframe.contentDocument.fonts.ready) {
            iframe.contentDocument.fonts.ready.then(() => {
                setTimeout(triggerPrint, 300);
            });
        } else {
            // Fallback: wait a fixed time
            setTimeout(triggerPrint, 1500);
        }

        toast('เลือก "Microsoft Print to PDF" หรือ "Save as PDF" แล้วกด Save เพื่อดาวน์โหลด');
    } catch (err) {
        console.error(err);
        toast(err.message || 'สร้างรายงานไม่สำเร็จ', 'danger');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

