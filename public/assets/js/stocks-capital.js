/* =====================================================
   stocks-capital.js — money moved into and out of the portfolio
   Loaded after stocks.js on the stocks page; shares its state.
===================================================== */

/* ============================================================
   CAPITAL FLOWS
   ============================================================ */

async function loadCapitalFlows() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/capital');
        stockCapitalFlows = data.flows || [];
        renderCapitalList();
    } catch (err) {
        toast(err.message || 'โหลดข้อมูลเงินลงทุนไม่สำเร็จ', 'danger');
    }
}

function renderCapitalList() {
    const tbody = document.getElementById('stkCapitalList');
    if (!tbody) return;

    if (!stockCapitalFlows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:2rem">ไม่มีรายการเงินลงทุน</td></tr>';
        return;
    }

    tbody.innerHTML = stockCapitalFlows.map(f => {
        const typeBadge = f.flow_type === 'deposit'
            ? '<span class="stk-side-buy">เติมเงิน</span>'
            : '<span class="stk-side-sell">ถอนเงิน</span>';
        
        return `
            <tr>
                <td class="text-sm">${escHtml(formatDate(f.flow_date))}</td>
                <td>${typeBadge}</td>
                <td class="stk-num" style="font-weight:700">${formatMoney(f.amount)}</td>
                <td>${escHtml(f.currency)}</td>
                <td class="text-sm">${escHtml(f.notes || '—')}</td>
                <td class="mode-readonly-hide">
                    <button class="btn-link" data-act="openEditCapital" data-args="[${f.id}]">แก้ไข</button>
                </td>
            </tr>`;
    }).join('');
}

function openAddCapital() {
    editingCapitalId = null;
    document.getElementById('capitalModalTitle').textContent = 'บันทึกรายการเงินลงทุน';
    document.getElementById('editCapitalId').value = '';
    document.getElementById('capType').value = 'deposit';
    document.getElementById('capCurrency').value = 'THB';
    document.getElementById('capAmount').value = '';
    document.getElementById('capDate').value = todayISO();
    document.getElementById('capNotes').value = '';
    document.getElementById('deleteCapitalBtn').style.display = 'none';
    openModal('capitalModal');
}

function openEditCapital(id) {
    const f = stockCapitalFlows.find(x => x.id === id);
    if (!f) return;
    editingCapitalId = id;
    document.getElementById('capitalModalTitle').textContent = 'แก้ไขรายการเงินลงทุน';
    document.getElementById('editCapitalId').value = id;
    document.getElementById('capType').value = f.flow_type;
    document.getElementById('capCurrency').value = f.currency;
    document.getElementById('capAmount').value = f.amount;
    document.getElementById('capDate').value = f.flow_date;
    document.getElementById('capNotes').value = f.notes || '';
    document.getElementById('deleteCapitalBtn').style.display = '';
    openModal('capitalModal');
}

async function saveCapital() {
    const body = {
        flow_type: document.getElementById('capType').value,
        currency:  document.getElementById('capCurrency').value,
        amount:    document.getElementById('capAmount').value,
        flow_date: document.getElementById('capDate').value,
        notes:     document.getElementById('capNotes').value,
    };

    if (!body.amount || parseFloat(body.amount) <= 0) {
        toast('กรุณากรอกจำนวนเงินที่ถูกต้อง', 'danger'); return;
    }
    if (!body.flow_date) {
        toast('กรุณาเลือกวันที่', 'danger'); return;
    }

    try {
        const url = editingCapitalId
            ? BASE_URL + '/api/stocks/capital/' + editingCapitalId
            : BASE_URL + '/api/stocks/capital';
        const method = editingCapitalId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('capitalModal');
        await Promise.all([
            loadStockPortfolio(),
            loadCapitalFlows()
        ]);
        toast('บันทึกเรียบร้อยแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteCapital() {
    if (!editingCapitalId) return;
    if (!await confirmAction('ต้องการลบรายการเงินลงทุนนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/capital/' + editingCapitalId, { method: 'DELETE' });
        closeModal('capitalModal');
        await Promise.all([
            loadStockPortfolio(),
            loadCapitalFlows()
        ]);
        toast('ลบเรียบร้อยแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

