<script>
    const IS_READ_ONLY = <?= Auth::isReadOnly() ? 'true' : 'false' ?>;
</script>

<?php if (Auth::isReadOnly()): ?>
    <!-- Shared Mode Layout (Only show header, 3 summary cards, THB Capital, and Latest Screenshot in full size) -->
    <div class="stk-share-layout">
        <div class="page-header flex items-center justify-between">
            <h1 class="page-title">สรุปพอร์ตหุ้น</h1>
            <span class="text-sm text-muted" id="stkRefreshedAt"></span>
        </div>

        <!-- Summary cards (Only 3 cards) -->
        <div class="stk-summary-bar mb-8" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stk-stat-card">
                <div class="stk-stat-label">มูลค่ารวม (ตลาด)</div>
                <div class="stk-stat-amount" id="stkMarketValue">—</div>
            </div>
            <div class="stk-stat-card">
                <div class="stk-stat-label">ต้นทุน</div>
                <div class="stk-stat-amount" id="stkCostBasis">—</div>
            </div>
            <div class="stk-stat-card">
                <div class="stk-stat-label">กำไร/ขาดทุน (Unrealized)</div>
                <div class="stk-stat-amount" id="stkUnrealized">—</div>
                <div class="stk-stat-sub" id="stkUnrealizedPct"></div>
            </div>
        </div>

        <div class="stk-share-grid">
            <!-- THB Capital Card -->
            <div class="card mb-6">
                <div class="card-header" style="padding-bottom: var(--space-3)">
                    <h3 class="card-title" style="font-size:1.05rem; font-weight:700">เงินลงทุน (THB)</h3>
                </div>
                <div class="card-body flex flex-col gap-5" style="padding-top: var(--space-4); padding-bottom: var(--space-5)">
                    <div class="stk-sidebar-stat">
                        <span class="stk-sidebar-label">เงินลงทุนทั้งหมด (Net Capital)</span>
                        <span class="stk-sidebar-value" id="sideNetTHB">0.00 THB</span>
                    </div>
                    <div class="stk-sidebar-stat">
                        <span class="stk-sidebar-label">เงินสดคงเหลือ (Cash Balance)</span>
                        <span class="stk-sidebar-value" id="sideCashTHB">0.00 THB</span>
                    </div>
                </div>
            </div>

            <!-- Latest Screenshot Card -->
            <div class="card">
                <div class="card-header" style="padding-bottom: var(--space-3)">
                    <h3 class="card-title" style="font-size:1.05rem; font-weight:700">รูปภาพพอร์ตล่าสุด</h3>
                </div>
                <div class="card-body" id="sideScreenshotContainer" style="padding-top: var(--space-4)">
                    <div class="text-center text-muted py-6">ไม่มีรูปภาพพอร์ตแนบไว้</div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Normal Mode Layout (Full Width) -->
    <div class="page-header flex items-center justify-between">
        <h1 class="page-title">หุ้น</h1>
        <div class="flex items-center gap-3">
            <span class="text-sm text-muted" id="stkRefreshedAt"></span>
            <button class="btn btn-ghost btn-sm" id="stkRefreshBtn" onclick="refreshPrices()" title="ดึงราคาล่าสุดจากผู้ให้บริการ"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;vertical-align:text-bottom"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v6h6"/></svg>รีเฟรชราคา</button>
            <button class="btn btn-primary btn-sm mode-readonly-hide" onclick="openAddStock()">+ บันทึกรายการ</button>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="stk-summary-bar">
        <div class="stk-stat-card">
            <div class="stk-stat-label">มูลค่ารวม (ตลาด)</div>
            <div class="stk-stat-amount" id="stkMarketValue">—</div>
        </div>
        <div class="stk-stat-card">
            <div class="stk-stat-label">ต้นทุน</div>
            <div class="stk-stat-amount" id="stkCostBasis">—</div>
        </div>
        <div class="stk-stat-card">
            <div class="stk-stat-label">กำไร/ขาดทุน (Unrealized)</div>
            <div class="stk-stat-amount" id="stkUnrealized">—</div>
            <div class="stk-stat-sub" id="stkUnrealizedPct"></div>
        </div>
        <div class="stk-stat-card">
            <div class="stk-stat-label">กำไร/ขาดทุน (Realized)</div>
            <div class="stk-stat-amount" id="stkRealized">—</div>
        </div>
    </div>

    <!-- Main Tabbed Table for Stocks -->
    <div class="card mb-8">
        <div class="card-header" style="border-bottom: 1px solid var(--color-border); padding-bottom: 0;">
            <div class="stk-main-tabs" role="tablist">
                <button class="stk-main-tab" data-main-tab="watchlists" type="button">Watchlists</button>
                <button class="stk-main-tab active" data-main-tab="portfolio" type="button">พอร์ตปัจจุบัน</button>
                <button class="stk-main-tab" data-main-tab="all" type="button">All Stocks</button>
                <button class="stk-main-tab" data-main-tab="US" type="button">US</button>
                <button class="stk-main-tab" data-main-tab="SET" type="button">SET</button>
                <button class="stk-main-tab" data-main-tab="OTHER" type="button">OTHER</button>
            </div>
        </div>
        
        <div class="table-wrap">
            <table class="table stk-holdings-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center">★</th>
                        <th>Ticker</th>
                        <th style="text-align:right">ราคาล่าสุด</th>
                        <th style="text-align:right">เปลี่ยนแปลง</th>
                        <th style="text-align:right" class="stk-clickable-header" onclick="showMetricExplain('pe')" title="คลิกดูคำอธิบาย P/E">P/E</th>
                        <th style="text-align:right" class="stk-clickable-header" onclick="showMetricExplain('forward_pe')" title="คลิกดูคำอธิบาย Forward P/E">Forward P/E</th>
                        <th style="text-align:right" class="stk-clickable-header" onclick="showMetricExplain('peg')" title="คลิกดูคำอธิบาย PEG">PEG</th>
                        <th style="text-align:right" class="stk-clickable-header" onclick="showMetricExplain('p_fcf')" title="คลิกดูคำอธิบาย P/FCF">P/FCF</th>
                        <th style="text-align:right" class="stk-clickable-header" onclick="showMetricExplain('eps')" title="คลิกดูคำอธิบาย EPS">EPS</th>
                        <th style="text-align:right" class="stk-col-portfolio">จำนวน</th>
                        <th style="text-align:right" class="stk-col-portfolio">ต้นทุนเฉลี่ย</th>
                        <th style="text-align:right" class="stk-col-portfolio">มูลค่าตลาด</th>
                        <th style="text-align:right" class="stk-col-portfolio">กำไร/ขาดทุน</th>
                        <th style="width:140px;text-align:center">AI</th>
                    </tr>
                </thead>
                <tbody id="stkUnifiedList">
                    <tr><td colspan="14" class="text-center text-muted" style="padding:2rem">กำลังโหลด...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sub-tabs -->
    <div class="card">
        <div class="card-header">
            <div class="stk-tabs" role="tablist">
                <button class="stk-tab active" data-stk-tab="transactions" type="button">รายการซื้อ-ขาย</button>
                <button class="stk-tab" data-stk-tab="capital" type="button">เงินลงทุน</button>
                <button class="stk-tab" data-stk-tab="screenshots" type="button">รูปภาพพอร์ต</button>
                <button class="stk-tab" data-stk-tab="chart" type="button">กราฟ</button>
                <button class="stk-tab" data-stk-tab="analysis" type="button">วิเคราะห์หุ้นด้วย AI</button>
            </div>
            <div class="flex gap-3" id="stkTxnFilters">
                <input type="text" class="form-control" id="stkTickerFilter" placeholder="กรอง Ticker"
                       style="width:140px;text-transform:uppercase" oninput="loadStockTransactions()">
                <select class="form-control" id="stkMarketFilter" style="width:auto" onchange="loadStockTransactions()">
                    <option value="">ทุกตลาด</option>
                    <option value="US">US</option>
                    <option value="SET">SET</option>
                    <option value="OTHER">อื่นๆ</option>
                </select>
            </div>
        </div>

        <!-- Transactions panel -->
        <div class="stk-panel active" data-stk-panel="transactions">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>วันที่</th>
                            <th>Ticker</th>
                            <th>ฝั่ง</th>
                            <th style="text-align:right">จำนวน</th>
                            <th style="text-align:right">ราคา</th>
                            <th style="text-align:right">ค่าธรรมเนียม</th>
                            <th style="text-align:right">มูลค่า</th>
                            <th class="mode-readonly-hide"></th>
                        </tr>
                    </thead>
                    <tbody id="stkTxnList">
                        <tr><td colspan="8" class="text-center text-muted" style="padding:2rem">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Capital panel -->
        <div class="stk-panel" data-stk-panel="capital" style="display:none">
            <div class="card-body">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="stk-analysis-title" style="margin:0">สรุปเงินลงทุน & เงินสดคงเหลือ</h3>
                    <button class="btn btn-primary btn-sm mode-readonly-hide" onclick="openAddCapital()">+ บันทึกเงินลงทุน</button>
                </div>
                
                <!-- Net capital and cash balance dashboards -->
                <div class="stk-capital-dashboard mb-6">
                    <div class="stk-cap-card">
                        <div class="stk-cap-header">พอร์ตเงินบาท (THB)</div>
                        <div class="stk-cap-grid">
                            <div class="stk-cap-item">
                                <span class="stk-cap-label">เงินต้นสะสม</span>
                                <span class="stk-cap-val" id="capNetTHB">0.00 THB</span>
                            </div>
                            <div class="stk-cap-item">
                                <span class="stk-cap-label">เงินสดคงเหลือ</span>
                                <span class="stk-cap-val" id="capCashTHB">0.00 THB</span>
                            </div>
                        </div>
                    </div>
                    <div class="stk-cap-card">
                        <div class="stk-cap-header">พอร์ตเงินดอลลาร์ (USD)</div>
                        <div class="stk-cap-grid">
                            <div class="stk-cap-item">
                                <span class="stk-cap-label">เงินต้นสะสม</span>
                                <span class="stk-cap-val" id="capNetUSD">0.00 USD</span>
                            </div>
                            <div class="stk-cap-item">
                                <span class="stk-cap-label">เงินสดคงเหลือ</span>
                                <span class="stk-cap-val" id="capCashUSD">0.00 USD</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Capital flows table -->
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>ประเภทรายการ</th>
                                <th style="text-align:right">จำนวนเงิน</th>
                                <th>สกุลเงิน</th>
                                <th>หมายเหตุ</th>
                                <th class="mode-readonly-hide"></th>
                            </tr>
                        </thead>
                        <tbody id="stkCapitalList">
                            <tr><td colspan="6" class="text-center text-muted" style="padding:2rem">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Screenshots panel -->
        <div class="stk-panel" data-stk-panel="screenshots" style="display:none">
            <div class="card-body">
                <div class="stk-upload-header mb-6">
                    <h3 class="stk-analysis-title" style="margin:0">รูปภาพพอร์ต Dime</h3>
                    <p class="text-sm text-muted" style="margin-top:4px">แนบรูปภาพภาพหน้าจอพอร์ตของคุณจากแอป Dime เพื่อความสะดวกในการติดตามดูการเติบโตแบบ Visual</p>
                </div>

                <?php if (!Auth::isReadOnly()): ?>
                <!-- Upload drag and drop zone -->
                <div class="stk-dropzone mb-8" id="stkDropzone" onclick="document.getElementById('stkFileSelect').click()">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mb-2" style="color:var(--color-accent)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div class="stk-dropzone-text">ลากและวางรูปภาพตรงนี้ หรือ <span>คลิกเพื่อเลือกไฟล์</span></div>
                    <div class="stk-dropzone-sub">เฉพาะ JPG, PNG, WEBP, GIF (สูงสุด 20MB)</div>
                    <input type="file" id="stkFileSelect" style="display:none" accept="image/*" onchange="uploadScreenshot(this)">
                </div>
                <?php endif; ?>

                <!-- Screenshots gallery grid -->
                <div class="stk-screenshots-grid" id="stkScreenshotsGrid">
                    <!-- Dynamic screenshots cards will go here -->
                </div>
            </div>
        </div>

        <!-- Chart panel -->
        <div class="stk-panel" data-stk-panel="chart" style="display:none">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-3">
                    <label class="form-label" style="margin:0">ปี</label>
                    <select class="form-control" id="stkChartYear" style="width:auto" onchange="loadStockChart(this.value)">
                        <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == (int)date('Y') ? 'selected' : '' ?>><?= $y + 543 ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="stk-chart-grid">
                    <div class="chart-wrap">
                        <canvas id="stkValueChart"></canvas>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="stkPlChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analysis panel -->
        <div class="stk-panel" data-stk-panel="analysis" style="display:none">
            <div class="card-body">
                <div class="stk-analysis-header">
                    <h3 class="stk-analysis-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ai-glow-icon" style="margin-right:6px;vertical-align:text-bottom;color:var(--color-accent)"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>วิเคราะห์หุ้นเชิงลึกด้วย AI</h3>
                    <p class="text-sm text-muted">เพียงระบุสัญลักษณ์หุ้นที่ต้องการ ระบบจะทำการตรวจสอบราคาปัจจุบัน วิเคราะห์ปัจจัยพื้นฐาน ข้อมูลทางเทคนิค แนวรับ-แนวต้าน และสรุปข้อเสนอแนะการลงทุนด้วยปัญญาประดิษฐ์</p>
                </div>
                
                <div class="stk-analysis-form-wrap">
                    <div class="stk-analysis-form">
                        <div class="form-group mb-0">
                            <label class="form-label">สัญลักษณ์หุ้น (Ticker)</label>
                            <input type="text" class="form-control text-upper" id="stkAnalyzeTicker" placeholder="เช่น AAPL, PTT.BK, CPALL" style="text-transform:uppercase" maxlength="20">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">ตลาด</label>
                            <select class="form-control" id="stkAnalyzeMarket">
                                <option value="US">US (ตลาดหุ้นสหรัฐฯ)</option>
                                <option value="SET">SET (ตลาดหุ้นไทย)</option>
                                <option value="OTHER">อื่นๆ</option>
                            </select>
                        </div>
                        <div class="form-group mb-0 flex items-end">
                            <button class="btn btn-ai-sparkle" id="stkAnalyzeBtn" onclick="runStockAnalysis()" style="width:100%;height:38px">
                                <svg class="sparkle-svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="margin-right:6px;vertical-align:middle"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C8.57 12.05 8 10.61 8 9c0-2.21 1.79-4 4-4s4 1.79 4 4c0 1.61-.57 3.05-2.15 4.1z"/></svg>
                                วิเคราะห์ด้วย AI
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading State Skeleton -->
                <div id="stkAnalyzeLoading" class="stk-analysis-loading-box" style="display:none">
                    <div class="ai-scanner-line"></div>
                    <div class="loading-spinner-wrap">
                        <div class="ai-loading-sparkle">✦</div>
                        <div class="ai-loading-spinner"></div>
                    </div>
                    <div class="loading-text-anim" id="stkAnalyzeLoadingText">กำลังดึงข้อมูล...</div>
                    <div class="loading-sub-text">ขุมพลัง AI กำลังทำการคำนวณและประเมินผลเชิงลึกระดับพรีเมียม</div>
                </div>

                <!-- Analysis Result Output Container -->
                <div id="stkAnalyzeResult" class="stk-analysis-result-container" style="display:none">
                    <!-- Will be dynamically generated by JS -->
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Add/Edit modal -->
<div class="modal-backdrop" id="stockModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="stockModalTitle">บันทึกรายการหุ้น</span>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editStockId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ticker</label>
                    <input type="text" class="form-control" id="stkTicker" placeholder="AAPL, PTT.BK" style="text-transform:uppercase" maxlength="20">
                </div>
                <div class="form-group">
                    <label class="form-label">ตลาด</label>
                    <select class="form-control" id="stkMarket" onchange="onStkMarketChange()">
                        <option value="US">US</option>
                        <option value="SET">SET</option>
                        <option value="OTHER">อื่นๆ</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ฝั่ง</label>
                    <select class="form-control" id="stkSide">
                        <option value="buy">ซื้อ</option>
                        <option value="sell">ขาย</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">สกุลเงิน</label>
                    <input type="text" class="form-control" id="stkCurrency" value="USD" maxlength="3" style="text-transform:uppercase">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">จำนวน (หุ้น)</label>
                    <input type="number" class="form-control" id="stkQty" step="0.0001" min="0.0001">
                </div>
                <div class="form-group">
                    <label class="form-label">ราคา / หุ้น</label>
                    <input type="number" class="form-control" id="stkPrice" step="0.0001" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ค่าธรรมเนียม</label>
                    <input type="number" class="form-control" id="stkFee" step="0.0001" min="0" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="stkDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <input type="text" class="form-control" id="stkNotes" maxlength="500">
            </div>
        </div>
        <div class="modal-footer" style="justify-content:space-between">
            <button class="btn btn-ghost" id="deleteStockBtn" onclick="deleteStock()" style="color:var(--color-danger);display:none">ลบ</button>
            <div class="flex gap-3" style="margin-left:auto">
                <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveStock()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Capital modal -->
<div class="modal-backdrop" id="capitalModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="capitalModalTitle">บันทึกรายการเงินลงทุน</span>
            <button class="modal-close" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editCapitalId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ประเภทรายการ</label>
                    <select class="form-control" id="capType">
                        <option value="deposit">เติมเงิน (Deposit)</option>
                        <option value="withdrawal">ถอนเงิน (Withdrawal)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">สกุลเงิน</label>
                    <select class="form-control" id="capCurrency">
                        <option value="THB">THB (บาท)</option>
                        <option value="USD">USD (ดอลลาร์)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">จำนวนเงิน</label>
                    <input type="number" class="form-control" id="capAmount" step="0.01" min="0.01" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="capDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <input type="text" class="form-control" id="capNotes" maxlength="500" placeholder="เช่น เงินเดือนเข้า, ปันผล, โอนเงินกลับ">
            </div>
        </div>
        <div class="modal-footer" style="justify-content:space-between">
            <button class="btn btn-ghost" id="deleteCapitalBtn" onclick="deleteCapital()" style="color:var(--color-danger);display:none">ลบ</button>
            <div class="flex gap-3" style="margin-left:auto">
                <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveCapital()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Screenshot Lightbox modal -->
<div class="modal-backdrop" id="screenshotLightboxModal" style="--modal-width: 90vw; --modal-max-width: 1000px;">
    <div class="modal" style="background:transparent;box-shadow:none;border:none;padding:0">
        <div class="flex justify-between items-center mb-2" style="position:relative;margin-bottom:10px">
            <span id="lightboxTitle" style="color:white;font-weight:600;font-size:1.1rem;text-shadow:0 2px 4px rgba(0,0,0,0.5)">ดูรูปภาพ</span>
            <button class="btn btn-ghost" data-close-modal style="color:white;font-size:2rem;padding:0 10px;line-height:1;background:rgba(0,0,0,0.3);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;padding:0">
            <img id="lightboxImage" src="" alt="Screenshot" style="max-width:100%;max-height:75vh;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);border:1px solid rgba(255,255,255,0.1);display:inline-block">
            <div id="lightboxDesc" style="color:rgba(255,255,255,0.8);margin-top:12px;font-size:0.95rem;text-shadow:0 1px 2px rgba(0,0,0,0.5)"></div>
        </div>
    </div>
</div>

