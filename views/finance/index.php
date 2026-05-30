<div class="page-header flex items-center justify-between">
    <h1 class="page-title">การเงิน</h1>
    <div class="flex gap-2">
        <button class="btn btn-ghost" onclick="openExportModal()" style="border-color: var(--color-border-2); display: flex; align-items: center; gap: 6px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>ส่งออก PDF</span>
        </button>
        <button class="btn btn-primary" onclick="openAddTransaction()">+ บันทึกรายการ</button>
    </div>
</div>

<!-- Summary bar -->
<div class="finance-summary-bar">
    <div class="finance-stat-card">
        <div class="finance-stat-label">รายรับเดือนนี้</div>
        <div class="finance-stat-amount income" id="sumIncome">—</div>
    </div>
    <div class="finance-stat-card">
        <div class="finance-stat-label">รายจ่ายเดือนนี้</div>
        <div class="finance-stat-amount expense" id="sumExpense">—</div>
    </div>
    <div class="finance-stat-card">
        <div class="finance-stat-label">คงเหลือ</div>
        <div class="finance-stat-amount balance" id="sumBalance">—</div>
    </div>
</div>

<!-- Spending ratio / Financial Health indicator -->
<div class="finance-ratio-card" id="financeRatioCard" style="display:none; margin-bottom: var(--space-8);">
    <div class="card" style="padding: var(--space-5);">
        <div class="flex justify-between items-center mb-2">
            <span class="text-sm font-semibold" style="letter-spacing: -0.01em;">สัดส่วนการใช้จ่าย (Spending Rate)</span>
            <span class="text-xs text-muted font-medium" id="spendingRatioText">—</span>
        </div>
        <div class="progress" style="height: 10px; background: var(--color-surface-2); border-radius: 99px; overflow: hidden; position: relative;">
            <div id="spendingRatioBar" class="progress-bar" style="width: 0%; background: var(--color-success); height: 100%; transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1), background-color var(--transition);"></div>
        </div>
    </div>
</div>

<!-- Quick Add Bar -->
<div class="card mb-8" style="padding: 1.25rem; border-left: 3px solid var(--color-text);">
    <form id="quickAddForm" class="flex gap-4 items-center justify-between" onsubmit="event.preventDefault(); saveQuickTransaction();" style="flex-wrap: wrap;">
        <span style="font-weight: 600; font-size: 0.9rem; white-space: nowrap; display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 1.2rem;"></span> บันทึกด่วน:
        </span>
        <div style="display: flex; gap: 10px; flex: 1; min-width: 280px; flex-wrap: wrap;">
            <select class="form-control" id="qaType" style="width: auto; cursor: pointer;" onchange="filterQaCategoryOptions()">
                <option value="expense">รายจ่าย</option>
                <option value="income">รายรับ</option>
            </select>
            <input type="number" class="form-control" id="qaAmount" placeholder="จำนวนเงิน (บาท)..." step="0.01" min="0.01" required style="width: 140px; flex: 1;">
            <select class="form-control" id="qaCategory" style="min-width: 150px; flex: 1; cursor: pointer;">
                <option value="">— หมวดหมู่ —</option>
                <?php foreach ($cats as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" data-type="<?= h($cat['type']) ?>">
                    <?= h($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="text" class="form-control" id="qaDesc" placeholder="รายละเอียดรายการ / หมายเหตุ..." maxlength="255" style="flex: 2; min-width: 180px;">
        </div>
        <button class="btn btn-ghost" type="submit" style="padding: var(--space-2) var(--space-5); font-weight: 600;">+ บันทึก</button>
    </form>
</div>

<!-- Chart -->
<div class="card mb-8">
    <div class="card-header">
        <span class="card-title">เปรียบเทียบ รายรับ vs รายจ่าย รายปี</span>
        <div class="flex items-center gap-3">
            <!-- Chart type segmented buttons -->
            <div class="segmented-control" id="chartTypeToggle">
                <button class="segmented-btn active" data-chart-type="bar" onclick="changeChartType('bar')">แท่ง</button>
                <button class="segmented-btn" data-chart-type="line" onclick="changeChartType('line')">พื้นที่</button>
            </div>
            <select class="form-control" id="chartYear" style="width:auto; padding-top: 4px; padding-bottom: 4px; font-size: 0.85rem;" onchange="loadChart(this.value)">
                <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>" <?= $y == (int)date('Y') ? 'selected' : '' ?>><?= $y + 543 ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-wrap">
            <canvas id="financeChart"></canvas>
        </div>
    </div>
</div>

<!-- Two Column Dashboard Layout (Table list + Categories distribution) -->
<div class="grid-2-custom mb-8">
    <!-- Left Column: Transaction list -->
    <div class="card" style="display: flex; flex-direction: column; overflow: hidden; padding-bottom: var(--space-4);">
        <div class="card-header flex items-center justify-between" style="flex-wrap: wrap; gap: var(--space-3); margin-bottom: var(--space-4);">
            <span class="card-title">ประวัติการทำรายการ</span>
            <div class="flex gap-2" style="flex-wrap: wrap; width: auto;">
                <input type="text" class="form-control filter-input" id="searchFilter" placeholder="ค้นหารายการ..." style="width: 150px; font-size: 0.85rem;" oninput="filterTransactionsLocal()">
                <input type="month" class="form-control" id="monthFilter" value="<?= date('Y-m') ?>" style="width: auto; font-size: 0.85rem;" onchange="loadTransactions()">
                <select class="form-control" id="typeFilter" style="width: auto; font-size: 0.85rem;" onchange="loadTransactions()">
                    <option value="">ทั้งหมด</option>
                    <option value="income">รายรับ</option>
                    <option value="expense">รายจ่าย</option>
                </select>
                <select class="form-control" id="categoryFilter" style="width: auto; font-size: 0.85rem;" onchange="filterTransactionsLocal()">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php foreach ($cats as $cat): ?>
                    <option value="<?= h($cat['name']) ?>"><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-wrap" style="flex: 1; overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>ประเภท</th>
                        <th>หมวดหมู่</th>
                        <th>รายการ</th>
                        <th style="text-align:right">จำนวน (บาท)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="transactionList">
                    <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">กำลังโหลด...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column: Visual category analytics + Financial Insight advices -->
    <div class="flex-col gap-6" style="display: flex; flex-direction: column; gap: var(--space-6);">
        <!-- Breakdown progress bars -->
        <div class="card" style="height: 100%;">
            <div class="card-header mb-4" style="margin-bottom: var(--space-4);">
                <span class="card-title">สัดส่วนการใช้จ่ายรายจ่าย</span>
            </div>
            <div id="categoryBreakdown" class="card-body flex-col" style="display: flex; flex-direction: column; gap: 12px;">
                <div class="text-center text-muted" style="padding: 2rem 0;">ไม่มีข้อมูลการใช้จ่ายในเดือนนี้</div>
            </div>
        </div>

        <!-- Financial advise insights box -->
        <div class="card" style="border-left: 4px solid #3b82f6; background: var(--color-surface);">
            <div class="card-header mb-2" style="margin-bottom: var(--space-2);">
                <span class="card-title" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #3b82f6; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                     การประเมินผลสุขภาพการเงิน
                </span>
            </div>
            <div class="card-body" id="financialAdviceText" style="font-size: 0.875rem; color: var(--color-muted); line-height: 1.65;">
                กำลังประเมินข้อมูล...
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Transaction Modal -->
<div class="modal-backdrop" id="txnModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="txnModalTitle">บันทึกรายการ</span>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editTxnId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ประเภท</label>
                    <select class="form-control" id="txnType">
                        <option value="expense">รายจ่าย</option>
                        <option value="income">รายรับ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">จำนวนเงิน (บาท)</label>
                    <input type="number" class="form-control" id="txnAmount" step="0.01" min="0.01">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">หมวดหมู่</label>
                    <select class="form-control" id="txnCategory">
                        <option value="">— เลือกหมวดหมู่ —</option>
                        <?php foreach ($cats as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" data-type="<?= h($cat['type']) ?>">
                            <?= h($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="txnDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">รายการ / หมายเหตุ</label>
                <input type="text" class="form-control" id="txnDesc" placeholder="รายละเอียดการเงิน..." maxlength="255">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="saveTransaction()">บันทึก</button>
        </div>
    </div>
</div>

<!-- Export PDF Modal -->
<div class="modal-backdrop" id="exportPdfModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title">ส่งออกรายงานการเงิน (PDF)</span>
            <button class="modal-close" onclick="closeModal('exportPdfModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="segmented-control" id="exportTypeToggle" style="display: flex; width: 100%; margin-bottom: var(--space-4);">
                <button class="segmented-btn active" data-type="month" onclick="setExportType('month')" style="flex: 1; text-align: center;">รายเดือน</button>
                <button class="segmented-btn" data-type="range" onclick="setExportType('range')" style="flex: 1; text-align: center;">เลือกช่วงเวลาเอง</button>
            </div>

            <!-- Month Selection -->
            <div id="exportMonthGroup" class="form-group">
                <label class="form-label">เลือกเดือน</label>
                <input type="month" class="form-control" id="exportMonthValue" value="<?= date('Y-m') ?>">
            </div>

            <!-- Range Selection -->
            <div id="exportRangeGroup" class="form-group" style="display: none;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">วันที่เริ่มต้น</label>
                        <input type="date" class="form-control" id="exportStartDate" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">วันที่สิ้นสุด</label>
                        <input type="date" class="form-control" id="exportEndDate" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('exportPdfModal')">ยกเลิก</button>
            <button class="btn btn-primary" id="btnExportSubmit" onclick="generatePdfReport()">ดาวน์โหลด PDF</button>
        </div>
    </div>
</div>

<!-- Print template container (hidden) -->
<div id="printReportContainer" style="display: none;"></div>

