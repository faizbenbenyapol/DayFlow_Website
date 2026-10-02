/* =====================================================
   stocks-capital.js — money moved into and out of the portfolio
   Loaded after stocks.js on the stocks page; shares its state.
===================================================== */

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
        tbody.innerHTML = '<tr><td colspan="6"><div class="stk-empty"><strong>ยังไม่มีรายการเงินลงทุน</strong>'
            + 'บันทึกเงินที่เติมเข้าพอร์ต เพื่อดูเงินสดคงเหลือ</div></td></tr>';
        return;
    }

    tbody.innerHTML = stockCapitalFlows.map(f => {
        const type = f.flow_type === 'deposit'
            ? '<span class="badge badge-success">เติมเงิน</span>'
            : '<span class="badge badge-gray">ถอนเงิน</span>';
        return '<tr>'
            + '<td>' + escHtml(formatDate(f.flow_date)) + '</td>'
            + '<td>' + type + '</td>'
            + '<td class="num">' + formatMoney(f.amount) + '</td>'
            + '<td>' + escHtml(f.currency) + '</td>'
            + '<td>' + (f.notes ? escHtml(f.notes) : STK_DASH) + '</td>'
            + '<td class="stk-act mode-readonly-hide"><button type="button" class="icon-btn sm" data-act="openEditCapital" data-args="[' + f.id + ']" aria-label="แก้ไขรายการเงินลงทุน"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button></td>'
            + '</tr>';
    }).join('');
}

function capError(message) {
    const line = document.getElementById('capError');
    line.textContent = message;
    line.hidden = message === '';
}

function capitalType() {
    return document.querySelector('input[name="capType"]:checked')?.value || 'deposit';
}

function setCapitalType(type) {
    const radio = document.querySelector('input[name="capType"][value="' + type + '"]');
    if (radio) radio.checked = true;
}

function openAddCapital() {
    editingCapitalId = null;
    document.getElementById('capitalModalTitle').textContent = 'บันทึกเงินลงทุน';
    document.getElementById('editCapitalId').value = '';
    setCapitalType('deposit');
    document.getElementById('capCurrency').value = 'THB';
    document.getElementById('capAmount').value = '';
    document.getElementById('capDate').value = todayISO();
    document.getElementById('capNotes').value = '';
    document.getElementById('deleteCapitalBtn').hidden = true;
    capError('');
    openModal('capitalModal');
}

function openEditCapital(id) {
    const f = stockCapitalFlows.find(x => x.id === id);
    if (!f) return;
    editingCapitalId = id;
    document.getElementById('capitalModalTitle').textContent = 'แก้ไขเงินลงทุน';
    document.getElementById('editCapitalId').value = id;
    setCapitalType(f.flow_type);
    document.getElementById('capCurrency').value = f.currency;
    document.getElementById('capAmount').value = f.amount;
    document.getElementById('capDate').value = f.flow_date;
    document.getElementById('capNotes').value = f.notes || '';
    document.getElementById('deleteCapitalBtn').hidden = false;
    capError('');
    openModal('capitalModal');
}

async function saveCapital() {
    const body = {
        flow_type: capitalType(),
        currency:  document.getElementById('capCurrency').value,
        amount:    document.getElementById('capAmount').value,
        flow_date: document.getElementById('capDate').value,
        notes:     document.getElementById('capNotes').value,
    };

    if (!body.amount || parseFloat(body.amount) <= 0) { capError('ใส่จำนวนเงินที่มากกว่า 0'); return; }
    if (!body.flow_date) { capError('เลือกวันที่ก่อน'); return; }

    try {
        const url = editingCapitalId ? BASE_URL + '/api/stocks/capital/' + editingCapitalId : BASE_URL + '/api/stocks/capital';
        await apiFetch(url, { method: editingCapitalId ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('capitalModal');
        await Promise.all([loadStockPortfolio(), loadCapitalFlows()]);
        toast('บันทึกรายการแล้ว');
    } catch (err) {
        capError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteCapital() {
    if (!editingCapitalId || !await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้ และเงินสดคงเหลือจะคำนวณใหม่', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/capital/' + editingCapitalId, { method: 'DELETE' });
        closeModal('capitalModal');
        await Promise.all([loadStockPortfolio(), loadCapitalFlows()]);
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
