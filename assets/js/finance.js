/* =====================================================
   finance.js — Dynamic Finance Tracker & Analytics
   ===================================================== */

let transactions = [];
let editingTxnId = null;
let financeChart = null;
let currentChartType = 'bar'; // default chart type: 'bar' or 'line'

const THAI_MONTHS_SHORT = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
                           'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

const PASTEL_COLORS = [
    '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', 
    '#06b6d4', '#f97316', '#ec4899', '#14b8a6', '#64748b'
];

document.addEventListener('DOMContentLoaded', async function () {
    const year = document.getElementById('chartYear')?.value;
    document.getElementById('txnType')?.addEventListener('change', filterCategoryOptions);
    document.getElementById('qaType')?.addEventListener('change', filterQaCategoryOptions);
    
    await Promise.all([
        loadTransactions(),
        loadChart(year)
    ]);
    
    // Initial category options filtering
    filterQaCategoryOptions();
});

async function loadSummary() {
    const month = document.getElementById('monthFilter')?.value || '';
    try {
        const data = await apiFetch(BASE_URL + '/api/finance/summary' + (month ? '?month=' + month : ''));
        
        const incomeVal = parseFloat(data.income || 0);
        const expenseVal = parseFloat(data.expense || 0);
        const balanceVal = parseFloat(data.balance || 0);

        document.getElementById('sumIncome').textContent  = formatMoney(incomeVal);
        document.getElementById('sumExpense').textContent = formatMoney(expenseVal);
        
        const bal = document.getElementById('sumBalance');
        bal.textContent = formatMoney(balanceVal);
        bal.className   = 'finance-stat-amount ' + (balanceVal >= 0 ? 'income' : 'expense');
        
        // Update spending ratio & financial health insights
        updateFinancialRatio(incomeVal, expenseVal);
    } catch {}
}

function updateFinancialRatio(income, expense) {
    const ratioCard = document.getElementById('financeRatioCard');
    const ratioBar = document.getElementById('spendingRatioBar');
    const ratioText = document.getElementById('spendingRatioText');
    const adviceText = document.getElementById('financialAdviceText');
    
    if (!ratioCard || !ratioBar || !ratioText || !adviceText) return;
    
    if (income <= 0) {
        if (expense > 0) {
            ratioCard.style.display = 'block';
            ratioBar.style.width = '100%';
            ratioBar.style.backgroundColor = 'var(--color-danger)';
            ratioText.textContent = 'เกินงบประมาณ (ไม่มีรายรับเดือนนี้)';
            adviceText.innerHTML = '⚠️ <strong>ระวังเป็นพิเศษ!</strong> มีแต่รายจ่ายโดยไม่มีรายรับเข้ามาในเดือนนี้ แนะนำให้ดึงงบจากเงินออมสำรองมาใช้ และควบคุมรายจ่ายอย่างเคร่งครัด';
        } else {
            ratioCard.style.display = 'none';
            adviceText.textContent = 'ยังไม่มีข้อมูลรายรับ-รายจ่ายของเดือนนี้ เริ่มต้นบันทึกข้อมูลเพื่อประเมินผล';
        }
        return;
    }
    
    ratioCard.style.display = 'block';
    const ratio = (expense / income) * 100;
    const boundedRatio = Math.min(ratio, 100);
    
    ratioBar.style.width = boundedRatio.toFixed(1) + '%';
    ratioText.textContent = `${ratio.toFixed(1)}% ของรายรับ (ใช้ไป ${formatMoney(expense)} จาก ${formatMoney(income)} บาท)`;
    
    // Smooth color change based on ratio severity
    if (ratio <= 50) {
        ratioBar.style.backgroundColor = 'var(--color-success)';
        adviceText.innerHTML = '✨ <strong>ยอดเยี่ยมมาก!</strong> อัตราการใช้จ่ายของคุณต่ำกว่า 50% ทำให้มีเงินออมเหลือมากกว่าครึ่งหนึ่งของรายรับ เป็นวินัยทางการเงินที่แข็งแกร่งมาก แนะนำให้นำเงินส่วนที่เหลือนี้ไปจัดสรรลงทุนเพื่อต่อยอดครับ';
    } else if (ratio <= 70) {
        ratioBar.style.backgroundColor = '#3b82f6'; // beautiful blue
        adviceText.innerHTML = '👍 <strong>สุขภาพการเงินดี!</strong> สัดส่วนการใช้จ่ายอยู่ในเกณฑ์มาตรฐานความปลอดภัย (ต่ำกว่า 70%) แนะนำให้คงความเสถียรนี้ไว้ และแบ่งออมก่อนเริ่มใช้จ่ายอย่างน้อย 15-20% ทุกเดือน';
    } else if (ratio <= 90) {
        ratioBar.style.backgroundColor = 'var(--color-warning)';
        adviceText.innerHTML = '⚠️ <strong>ควรระมัดระวัง!</strong> อัตราการใช้จ่ายเริ่มสูงเกิน 70% แล้ว แนะนำให้ลองคัดกรองรายจ่ายที่ไม่จำเป็นออกไป และพยายามตัดเงินเก็บทันทีเมื่อมีรายรับเพื่อบังคับตนเองให้ออม';
    } else {
        ratioBar.style.backgroundColor = 'var(--color-danger)';
        adviceText.innerHTML = '🚨 <strong>สภาวะตึงตัว!</strong> รายจ่ายของคุณเกือบเท่าหรือมากกว่ารายรับในเดือนนี้แล้ว แนะนำให้หยุดการใช้จ่ายฟุ่มเฟือยทันที ตรวจสอบภาระหนี้สิน และวางแผนลดรายจ่ายคงที่ที่ไม่จำเป็นโดยเร็วที่สุด';
    }
}

async function loadTransactions() {
    const month = document.getElementById('monthFilter')?.value || '';
    const type  = document.getElementById('typeFilter')?.value  || '';
    const params = new URLSearchParams();
    if (month) params.set('month', month);
    if (type)  params.set('type', type);

    try {
        const data = await apiFetch(BASE_URL + '/api/finance?' + params.toString());
        transactions = data.transactions || [];
        
        // Render view & summary
        renderTransactions();
        loadSummary();
        renderCategoryBreakdown();
    } catch {}
}

function renderTransactions(filteredList) {
    const tbody = document.getElementById('transactionList');
    const list = filteredList || transactions;

    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:2rem">ไม่มีรายการที่ตรงกับเงื่อนไข</td></tr>';
        return;
    }

    tbody.innerHTML = list.map(t => `
        <tr style="transition: background var(--transition)">
            <td class="text-sm">${escHtml(formatDate(t.txn_date))}</td>
            <td><span class="txn-${t.type}">${t.type === 'income' ? 'รายรับ' : 'รายจ่าย'}</span></td>
            <td>
                <span class="badge badge-gray text-xs" style="font-weight:600; font-size:0.75rem; padding: 2px 8px; border-radius: 4px;">
                    ${escHtml(t.category_name || 'ไม่ระบุ')}
                </span>
            </td>
            <td style="font-weight: 500">${escHtml(t.description || '—')}</td>
            <td style="text-align:right; font-weight:700; color:${t.type === 'income' ? 'var(--color-success)' : 'var(--color-text)'}">
                ${t.type === 'income' ? '+' : ''}${formatMoney(t.amount)}
            </td>
            <td>
                <div class="flex gap-2 justify-end">
                    <button class="btn-link" onclick="openEditTransaction(${t.id})" style="padding: 2px 6px;">แก้ไข</button>
                    <button class="btn-link" style="color:var(--color-danger); padding: 2px 6px;" onclick="deleteTxn(${t.id})">ลบ</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function filterTransactionsLocal() {
    const q = document.getElementById('searchFilter').value.trim().toLowerCase();
    const cat = document.getElementById('categoryFilter').value;
    
    const filtered = transactions.filter(t => {
        const matchesQuery = !q || 
            (t.description && t.description.toLowerCase().includes(q)) || 
            (t.category_name && t.category_name.toLowerCase().includes(q)) || 
            (t.amount && String(t.amount).includes(q));
            
        const matchesCategory = !cat || (t.category_name === cat);
        
        return matchesQuery && matchesCategory;
    });
    
    renderTransactions(filtered);
}

function renderCategoryBreakdown() {
    const container = document.getElementById('categoryBreakdown');
    if (!container) return;
    
    // Group only expenses
    const expenses = transactions.filter(t => t.type === 'expense');
    
    if (expenses.length === 0) {
        container.innerHTML = '<div class="text-center text-muted" style="padding: 2rem 0;">ไม่มีข้อมูลค่าใช้จ่ายในเดือนนี้</div>';
        return;
    }
    
    const totalsByCategory = {};
    let totalExpenseSum = 0;
    
    expenses.forEach(e => {
        const cat = e.category_name || 'ไม่ระบุ';
        const amt = parseFloat(e.amount || 0);
        totalsByCategory[cat] = (totalsByCategory[cat] || 0) + amt;
        totalExpenseSum += amt;
    });
    
    // Convert to sorted array
    const sortedCategories = Object.keys(totalsByCategory).map(name => ({
        name,
        amount: totalsByCategory[name],
        percentage: totalExpenseSum > 0 ? (totalsByCategory[name] / totalExpenseSum) * 100 : 0
    })).sort((a, b) => b.amount - a.amount);
    
    container.innerHTML = sortedCategories.map((c, index) => {
        const color = PASTEL_COLORS[index % PASTEL_COLORS.length];
        return `
            <div class="breakdown-row">
                <div class="breakdown-info">
                    <span class="breakdown-cat-name">${escHtml(c.name)}</span>
                    <div>
                        <span class="breakdown-cat-amount">${formatMoney(c.amount)} บ.</span>
                        <span class="breakdown-cat-pct">(${c.percentage.toFixed(0)}%)</span>
                    </div>
                </div>
                <div class="breakdown-track">
                    <div class="breakdown-fill" style="width: ${c.percentage}%; background-color: ${color}"></div>
                </div>
            </div>
        `;
    }).join('');
}

async function loadChart(year) {
    if (typeof Chart === 'undefined') return;

    try {
        const data = await apiFetch(BASE_URL + '/api/finance/chart?year=' + year);
        const chart = data.chart || [];

        const labels   = chart.map(c => THAI_MONTHS_SHORT[c.month - 1]);
        const incomes  = chart.map(c => c.income);
        const expenses = chart.map(c => c.expense);

        const ctx = document.getElementById('financeChart');
        if (!ctx) return;

        if (financeChart) financeChart.destroy();
        
        const style = getComputedStyle(document.documentElement);
        const textColor = style.getPropertyValue('--color-text').trim() || '#1d1d1f';
        const borderColor = style.getPropertyValue('--color-border').trim() || '#eaeaea';
        
        // Define clean custom canvas gradients for premium look
        const canvasCtx = ctx.getContext('2d');
        
        let incomeBg, expenseBg;
        
        if (currentChartType === 'line') {
            // Glowing Area fills
            incomeBg = canvasCtx.createLinearGradient(0, 0, 0, 240);
            incomeBg.addColorStop(0, 'rgba(34,197,94,0.3)');
            incomeBg.addColorStop(1, 'rgba(34,197,94,0.01)');
            
            expenseBg = canvasCtx.createLinearGradient(0, 0, 0, 240);
            expenseBg.addColorStop(0, 'rgba(239,68,68,0.25)');
            expenseBg.addColorStop(1, 'rgba(239,68,68,0.01)');
        } else {
            // Clean Bar colors
            incomeBg = 'rgba(34,197,94,0.85)';
            expenseBg = 'rgba(239,68,68,0.85)';
        }

        financeChart = new Chart(ctx, {
            type: currentChartType,
            data: {
                labels,
                datasets: [
                    {
                        label: 'รายรับ (Income)',
                        data: incomes,
                        backgroundColor: incomeBg,
                        borderColor: '#22c55e',
                        borderWidth: currentChartType === 'line' ? 2.5 : 0,
                        tension: 0.35,
                        fill: currentChartType === 'line',
                        borderRadius: currentChartType === 'bar' ? 4 : 0,
                    },
                    {
                        label: 'รายจ่าย (Expense)',
                        data: expenses,
                        backgroundColor: expenseBg,
                        borderColor: '#ef4444',
                        borderWidth: currentChartType === 'line' ? 2.5 : 0,
                        tension: 0.35,
                        fill: currentChartType === 'line',
                        borderRadius: currentChartType === 'bar' ? 4 : 0,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 20,
                            font: { family: 'inherit', size: 12, weight: '500' },
                            color: textColor
                        }
                    },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 8,
                        backgroundColor: 'rgba(15,23,42,0.95)',
                        titleFont: { family: 'inherit', size: 12, weight: '600' },
                        bodyFont: { family: 'inherit', size: 12 },
                        callbacks: {
                            label: function(ctx) {
                                return ' ' + ctx.dataset.label.split(' ')[0] + ': ' + formatMoney(ctx.parsed.y) + ' บาท';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { font: { family: 'inherit', size: 11 }, color: textColor },
                        grid: { display: false }
                    },
                    y: {
                        ticks: {
                            font: { family: 'inherit', size: 11 },
                            color: textColor,
                            callback: v => formatMoney(v)
                        },
                        grid: { color: borderColor }
                    }
                }
            }
        });
    } catch {}
}

function changeChartType(type) {
    if (currentChartType === type) return;
    currentChartType = type;
    
    // Toggle active classes on toggles
    const btns = document.querySelectorAll('#chartTypeToggle button');
    btns.forEach(btn => {
        const isActive = btn.dataset.chartType === type;
        btn.classList.toggle('active', isActive);
        btn.className = isActive ? 'segmented-btn active' : 'segmented-btn';
    });
    
    const year = document.getElementById('chartYear')?.value;
    loadChart(year);
}

/* --- Modals and Dropdowns --- */
function filterCategoryOptions() {
    const sel  = document.getElementById('txnCategory');
    const type = document.getElementById('txnType')?.value;
    if (!sel || !type) return;

    let matchedCurrent = false;
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        const match = opt.dataset.type === type;
        opt.hidden = !match;
        if (match && opt.value === sel.value) matchedCurrent = true;
    });
    if (!matchedCurrent) sel.value = '';
}

function filterQaCategoryOptions() {
    const sel = document.getElementById('qaCategory');
    const type = document.getElementById('qaType')?.value;
    if (!sel || !type) return;
    
    let matchedCurrent = false;
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        const match = opt.dataset.type === type;
        opt.hidden = !match;
        if (match && opt.value === sel.value) matchedCurrent = true;
    });
    if (!matchedCurrent) sel.value = '';
}

function openAddTransaction() {
    editingTxnId = null;
    document.getElementById('txnModalTitle').textContent = 'บันทึกรายการ';
    document.getElementById('editTxnId').value   = '';
    document.getElementById('txnType').value     = 'expense';
    document.getElementById('txnAmount').value   = '';
    document.getElementById('txnCategory').value = '';
    document.getElementById('txnDate').value     = todayISO();
    document.getElementById('txnDesc').value     = '';
    filterCategoryOptions();
    openModal('txnModal');
}

function openEditTransaction(id) {
    const t = transactions.find(x => x.id === id);
    if (!t) return;
    editingTxnId = id;
    document.getElementById('txnModalTitle').textContent = 'แก้ไขรายการ';
    document.getElementById('editTxnId').value   = id;
    document.getElementById('txnType').value     = t.type;
    document.getElementById('txnAmount').value   = t.amount;
    filterCategoryOptions();
    document.getElementById('txnCategory').value = t.category_id || '';
    document.getElementById('txnDate').value     = t.txn_date;
    document.getElementById('txnDesc').value     = t.description || '';
    openModal('txnModal');
}

async function saveTransaction() {
    const body = {
        type:        document.getElementById('txnType').value,
        amount:      document.getElementById('txnAmount').value,
        category_id: document.getElementById('txnCategory').value,
        txn_date:    document.getElementById('txnDate').value,
        description: document.getElementById('txnDesc').value,
    };

    if (!body.amount || parseFloat(body.amount) <= 0) {
        toast('กรุณากรอกจำนวนเงิน', 'danger'); return;
    }

    try {
        const url    = editingTxnId ? BASE_URL + '/api/finance/' + editingTxnId : BASE_URL + '/api/finance';
        const method = editingTxnId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('txnModal');
        await loadTransactions();
        const year = document.getElementById('chartYear')?.value;
        await loadChart(year);
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function saveQuickTransaction() {
    const body = {
        type:        document.getElementById('qaType').value,
        amount:      document.getElementById('qaAmount').value,
        category_id: document.getElementById('qaCategory').value,
        txn_date:    todayISO(), // quick add uses today
        description: document.getElementById('qaDesc').value.trim(),
    };

    if (!body.amount || parseFloat(body.amount) <= 0) {
        toast('กรุณากรอกจำนวนเงิน', 'danger'); return;
    }

    try {
        await apiFetch(BASE_URL + '/api/finance', { method: 'POST', body: JSON.stringify(body) });
        
        // Reset quick add form fields except type
        document.getElementById('qaAmount').value = '';
        document.getElementById('qaCategory').value = '';
        document.getElementById('qaDesc').value = '';
        filterQaCategoryOptions();
        
        // Reload dashboard
        await loadTransactions();
        const year = document.getElementById('chartYear')?.value;
        await loadChart(year);
        toast('บันทึกด่วนสำเร็จ');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteTxn(id) {
    if (!await confirmAction('ต้องการลบรายการนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/finance/' + id, { method: 'DELETE' });
    await loadTransactions();
    const year = document.getElementById('chartYear')?.value;
    await loadChart(year);
    toast('ลบแล้ว');
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* =====================================================
   PDF Export Feature
   ===================================================== */

let currentExportType = 'month';

function loadHtml2PdfLibrary() {
    return new Promise((resolve, reject) => {
        if (window.html2pdf) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('โหลดโมดูลสำหรับสร้าง PDF ล้มเหลว กรุณาตรวจสอบอินเทอร์เน็ต'));
        document.head.appendChild(script);
    });
}

function openExportModal() {
    currentExportType = 'month';
    const monthFilter = document.getElementById('monthFilter');
    if (monthFilter && monthFilter.value) {
        document.getElementById('exportMonthValue').value = monthFilter.value;
    }
    
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
    
    // Toggle active segment buttons
    const btns = document.querySelectorAll('#exportTypeToggle .segmented-btn');
    btns.forEach(btn => {
        const isActive = btn.dataset.type === type;
        btn.classList.toggle('active', isActive);
        btn.className = isActive ? 'segmented-btn active' : 'segmented-btn';
    });
    
    // Toggle input field displays
    document.getElementById('exportMonthGroup').style.display = type === 'month' ? 'block' : 'none';
    document.getElementById('exportRangeGroup').style.display = type === 'range' ? 'block' : 'none';
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
    let spendingRateText = 'N/A';
    let spendingRateColor = '#3b82f6';
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
                    <div style="font-size: 16px; font-weight: 700; color: #1e3a8a; letter-spacing: -0.01em;">MY MANAGER</div>
                    <div style="font-size: 9px; color: #6b7280; margin-top: 2px;">ออกเอกสาร: ${escHtml(today)} น.</div>
                </div>
            </div>

            <!-- Summary Cards Grid -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #166534; margin-bottom: 4px; letter-spacing: 0.05em;">รายรับรวม</div>
                    <div style="font-size: 14px; font-weight: 700; color: #166534;">+${formatMoney(totals.income)}</div>
                </div>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #991b1b; margin-bottom: 4px; letter-spacing: 0.05em;">รายจ่ายรวม</div>
                    <div style="font-size: 14px; font-weight: 700; color: #991b1b;">-${formatMoney(totals.expense)}</div>
                </div>
                <div style="background: ${totals.balance >= 0 ? '#eff6ff' : '#fff7ed'}; border: 1px solid ${totals.balance >= 0 ? '#bfdbfe' : '#fed7aa'}; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: ${totals.balance >= 0 ? '#1e40af' : '#854d0e'}; margin-bottom: 4px; letter-spacing: 0.05em;">คงเหลือสุทธิ</div>
                    <div style="font-size: 14px; font-weight: 700; color: ${totals.balance >= 0 ? '#1e40af' : '#b45309'};">${formatMoney(totals.balance)}</div>
                </div>
                <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 9px; text-transform: uppercase; font-weight: 700; color: #4b5563; margin-bottom: 4px; letter-spacing: 0.05em;">Spending Rate</div>
                    <div style="font-size: 14px; font-weight: 700; color: ${spendingRateColor};">${spendingRateText}</div>
                </div>
            </div>

            <!-- Two Columns Breakdown -->
            <div style="margin-bottom: 24px;">
                <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px;">
                    <h2 style="font-size: 12px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 1px dashed #e5e7eb; padding-bottom: 6px; color: #1e3a8a;">
                        สัดส่วนรายจ่ายแยกตามหมวดหมู่ (Expense Distribution Breakdown)
                    </h2>
                    ${categoryRows}
                </div>
            </div>

            <!-- Detailed Transactions Table -->
            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                <h2 style="font-size: 12px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 1px dashed #e5e7eb; padding-bottom: 6px; color: #1e3a8a;">
                    ประวัติธุรกรรมการทำรายการโดยละเอียด (Detailed Ledger)
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
                toast('กรุณาระบุเดือนที่ต้องการส่งออก', 'danger');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            url += '?month=' + encodeURIComponent(mVal);
            
            const [y, m] = mVal.split('-');
            const thaiMonthsFull = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                                    'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
            periodText = `ประจำเดือน ${thaiMonthsFull[parseInt(m) - 1]} ${parseInt(y) + 543}`;
        } else {
            const start = document.getElementById('exportStartDate').value;
            const end = document.getElementById('exportEndDate').value;
            if (!start || !end) {
                toast('กรุณาระบุช่วงวันที่ให้ครบถ้วน', 'danger');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            if (new Date(start) > new Date(end)) {
                toast('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด', 'danger');
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
            toast('ไม่พบข้อมูลการทำรายการในช่วงเวลาที่เลือก', 'danger');
            btn.disabled = false;
            btn.textContent = originalText;
            return;
        }

        // Load html2pdf library
        btn.textContent = 'กำลังโหลดโมดูล PDF...';
        await loadHtml2PdfLibrary();
        
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

        // Render template to printable element
        btn.textContent = 'กำลังสร้างไฟล์ PDF...';
        const printContainer = document.getElementById('printReportContainer');
        printContainer.innerHTML = buildPrintTemplate(txns, summaryInfo, periodText);
        printContainer.style.display = 'block'; // Temporarily make it block for rendering

        const opt = {
            margin:       [12, 12, 12, 12],
            filename:     `รายงานการเงิน_${periodText.replace(/ /g, '_')}.pdf`,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2.5, useCORS: true, letterRendering: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        await html2pdf().set(opt).from(printContainer).save();
        
        // Cleanup
        printContainer.style.display = 'none';
        printContainer.innerHTML = '';
        closeModal('exportPdfModal');
        toast('ดาวน์โหลดรายงาน PDF สำเร็จ');
    } catch (err) {
        console.error(err);
        toast(err.message || 'สร้างรายงานไม่สำเร็จ', 'danger');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
