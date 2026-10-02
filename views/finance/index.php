<?php
// =====================================================
// views/finance/index.php — the month's accounts
//
// One month at a time: what came in, what went out, what is left; a line to
// add an entry; the entries by day; where the expenses went; and the year as a
// chart. $cats comes from FinanceController. assets/js/finance.js draws it all.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>รายรับรายจ่าย</h1>
        <p class="sub" id="financeTally" aria-live="polite">กำลังโหลดบัญชี…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-ghost" type="button" data-act="openExportModal">ส่งออก PDF</button>
        <button class="btn btn-primary" type="button" data-act="openAddTransaction"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>บันทึกรายการ</button>
    </div>
</div>

<div class="month-bar">
    <button class="icon-btn" type="button" id="monthPrev" aria-label="เดือนก่อน">&lsaquo;</button>
    <h2 class="month-bar-label" id="monthLabel"></h2>
    <button class="icon-btn" type="button" id="monthNext" aria-label="เดือนถัดไป">&rsaquo;</button>
    <button class="btn btn-sm" type="button" id="monthNow" hidden>กลับมาเดือนนี้</button>
</div>

<div id="financeError" role="alert" hidden></div>

<div class="fin-summary">
    <table class="ledger ledger-lg" aria-label="สรุปเดือนนี้">
        <tr><td>รายรับ</td><td class="num in" id="sumIncome">—</td></tr>
        <tr><td>รายจ่าย</td><td class="num out" id="sumExpense">—</td></tr>
        <tr class="total"><td>คงเหลือ</td><td class="num" id="sumBalance">—</td></tr>
    </table>
    <div class="fin-ratio" id="financeRatio" hidden>
        <div class="progress" role="img" id="spendingRatioTrack" aria-label="สัดส่วนรายจ่ายต่อรายรับ"><div class="progress-bar" id="spendingRatioBar"></div></div>
        <p class="fin-ratio-text" id="spendingRatioText"></p>
    </div>
</div>

<!-- Add an entry in one line -->
<form id="quickAddForm" class="entry-form" data-act="saveQuickTransaction" novalidate>
    <div class="seg-pair" role="radiogroup" aria-label="ประเภทรายการ">
        <label><input type="radio" name="qaType" value="expense" checked><span>รายจ่าย</span></label>
        <label><input type="radio" name="qaType" value="income"><span>รายรับ</span></label>
    </div>
    <label class="sr-only" for="qaAmount">จำนวนเงิน (บาท)</label>
    <input class="form-control entry-amount" id="qaAmount" inputmode="decimal" autocomplete="off" placeholder="จำนวนเงิน">
    <label class="sr-only" for="qaCategory">หมวดหมู่</label>
    <select class="form-control entry-category" id="qaCategory"><option value="">ไม่ระบุหมวด</option></select>
    <label class="sr-only" for="qaDesc">รายการ</label>
    <input class="form-control entry-desc" id="qaDesc" maxlength="255" autocomplete="off" placeholder="รายการ เช่น ข้าวมันไก่ + ชาเย็น">
    <button class="btn btn-primary" type="submit" id="qaSubmit">บันทึกรายจ่าย</button>
</form>

<div class="cols fin-cols">
    <div class="col-main">
        <section class="sec" aria-labelledby="txnTitle">
            <div class="sec-head">
                <h2 id="txnTitle">รายการของเดือน<span class="count" id="txnCount"></span></h2>
            </div>
            <div class="txn-filters">
                <label class="sr-only" for="searchFilter">ค้นหารายการ</label>
                <input type="search" class="form-control" id="searchFilter" placeholder="ค้นหารายการ" autocomplete="off">
                <div class="seg-pair" role="group" aria-label="แสดง" id="typeFilter">
                    <button type="button" data-type="" aria-pressed="true">ทั้งหมด</button>
                    <button type="button" data-type="income" aria-pressed="false">รายรับ</button>
                    <button type="button" data-type="expense" aria-pressed="false">รายจ่าย</button>
                </div>
                <label class="sr-only" for="categoryFilter">หมวดหมู่</label>
                <select class="form-control" id="categoryFilter"><option value="">ทุกหมวดหมู่</option></select>
            </div>
            <div id="transactionList" aria-busy="true">
                <div class="skel-row"><span class="skel skel-w-60"></span></div>
                <div class="skel-row"><span class="skel skel-w-45"></span></div>
                <div class="skel-row"><span class="skel skel-w-52"></span></div>
            </div>
            <div id="txnLoadMoreWrap" class="txn-more" hidden>
                <button class="btn btn-sm" type="button" id="txnLoadMore">โหลดเพิ่ม</button>
            </div>
        </section>
    </div>

    <aside class="col-side">
        <section class="sec" aria-labelledby="catTitle">
            <div class="sec-head"><h2 id="catTitle">รายจ่ายแยกตามหมวด</h2></div>
            <div id="categoryBreakdown"></div>
        </section>
    </aside>
</div>

<section class="sec" aria-labelledby="chartTitle">
    <div class="sec-head">
        <h2 id="chartTitle">รายรับกับรายจ่ายตลอดปี</h2>
        <div class="chart-controls">
            <div class="seg-pair" role="group" aria-label="แบบกราฟ" id="chartTypeToggle">
                <button type="button" data-chart-type="bar" aria-pressed="true" data-act="changeChartType" data-args="[&quot;bar&quot;]">แท่ง</button>
                <button type="button" data-chart-type="line" aria-pressed="false" data-act="changeChartType" data-args="[&quot;line&quot;]">เส้น</button>
            </div>
            <label class="sr-only" for="chartYear">ปี</label>
            <select class="form-control chart-year" id="chartYear" data-act="loadChart" data-args="[&quot;$value&quot;]" data-on="change">
                <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>"<?= $y === (int)date('Y') ? ' selected' : '' ?>><?= $y + 543 ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
    <div class="chart-wrap">
        <canvas id="financeChart" role="img" aria-label="กราฟรายรับและรายจ่ายรายเดือนตลอดปี"></canvas>
    </div>
</section>

<script nonce="<?= h(Security::nonce()) ?>">
// The categories the entry forms offer, so they can be filtered by income or expense.
window.financeCategories = <?= jsonForScript($cats) ?>;
</script>

<!-- Add/Edit Transaction Modal -->
<div class="modal-backdrop" id="txnModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="txnModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="txnModalTitle">บันทึกรายการ</h2>
            <button class="modal-close" type="button" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editTxnId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="txnType">ประเภท</label>
                    <select class="form-control" id="txnType">
                        <option value="expense">รายจ่าย</option>
                        <option value="income">รายรับ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="txnAmount">จำนวนเงิน (บาท)</label>
                    <input type="text" inputmode="decimal" class="form-control" id="txnAmount" autocomplete="off">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="txnCategory">หมวดหมู่</label>
                    <select class="form-control" id="txnCategory"><option value="">ไม่ระบุหมวด</option></select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="txnDate">วันที่</label>
                    <input type="date" class="form-control" id="txnDate">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="txnDesc">รายการ</label>
                <input type="text" class="form-control" id="txnDesc" placeholder="เช่น ค่าไฟเดือนตุลาคม" maxlength="255">
            </div>
            <p class="form-error" id="txnError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveTransaction">บันทึกรายการ</button>
        </div>
    </div>
</div>

<!-- Export PDF Modal -->
<div class="modal-backdrop" id="exportPdfModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="exportTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="exportTitle">ส่งออกรายงานเป็น PDF</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-act="closeModal" data-args="[&quot;exportPdfModal&quot;]">&times;</button>
        </div>
        <div class="modal-body">
            <div class="seg-pair export-toggle" role="group" aria-label="ช่วงเวลา" id="exportTypeToggle">
                <button type="button" data-type="month" aria-pressed="true" data-act="setExportType" data-args="[&quot;month&quot;]">รายเดือน</button>
                <button type="button" data-type="range" aria-pressed="false" data-act="setExportType" data-args="[&quot;range&quot;]">เลือกช่วงเอง</button>
            </div>

            <div id="exportMonthGroup" class="form-group">
                <label class="form-label" for="exportMonthValue">เดือน</label>
                <input type="month" class="form-control" id="exportMonthValue" value="<?= date('Y-m') ?>">
            </div>

            <div id="exportRangeGroup" class="form-row" hidden>
                <div class="form-group">
                    <label class="form-label" for="exportStartDate">ตั้งแต่วันที่</label>
                    <input type="date" class="form-control" id="exportStartDate" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="exportEndDate">ถึงวันที่</label>
                    <input type="date" class="form-control" id="exportEndDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-act="closeModal" data-args="[&quot;exportPdfModal&quot;]">ยกเลิก</button>
            <button class="btn btn-primary" type="button" id="btnExportSubmit" data-act="generatePdfReport">ดาวน์โหลด PDF</button>
        </div>
    </div>
</div>
