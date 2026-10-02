<?php
// =====================================================
// views/stocks/index.php — the portfolio
//
// What the holdings are worth and what they have earned, then the working
// parts: the holdings table, the trades, the money put in and taken out, the
// screenshots of the broker app, the charts and the AI read of one ticker.
// A shared (read-only) link gets a short page: the totals, the cash and the
// latest screenshot. assets/js/stocks*.js draw it all from /api/stocks/*.
// =====================================================
$readOnly = Auth::isReadOnly();
?>
<script nonce="<?= h(Security::nonce()) ?>">
    const IS_READ_ONLY = <?= $readOnly ? 'true' : 'false' ?>;
</script>

<?php if ($readOnly): ?>
<div class="page-head">
    <div>
        <h1>สรุปพอร์ตหุ้น</h1>
        <p class="sub" id="stkRefreshedAt" aria-live="polite"></p>
    </div>
</div>

<div class="facts stk-facts stk-facts-3">
    <div class="fact"><div class="k">มูลค่าตลาด</div><div class="v" id="stkMarketValue">—</div></div>
    <div class="fact"><div class="k">ต้นทุน</div><div class="v" id="stkCostBasis">—</div></div>
    <div class="fact"><div class="k">กำไร/ขาดทุนที่ยังไม่ปิด</div><div class="v" id="stkUnrealized">—</div><div class="note" id="stkUnrealizedPct"></div></div>
</div>

<div class="cols">
    <div class="col-main">
        <section class="sec" aria-labelledby="shotTitle">
            <div class="sec-head"><h2 id="shotTitle">รูปภาพพอร์ตล่าสุด</h2></div>
            <div id="sideScreenshotContainer" class="stk-latest-shot">
                <p class="stk-empty">ยังไม่มีรูปภาพพอร์ต</p>
            </div>
        </section>
    </div>
    <aside class="col-side">
        <section class="sec" aria-labelledby="capTitle">
            <div class="sec-head"><h2 id="capTitle">เงินลงทุน (บาท)</h2></div>
            <table class="ledger" aria-labelledby="capTitle">
                <tr><td>เงินต้นสะสม</td><td class="num" id="sideNetTHB">—</td></tr>
                <tr><td>เงินสดคงเหลือ</td><td class="num" id="sideCashTHB">—</td></tr>
            </table>
        </section>
    </aside>
</div>
<?php else: ?>
<div class="page-head">
    <div>
        <h1>หุ้น</h1>
        <p class="sub" id="stkRefreshedAt" aria-live="polite"></p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-ghost" type="button" id="stkRefreshBtn" data-act="refreshPrices" title="ดึงราคาล่าสุดจากผู้ให้บริการ"><svg class="icon" aria-hidden="true"><use href="#i-refresh"/></svg><span>รีเฟรชราคา</span></button>
        <button class="btn btn-primary" type="button" data-act="openAddStock"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>บันทึกรายการ</button>
    </div>
</div>

<div class="facts stk-facts">
    <div class="fact"><div class="k">มูลค่าตลาด</div><div class="v" id="stkMarketValue">—</div></div>
    <div class="fact"><div class="k">ต้นทุน</div><div class="v" id="stkCostBasis">—</div></div>
    <div class="fact"><div class="k">กำไร/ขาดทุนที่ยังไม่ปิด</div><div class="v" id="stkUnrealized">—</div><div class="note" id="stkUnrealizedPct"></div></div>
    <div class="fact"><div class="k">กำไร/ขาดทุนที่ปิดแล้ว</div><div class="v" id="stkRealized">—</div></div>
</div>

<!-- The holdings, and the tickers being watched -->
<section class="sec" aria-labelledby="holdTitle">
    <div class="sec-head"><h2 id="holdTitle">ราคาและตัวชี้วัด</h2></div>
    <div class="tabs" role="tablist" aria-label="กลุ่มหุ้น" id="stkMainTabs">
        <button class="tab" type="button" role="tab" aria-selected="false" data-main-tab="watchlists">ที่ติดตาม</button>
        <button class="tab" type="button" role="tab" aria-selected="true" data-main-tab="portfolio">พอร์ตปัจจุบัน</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-main-tab="all">ทั้งหมด</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-main-tab="US">US</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-main-tab="SET">SET</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-main-tab="OTHER">อื่นๆ</button>
    </div>
    <div class="table-wrap">
        <table class="table stk-table" id="stkTable" data-view="portfolio" aria-label="ตารางหุ้น">
            <thead>
                <tr>
                    <th scope="col" class="stk-sticky">หุ้น</th>
                    <th scope="col" class="num">ราคาล่าสุด</th>
                    <th scope="col" class="num">เปลี่ยนวันนี้</th>
                    <th scope="col" class="num"><button type="button" class="metric-help" data-act="showMetricExplain" data-args='["pe"]' aria-label="อธิบาย P/E">P/E</button></th>
                    <th scope="col" class="num"><button type="button" class="metric-help" data-act="showMetricExplain" data-args='["forward_pe"]' aria-label="อธิบาย Forward P/E">Forward P/E</button></th>
                    <th scope="col" class="num"><button type="button" class="metric-help" data-act="showMetricExplain" data-args='["peg"]' aria-label="อธิบาย PEG">PEG</button></th>
                    <th scope="col" class="num"><button type="button" class="metric-help" data-act="showMetricExplain" data-args='["p_fcf"]' aria-label="อธิบาย P/FCF">P/FCF</button></th>
                    <th scope="col" class="num"><button type="button" class="metric-help" data-act="showMetricExplain" data-args='["eps"]' aria-label="อธิบาย EPS">EPS</button></th>
                    <th scope="col" class="num stk-col-portfolio">จำนวน</th>
                    <th scope="col" class="num stk-col-portfolio">ต้นทุนเฉลี่ย</th>
                    <th scope="col" class="num stk-col-portfolio">มูลค่าตลาด</th>
                    <th scope="col" class="num stk-col-portfolio">กำไร/ขาดทุน</th>
                    <th scope="col"><span class="sr-only">วิเคราะห์</span></th>
                </tr>
            </thead>
            <tbody id="stkUnifiedList">
                <tr><td colspan="13"><span class="skel skel-w-60"></span></td></tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Trades, money in and out, screenshots, charts, analysis -->
<section class="sec" aria-labelledby="workTitle">
    <div class="sec-head"><h2 id="workTitle">บันทึกและวิเคราะห์</h2></div>
    <div class="tabs" role="tablist" aria-label="หมวดบันทึก" id="stkSubTabs">
        <button class="tab" type="button" role="tab" aria-selected="true" data-stk-tab="transactions">ซื้อขาย</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-stk-tab="capital">เงินลงทุน</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-stk-tab="screenshots">รูปภาพพอร์ต</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-stk-tab="chart">กราฟ</button>
        <button class="tab" type="button" role="tab" aria-selected="false" data-stk-tab="analysis">วิเคราะห์ด้วย AI</button>
    </div>

    <!-- Trades -->
    <div class="stk-panel" data-stk-panel="transactions">
        <div class="stk-filters">
            <label class="sr-only" for="stkTickerFilter">กรองตามชื่อหุ้น</label>
            <input type="search" class="form-control stk-filter-ticker" id="stkTickerFilter" placeholder="กรองตามชื่อหุ้น" autocomplete="off" data-act="loadStockTransactions" data-on="input">
            <label class="sr-only" for="stkMarketFilter">ตลาด</label>
            <select class="form-control" id="stkMarketFilter" data-act="loadStockTransactions" data-on="change">
                <option value="">ทุกตลาด</option>
                <option value="US">US</option>
                <option value="SET">SET</option>
                <option value="OTHER">อื่นๆ</option>
            </select>
        </div>
        <div class="table-wrap">
            <table class="table" aria-label="รายการซื้อขาย">
                <thead>
                    <tr>
                        <th scope="col">วันที่</th>
                        <th scope="col">หุ้น</th>
                        <th scope="col">ฝั่ง</th>
                        <th scope="col" class="num">จำนวน</th>
                        <th scope="col" class="num">ราคา</th>
                        <th scope="col" class="num">ค่าธรรมเนียม</th>
                        <th scope="col" class="num">มูลค่า</th>
                        <th scope="col"><span class="sr-only">แก้ไข</span></th>
                    </tr>
                </thead>
                <tbody id="stkTxnList">
                    <tr><td colspan="8"><span class="skel skel-w-52"></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Money in and out -->
    <div class="stk-panel" data-stk-panel="capital" hidden>
        <div class="stk-panel-head">
            <p class="stk-note">เงินที่เติมเข้าพอร์ตและถอนออกจากพอร์ต แยกตามสกุลเงิน</p>
            <button class="btn btn-sm" type="button" data-act="openAddCapital"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>บันทึกเงินลงทุน</button>
        </div>
        <div class="stk-cap">
            <div>
                <h3 class="subhead">พอร์ตเงินบาท (THB)</h3>
                <table class="ledger" aria-label="เงินลงทุนสกุลบาท">
                    <tr><td>เงินต้นสะสม</td><td class="num" id="capNetTHB">—</td></tr>
                    <tr><td>เงินสดคงเหลือ</td><td class="num" id="capCashTHB">—</td></tr>
                </table>
            </div>
            <div>
                <h3 class="subhead">พอร์ตเงินดอลลาร์ (USD)</h3>
                <table class="ledger" aria-label="เงินลงทุนสกุลดอลลาร์">
                    <tr><td>เงินต้นสะสม</td><td class="num" id="capNetUSD">—</td></tr>
                    <tr><td>เงินสดคงเหลือ</td><td class="num" id="capCashUSD">—</td></tr>
                </table>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table" aria-label="รายการเงินลงทุน">
                <thead>
                    <tr>
                        <th scope="col">วันที่</th>
                        <th scope="col">รายการ</th>
                        <th scope="col" class="num">จำนวนเงิน</th>
                        <th scope="col">สกุลเงิน</th>
                        <th scope="col">หมายเหตุ</th>
                        <th scope="col"><span class="sr-only">แก้ไข</span></th>
                    </tr>
                </thead>
                <tbody id="stkCapitalList">
                    <tr><td colspan="6"><span class="skel skel-w-52"></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Screenshots of the broker app -->
    <div class="stk-panel" data-stk-panel="screenshots" hidden>
        <p class="stk-note">เก็บภาพหน้าจอพอร์ตจากแอปโบรกเกอร์ไว้เทียบย้อนหลัง ภาพล่าสุดจะแสดงในหน้าที่แชร์</p>
        <button type="button" class="stk-dropzone" id="stkDropzone" data-click="#stkFileSelect">
            <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
            <span class="stk-dropzone-text">ลากรูปมาวาง หรือกดเพื่อเลือกไฟล์</span>
            <span class="stk-dropzone-sub">JPG, PNG, WEBP, GIF ไม่เกิน 20MB</span>
        </button>
        <input type="file" id="stkFileSelect" hidden accept="image/*" aria-label="เลือกรูปภาพพอร์ต" data-act="uploadScreenshot" data-args='["$el"]' data-on="change">
        <div class="stk-shots" id="stkScreenshotsGrid"></div>
    </div>

    <!-- Charts -->
    <div class="stk-panel" data-stk-panel="chart" hidden>
        <div class="stk-filters">
            <label class="form-label" for="stkChartYear">ปี</label>
            <select class="form-control" id="stkChartYear" data-act="loadStockChart" data-args='["$value"]' data-on="change">
                <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
                <option value="<?= $y ?>"<?= $y === (int)date('Y') ? ' selected' : '' ?>><?= $y + 543 ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="stk-charts">
            <figure>
                <figcaption>ต้นทุนสะสมกับมูลค่าตลาด (ประมาณ)</figcaption>
                <div class="stk-chart"><canvas id="stkValueChart" role="img" aria-label="กราฟต้นทุนสะสมและมูลค่าตลาดรายเดือน"></canvas></div>
            </figure>
            <figure>
                <figcaption>กำไร/ขาดทุนที่ยังไม่ปิด แยกตามหุ้น</figcaption>
                <div class="stk-chart"><canvas id="stkPlChart" role="img" aria-label="กราฟกำไรขาดทุนที่ยังไม่ปิดของแต่ละหุ้น"></canvas></div>
            </figure>
        </div>
    </div>

    <!-- AI read of one ticker -->
    <div class="stk-panel" data-stk-panel="analysis" hidden>
        <p class="stk-note">ใส่ชื่อหุ้นแล้วให้ AI สรุปปัจจัยพื้นฐาน แนวโน้มราคา และความเสี่ยงให้ ผลที่ได้เป็นความเห็นของโมเดล อาจผิดพลาดหรือล้าหลังได้ ใช้ประกอบการตัดสินใจเท่านั้น ไม่ใช่คำแนะนำการลงทุน</p>
        <form class="stk-analyze-form" id="stkAnalyzeForm" data-act="runStockAnalysis" novalidate>
            <div class="form-group">
                <label class="form-label" for="stkAnalyzeTicker">ชื่อหุ้น (Ticker)</label>
                <input type="text" class="form-control" id="stkAnalyzeTicker" placeholder="เช่น AAPL, PTT.BK, CPALL" maxlength="20" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="stkAnalyzeMarket">ตลาด</label>
                <select class="form-control" id="stkAnalyzeMarket">
                    <option value="US">US (สหรัฐฯ)</option>
                    <option value="SET">SET (ไทย)</option>
                    <option value="OTHER">อื่นๆ</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit" id="stkAnalyzeBtn">วิเคราะห์</button>
        </form>

        <div id="stkAnalyzeLoading" class="stk-analyzing" role="status" hidden>
            <p id="stkAnalyzeLoadingText">กำลังวิเคราะห์ อาจใช้เวลาครู่หนึ่ง</p>
            <div class="skel-row"><span class="skel skel-w-60"></span></div>
            <div class="skel-row"><span class="skel skel-w-45"></span></div>
            <div class="skel-row"><span class="skel skel-w-52"></span></div>
        </div>

        <div id="stkAnalyzeResult" class="stk-result" aria-live="polite" hidden></div>
    </div>
</section>

<!-- What a ratio means -->
<div class="modal-backdrop" id="metricModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="metricTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="metricTitle">ตัวชี้วัด</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body stk-metric" id="metricBody"></div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ปิด</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit trade -->
<div class="modal-backdrop" id="stockModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="stockModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="stockModalTitle">บันทึกรายการหุ้น</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editStockId">
            <div class="form-group">
                <div class="seg-pair" role="radiogroup" aria-label="ซื้อหรือขาย">
                    <label><input type="radio" name="stkSide" value="buy" checked><span>ซื้อ</span></label>
                    <label><input type="radio" name="stkSide" value="sell"><span>ขาย</span></label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="stkTicker">ชื่อหุ้น (Ticker)</label>
                    <input type="text" class="form-control" id="stkTicker" placeholder="AAPL, PTT.BK" maxlength="20" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="stkMarket">ตลาด</label>
                    <select class="form-control" id="stkMarket" data-act="onStkMarketChange" data-on="change">
                        <option value="US">US</option>
                        <option value="SET">SET</option>
                        <option value="OTHER">อื่นๆ</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="stkQty">จำนวน (หุ้น)</label>
                    <input type="number" class="form-control" id="stkQty" step="0.0001" min="0.0001" inputmode="decimal">
                </div>
                <div class="form-group">
                    <label class="form-label" for="stkPrice">ราคาต่อหุ้น</label>
                    <input type="number" class="form-control" id="stkPrice" step="0.0001" min="0" inputmode="decimal">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="stkCurrency">สกุลเงิน</label>
                    <input type="text" class="form-control" id="stkCurrency" value="USD" maxlength="3" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="stkFee">ค่าธรรมเนียม</label>
                    <input type="number" class="form-control" id="stkFee" step="0.0001" min="0" value="0" inputmode="decimal">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="stkDate">วันที่</label>
                    <input type="date" class="form-control" id="stkDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="stkNotes">หมายเหตุ</label>
                    <input type="text" class="form-control" id="stkNotes" maxlength="500">
                </div>
            </div>
            <p class="form-error" id="stkError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger mr-auto" type="button" id="deleteStockBtn" data-act="deleteStock" hidden>ลบรายการ</button>
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveStock">บันทึกรายการ</button>
        </div>
    </div>
</div>

<!-- Add/Edit money in or out -->
<div class="modal-backdrop" id="capitalModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="capitalModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="capitalModalTitle">บันทึกเงินลงทุน</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editCapitalId">
            <div class="form-group">
                <div class="seg-pair" role="radiogroup" aria-label="เติมเงินหรือถอนเงิน">
                    <label><input type="radio" name="capType" value="deposit" checked><span>เติมเงิน</span></label>
                    <label><input type="radio" name="capType" value="withdrawal"><span>ถอนเงิน</span></label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="capAmount">จำนวนเงิน</label>
                    <input type="number" class="form-control" id="capAmount" step="0.01" min="0.01" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label" for="capCurrency">สกุลเงิน</label>
                    <select class="form-control" id="capCurrency">
                        <option value="THB">THB (บาท)</option>
                        <option value="USD">USD (ดอลลาร์)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="capDate">วันที่</label>
                <input type="date" class="form-control" id="capDate" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="capNotes">หมายเหตุ</label>
                <input type="text" class="form-control" id="capNotes" maxlength="500" placeholder="เช่น เงินเดือนเข้า, ปันผล, โอนกลับ">
            </div>
            <p class="form-error" id="capError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger mr-auto" type="button" id="deleteCapitalBtn" data-act="deleteCapital" hidden>ลบรายการ</button>
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveCapital">บันทึกรายการ</button>
        </div>
    </div>
</div>

<!-- A screenshot at full size -->
<div class="modal-backdrop" id="screenshotLightboxModal" aria-hidden="true">
    <div class="modal modal-wide" role="dialog" aria-labelledby="lightboxTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="lightboxTitle">รูปภาพพอร์ต</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <img class="stk-lightbox-img" id="lightboxImage" src="" alt="">
            <p class="stk-note" id="lightboxDesc"></p>
        </div>
    </div>
</div>
