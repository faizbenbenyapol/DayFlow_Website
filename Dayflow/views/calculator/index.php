<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">คำนวณ</h1>
        <div class="text-xs text-muted">เครื่องคำนวณครบทุกฟังก์ชัน — กว่า 40 เครื่องมือในที่เดียว</div>
    </div>
    <button class="btn btn-ghost btn-sm" id="btnClearHistory" type="button">ล้างประวัติ</button>
</div>

<!-- Search bar -->
<div class="calc-search">
    <input type="search" class="form-control" id="calcSearch"
           placeholder="ค้นหาเครื่องมือ… เช่น ส่วนลด, VAT, BMI, อายุ, ผ่อนรถ, ค่าไฟ, รหัสผ่าน">
    <div class="calc-search-hint text-xs text-muted">กด <kbd>/</kbd> เพื่อค้นหา · <kbd>Esc</kbd> เคลียร์</div>
</div>

<!-- Tab bar -->
<div class="calc-tabs" id="calcTabs">
    <button type="button" class="calc-tab active" data-tab="general">ทั่วไป</button>
    <button type="button" class="calc-tab" data-tab="percent">เปอร์เซ็นต์</button>
    <button type="button" class="calc-tab" data-tab="price">ราคา</button>
    <button type="button" class="calc-tab" data-tab="finance">การเงิน</button>
    <button type="button" class="calc-tab" data-tab="tax">ภาษี</button>
    <button type="button" class="calc-tab" data-tab="bills">บิล</button>
    <button type="button" class="calc-tab" data-tab="invest">ลงทุน</button>
    <button type="button" class="calc-tab" data-tab="convert">แปลงหน่วย</button>
    <button type="button" class="calc-tab" data-tab="health">สุขภาพ</button>
    <button type="button" class="calc-tab" data-tab="date">วันที่</button>
    <button type="button" class="calc-tab" data-tab="math">คณิต</button>
    <button type="button" class="calc-tab" data-tab="numbers">ตัวเลข</button>
    <button type="button" class="calc-tab" data-tab="tools">เครื่องมือ</button>
</div>

<div class="calc-layout">
<div class="calc-main">

<!-- ============ TAB: GENERAL ============ -->
<section class="calc-panel active" data-panel="general">
    <div class="card">
        <div class="card-header">
            <span class="card-title">เครื่องคิดเลข</span>
            <label class="flex items-center gap-2 text-xs text-muted" style="gap:.4rem">
                <input type="checkbox" id="sciToggle"> เครื่องคิดเลขวิทยาศาสตร์
            </label>
        </div>
        <div class="card-body">
            <input type="text" class="calc-display" id="calcDisplay" placeholder="0" inputmode="text" autocomplete="off">
            <div class="calc-subdisplay" id="calcSubDisplay">&nbsp;</div>

            <div class="calc-keypad" id="calcKeypad">
                <div class="calc-keypad-sci" id="keypadSci" hidden>
                    <button type="button" class="calc-key calc-key-fn" data-op="sin(">sin</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="cos(">cos</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="tan(">tan</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="log(">log</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="ln(">ln</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="sqrt(">√</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="^2">x²</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="^3">x³</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="^">xʸ</button>
                    <button type="button" class="calc-key calc-key-fn" data-insert="pi">π</button>
                    <button type="button" class="calc-key calc-key-fn" data-insert="e">e</button>
                    <button type="button" class="calc-key calc-key-fn" data-op="!">n!</button>
                </div>
                <div class="calc-keypad-main">
                    <button type="button" class="calc-key calc-key-alt" data-action="clear">C</button>
                    <button type="button" class="calc-key calc-key-alt" data-op="(">(</button>
                    <button type="button" class="calc-key calc-key-alt" data-op=")">)</button>
                    <button type="button" class="calc-key calc-key-alt" data-action="back">⌫</button>

                    <button type="button" class="calc-key" data-op="7">7</button>
                    <button type="button" class="calc-key" data-op="8">8</button>
                    <button type="button" class="calc-key" data-op="9">9</button>
                    <button type="button" class="calc-key calc-key-op" data-op="/">÷</button>

                    <button type="button" class="calc-key" data-op="4">4</button>
                    <button type="button" class="calc-key" data-op="5">5</button>
                    <button type="button" class="calc-key" data-op="6">6</button>
                    <button type="button" class="calc-key calc-key-op" data-op="*">×</button>

                    <button type="button" class="calc-key" data-op="1">1</button>
                    <button type="button" class="calc-key" data-op="2">2</button>
                    <button type="button" class="calc-key" data-op="3">3</button>
                    <button type="button" class="calc-key calc-key-op" data-op="-">−</button>

                    <button type="button" class="calc-key" data-op="0">0</button>
                    <button type="button" class="calc-key" data-op=".">.</button>
                    <button type="button" class="calc-key calc-key-primary" data-action="equals">=</button>
                    <button type="button" class="calc-key calc-key-op" data-op="+">+</button>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ TAB: PERCENT ============ -->
<section class="calc-panel" data-panel="percent">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">X% ของ Y</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เปอร์เซ็นต์ (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p1" placeholder="20"></div>
                <div class="form-group"><label class="form-label">ของจำนวน</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p1" placeholder="1500"></div>
            </div>
            <div class="calc-result" data-result="p1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">X เป็นกี่ % ของ Y</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">จำนวน X</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p2" placeholder="150"></div>
                <div class="form-group"><label class="form-label">จากทั้งหมด Y</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p2" placeholder="500"></div>
            </div>
            <div class="calc-result" data-result="p2">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">เพิ่ม / ลด % จากจำนวน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">จำนวน</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p3" placeholder="1000"></div>
                <div class="form-group"><label class="form-label">เปอร์เซ็นต์ (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p3" placeholder="15"></div>
            </div>
            <div class="calc-result" data-result="p3">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">% การเปลี่ยนแปลง (A → B)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ค่าเดิม A</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p4" placeholder="100"></div>
                <div class="form-group"><label class="form-label">ค่าใหม่ B</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="p4" placeholder="125"></div>
            </div>
            <div class="calc-result" data-result="p4">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: PRICE ============ -->
<section class="calc-panel" data-panel="price">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">คำนวณส่วนลด</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ราคาเต็ม (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr1" placeholder="1000"></div>
                <div class="form-group"><label class="form-label">ส่วนลด (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr1" placeholder="15"></div>
            </div>
            <div class="calc-result" data-result="pr1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">VAT (ภาษีมูลค่าเพิ่ม)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ยอดเงิน (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr2" placeholder="100"></div>
                <div class="form-group"><label class="form-label">อัตรา VAT (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr2" value="7"></div>
                <div class="form-group"><label class="form-label">โหมด</label>
                    <select class="form-control js-calc" data-calc="pr2" id="vatMode">
                        <option value="add">บวก VAT (ราคายังไม่รวม)</option>
                        <option value="extract">แยก VAT (ราคารวม VAT แล้ว)</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="pr2">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">กำไร / ต้นทุน (Markup & Margin)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ต้นทุน (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr3" placeholder="80"></div>
                <div class="form-group"><label class="form-label">ราคาขาย (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr3" placeholder="100"></div>
                <div class="form-group"><label class="form-label">กำไรที่คาดหวัง (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr3" placeholder="25"></div>
            </div>
            <div class="calc-result" data-result="pr3">—</div>
            <div class="text-xs text-muted mt-2">ใส่ต้นทุน+ราคาขาย → คำนวณกำไร/มาร์จิ้น หรือใส่ต้นทุน+% กำไรที่ต้องการ → คำนวณราคาขาย</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">แบ่งบิล (Split Bill)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ยอดรวม (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr4" placeholder="1200"></div>
                <div class="form-group"><label class="form-label">จำนวนคน</label>
                    <input type="number" step="1" min="1" class="form-control js-calc" data-calc="pr4" placeholder="4"></div>
                <div class="form-group"><label class="form-label">ทิป (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="pr4" value="0"></div>
            </div>
            <div class="calc-result" data-result="pr4">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header">
            <span class="card-title">เปรียบเทียบราคาต่อหน่วย</span>
            <button type="button" class="btn btn-ghost btn-sm" id="btnAddCompareItem">+ เพิ่มสินค้า</button>
        </div>
        <div class="card-body">
            <div id="compareList">
                <div class="compare-row">
                    <input type="text" class="form-control js-compare" placeholder="ชื่อสินค้า A">
                    <input type="number" step="any" class="form-control js-compare" placeholder="ราคา (฿)">
                    <input type="number" step="any" class="form-control js-compare" placeholder="ปริมาณ">
                    <input type="text" class="form-control js-compare" placeholder="หน่วย เช่น g, ml" value="g">
                </div>
                <div class="compare-row">
                    <input type="text" class="form-control js-compare" placeholder="ชื่อสินค้า B">
                    <input type="number" step="any" class="form-control js-compare" placeholder="ราคา (฿)">
                    <input type="number" step="any" class="form-control js-compare" placeholder="ปริมาณ">
                    <input type="text" class="form-control js-compare" placeholder="หน่วย" value="g">
                </div>
            </div>
            <div class="calc-result" data-result="compare">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: FINANCE ============ -->
<section class="calc-panel" data-panel="finance">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ดอกเบี้ย (Simple & Compound)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เงินต้น (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f1" placeholder="100000"></div>
                <div class="form-group"><label class="form-label">อัตราดอกเบี้ย (% ต่อปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f1" placeholder="3"></div>
                <div class="form-group"><label class="form-label">ระยะเวลา (ปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f1" placeholder="5"></div>
                <div class="form-group"><label class="form-label">ทบต้น/ปี</label>
                    <select class="form-control js-calc" data-calc="f1">
                        <option value="1">ปีละ 1 ครั้ง</option>
                        <option value="2">ทุก 6 เดือน</option>
                        <option value="4">รายไตรมาส</option>
                        <option value="12" selected>รายเดือน</option>
                        <option value="365">รายวัน</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="f1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ผ่อนชำระ / เงินกู้</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เงินต้น (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f2" placeholder="100000"></div>
                <div class="form-group"><label class="form-label">อัตราดอกเบี้ย (% ต่อปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f2" placeholder="6"></div>
                <div class="form-group"><label class="form-label">ระยะเวลา (เดือน)</label>
                    <input type="number" step="1" min="1" class="form-control js-calc" data-calc="f2" placeholder="24"></div>
            </div>
            <div class="calc-result" data-result="f2">—</div>
            <details class="mt-4">
                <summary class="text-sm text-muted" style="cursor:pointer">ตารางการผ่อน (amortization)</summary>
                <div class="table-wrap mt-2">
                    <table class="table calc-amort-table">
                        <thead><tr><th>งวด</th><th>ค่างวด</th><th>เงินต้น</th><th>ดอกเบี้ย</th><th>คงเหลือ</th></tr></thead>
                        <tbody id="amortBody"><tr><td colspan="5" class="text-center text-muted">—</td></tr></tbody>
                    </table>
                </div>
            </details>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">ออมเงินตามเป้าหมาย</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เป้าหมาย (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f3" placeholder="50000"></div>
                <div class="form-group"><label class="form-label">ระยะเวลา (เดือน)</label>
                    <input type="number" step="1" min="1" class="form-control js-calc" data-calc="f3" placeholder="10"></div>
                <div class="form-group"><label class="form-label">ดอกเบี้ยออม (% ต่อปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="f3" value="0"></div>
            </div>
            <div class="calc-result" data-result="f3">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: CONVERT ============ -->
<section class="calc-panel" data-panel="convert">
    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">แปลงหน่วย</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">หมวด</label>
                    <select class="form-control" id="convCategory">
                        <option value="length">ความยาว</option>
                        <option value="weight">น้ำหนัก</option>
                        <option value="temperature">อุณหภูมิ</option>
                        <option value="area">พื้นที่</option>
                        <option value="volume">ปริมาตร</option>
                        <option value="speed">ความเร็ว</option>
                        <option value="time">เวลา</option>
                        <option value="data">ข้อมูล</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">จาก</label>
                    <div class="flex" style="gap:.5rem">
                        <input type="number" step="any" class="form-control" id="convFromValue" placeholder="1">
                        <select class="form-control" id="convFromUnit" style="max-width:140px"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">ไป</label>
                    <div class="flex" style="gap:.5rem">
                        <input type="text" class="form-control" id="convToValue" readonly>
                        <select class="form-control" id="convToUnit" style="max-width:140px"></select>
                    </div>
                </div>
            </div>
            <div class="calc-result" data-result="conv">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: HEALTH ============ -->
<section class="calc-panel" data-panel="health">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">BMI — ดัชนีมวลกาย</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">น้ำหนัก (กก.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="h1" placeholder="70"></div>
                <div class="form-group"><label class="form-label">ส่วนสูง (ซม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="h1" placeholder="170"></div>
            </div>
            <div class="calc-result" data-result="h1">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">BMR / TDEE — พลังงานต่อวัน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เพศ</label>
                    <select class="form-control js-calc" data-calc="h2">
                        <option value="male">ชาย</option>
                        <option value="female">หญิง</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">อายุ (ปี)</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="h2" placeholder="30"></div>
                <div class="form-group"><label class="form-label">น้ำหนัก (กก.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="h2" placeholder="70"></div>
                <div class="form-group"><label class="form-label">ส่วนสูง (ซม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="h2" placeholder="170"></div>
                <div class="form-group"><label class="form-label">กิจกรรม</label>
                    <select class="form-control js-calc" data-calc="h2">
                        <option value="1.2">นั่งทำงาน / ไม่ออกกำลัง</option>
                        <option value="1.375">ออกกำลังเบา 1–3 วัน/สัปดาห์</option>
                        <option value="1.55" selected>ออกกำลังปานกลาง 3–5 วัน</option>
                        <option value="1.725">ออกกำลังหนัก 6–7 วัน</option>
                        <option value="1.9">นักกีฬา / งานใช้แรงมาก</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="h2">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: DATE ============ -->
<section class="calc-panel" data-panel="date">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ผลต่างระหว่างวันที่</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">จากวันที่</label>
                    <input type="date" class="form-control js-calc" data-calc="d1"></div>
                <div class="form-group"><label class="form-label">ถึงวันที่</label>
                    <input type="date" class="form-control js-calc" data-calc="d1"></div>
            </div>
            <div class="calc-result" data-result="d1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">บวก / ลบวัน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">วันที่เริ่มต้น</label>
                    <input type="date" class="form-control js-calc" data-calc="d2"></div>
                <div class="form-group"><label class="form-label">จำนวน</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="d2" placeholder="30"></div>
                <div class="form-group"><label class="form-label">หน่วย</label>
                    <select class="form-control js-calc" data-calc="d2">
                        <option value="d">วัน</option>
                        <option value="w">สัปดาห์</option>
                        <option value="m">เดือน</option>
                        <option value="y">ปี</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">การดำเนินการ</label>
                    <select class="form-control js-calc" data-calc="d2">
                        <option value="add">บวก (+)</option>
                        <option value="sub">ลบ (−)</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="d2">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">คำนวณอายุ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">วันเกิด</label>
                    <input type="date" class="form-control js-calc" data-calc="d3"></div>
                <div class="form-group"><label class="form-label">ณ วันที่</label>
                    <input type="date" class="form-control js-calc" data-calc="d3"></div>
            </div>
            <div class="calc-result" data-result="d3">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: TAX ============ -->
<section class="calc-panel" data-panel="tax">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ภาษีเงินได้บุคคลธรรมดา (ขั้นบันได)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เงินได้สุทธิต่อปี (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax1" placeholder="500000"></div>
            </div>
            <div class="calc-result" data-result="tax1">—</div>
            <div class="text-xs text-muted mt-2">ใช้อัตราภาษีไทยปัจจุบัน 0%–35% (ยกเว้น 150,000 แรก)</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">วางแผนภาษีจากเงินเดือน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เงินเดือน (บาท/เดือน)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax2" placeholder="50000"></div>
                <div class="form-group"><label class="form-label">โบนัส/ปี (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax2" value="0"></div>
                <div class="form-group"><label class="form-label">ประกันสังคม/ปี</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax2" value="9000"></div>
                <div class="form-group"><label class="form-label">ลดหย่อนอื่น ๆ</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax2" value="60000"></div>
            </div>
            <div class="calc-result" data-result="tax2">—</div>
            <div class="text-xs text-muted mt-2">คิดจากค่าใช้จ่ายส่วนตัว 100,000 + ลดหย่อนส่วนตัว 60,000 + ที่กรอก</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">หัก ณ ที่จ่าย</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ยอดเงิน (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="tax3" placeholder="10000"></div>
                <div class="form-group"><label class="form-label">อัตรา (%)</label>
                    <select class="form-control js-calc" data-calc="tax3">
                        <option value="1">1% (ค่าขนส่ง)</option>
                        <option value="2">2% (ค่าโฆษณา)</option>
                        <option value="3" selected>3% (บริการ/รับจ้าง)</option>
                        <option value="5">5% (ค่าเช่า)</option>
                        <option value="10">10% (เงินปันผล/วิชาชีพ)</option>
                        <option value="15">15% (ดอกเบี้ย)</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="tax3">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: BILLS ============ -->
<section class="calc-panel" data-panel="bills">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ค่าไฟฟ้า (บ้านอยู่อาศัย)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">หน่วยที่ใช้ (kWh)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b1" placeholder="250"></div>
                <div class="form-group"><label class="form-label">ประเภท</label>
                    <select class="form-control js-calc" data-calc="b1">
                        <option value="small" selected>ใช้ไฟฟ้าไม่เกิน 150 หน่วย/เดือน (ประเภท 1.1)</option>
                        <option value="large">ใช้ไฟฟ้าเกิน 150 หน่วย/เดือน (ประเภท 1.2)</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="b1">—</div>
            <div class="text-xs text-muted mt-2">รวมค่า FT + VAT 7% (อัตราอ้างอิง MEA/PEA)</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ค่าน้ำมันรถ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ระยะทาง (กม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b2" placeholder="400"></div>
                <div class="form-group"><label class="form-label">อัตราสิ้นเปลือง (กม./ลิตร)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b2" placeholder="14"></div>
                <div class="form-group"><label class="form-label">ราคาน้ำมัน (฿/ลิตร)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b2" placeholder="38"></div>
            </div>
            <div class="calc-result" data-result="b2">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ค่าประมาณสีทาบ้าน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ความกว้าง (ม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b3" placeholder="10"></div>
                <div class="form-group"><label class="form-label">ความสูง (ม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b3" placeholder="2.8"></div>
                <div class="form-group"><label class="form-label">จำนวนด้าน</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="b3" value="4"></div>
                <div class="form-group"><label class="form-label">จำนวนเที่ยว</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="b3" value="2"></div>
                <div class="form-group"><label class="form-label">พื้นที่ต่อแกลลอน (ตร.ม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b3" value="35"></div>
            </div>
            <div class="calc-result" data-result="b3">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">ค่ากระเบื้อง/วัสดุปูพื้น</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">พื้นที่ห้อง (ตร.ม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b4" placeholder="30"></div>
                <div class="form-group"><label class="form-label">ขนาดกระเบื้อง (ซม.)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b4" value="60"></div>
                <div class="form-group"><label class="form-label">ราคา/แผ่น (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b4" placeholder="120"></div>
                <div class="form-group"><label class="form-label">เผื่อวัสดุเสียหาย (Waste %)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="b4" value="10"></div>
            </div>
            <div class="calc-result" data-result="b4">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: INVEST ============ -->
<section class="calc-panel" data-panel="invest">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">กำไร/ขาดทุน (ROI)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ซื้อที่ราคา</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i1" placeholder="100"></div>
                <div class="form-group"><label class="form-label">ขายที่ราคา</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i1" placeholder="130"></div>
                <div class="form-group"><label class="form-label">จำนวน</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i1" value="1"></div>
                <div class="form-group"><label class="form-label">ค่าธรรมเนียมรวม (%)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i1" value="0"></div>
            </div>
            <div class="calc-result" data-result="i1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">DCA — ลงทุนสม่ำเสมอ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ลงทุนต่อเดือน (บาท)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i2" placeholder="5000"></div>
                <div class="form-group"><label class="form-label">ผลตอบแทนคาดหวัง (% ต่อปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i2" placeholder="8"></div>
                <div class="form-group"><label class="form-label">ระยะเวลา (ปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i2" placeholder="20"></div>
                <div class="form-group"><label class="form-label">เงินต้นเริ่มต้น</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i2" value="0"></div>
            </div>
            <div class="calc-result" data-result="i2">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">วางแผนเกษียณ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">อายุปัจจุบัน</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="i3" placeholder="30"></div>
                <div class="form-group"><label class="form-label">อายุเกษียณ</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="i3" value="60"></div>
                <div class="form-group"><label class="form-label">ค่าใช้จ่ายต่อเดือน (ปัจจุบัน)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i3" placeholder="30000"></div>
                <div class="form-group"><label class="form-label">คาดอยู่ถึงอายุ</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="i3" value="85"></div>
                <div class="form-group"><label class="form-label">เงินเฟ้อ (% ต่อปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i3" value="3"></div>
                <div class="form-group"><label class="form-label">ผลตอบแทนลงทุน (%/ปี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="i3" value="6"></div>
            </div>
            <div class="calc-result" data-result="i3">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: MATH ============ -->
<section class="calc-panel" data-panel="math">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">สมการกำลังสอง ax² + bx + c = 0</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">a</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m1" placeholder="1"></div>
                <div class="form-group"><label class="form-label">b</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m1" placeholder="-5"></div>
                <div class="form-group"><label class="form-label">c</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m1" placeholder="6"></div>
            </div>
            <div class="calc-result" data-result="m1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">สามเหลี่ยมมุมฉาก (Pythagoras)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ด้าน a</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m2" placeholder="3"></div>
                <div class="form-group"><label class="form-label">ด้าน b</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m2" placeholder="4"></div>
                <div class="form-group"><label class="form-label">หรือด้านฉาก c</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m2"></div>
            </div>
            <div class="calc-result" data-result="m2">—</div>
            <div class="text-xs text-muted mt-2">กรอก 2 ด้านใดก็ได้ — ระบบหาอีกด้าน + มุม</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">วงกลม</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">รัศมี (r)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m3" placeholder="5"></div>
            </div>
            <div class="calc-result" data-result="m3">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">พื้นที่รูปเรขาคณิต</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">รูป</label>
                    <select class="form-control js-calc" data-calc="m4">
                        <option value="rect">สี่เหลี่ยมผืนผ้า</option>
                        <option value="tri">สามเหลี่ยม (ฐาน × สูง ÷ 2)</option>
                        <option value="trap">สี่เหลี่ยมคางหมู</option>
                        <option value="para">พื้นที่สี่เหลี่ยมด้านขนาน</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">ค่า 1</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m4" placeholder="10"></div>
                <div class="form-group"><label class="form-label">ค่า 2</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m4" placeholder="5"></div>
                <div class="form-group"><label class="form-label">ค่า 3 (ถ้ามี)</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="m4"></div>
            </div>
            <div class="calc-result" data-result="m4">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: NUMBERS ============ -->
<section class="calc-panel" data-panel="numbers">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">แปลงฐานเลข (Binary/Decimal/Hex/Octal)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ค่า</label>
                    <input type="text" class="form-control js-calc" data-calc="n1" placeholder="255"></div>
                <div class="form-group"><label class="form-label">ฐานต้นทาง</label>
                    <select class="form-control js-calc" data-calc="n1">
                        <option value="10" selected>ฐาน 10 (ทศนิยม)</option>
                        <option value="2">ฐาน 2 (ไบนารี)</option>
                        <option value="8">ฐาน 8 (ออคทัล)</option>
                        <option value="16">ฐาน 16 (เฮกซ์)</option>
                    </select>
                </div>
            </div>
            <div class="calc-result" data-result="n1">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ห.ร.ม. / ค.ร.น. (GCD / LCM)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">เลขชุด (คั่นด้วยจุลภาค)</label>
                    <input type="text" class="form-control js-calc" data-calc="n2" placeholder="12, 18, 24"></div>
            </div>
            <div class="calc-result" data-result="n2">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">ตรวจจำนวนเฉพาะ / แยกตัวประกอบ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">จำนวน</label>
                    <input type="number" step="1" class="form-control js-calc" data-calc="n3" placeholder="97"></div>
            </div>
            <div class="calc-result" data-result="n3">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">สถิติพื้นฐาน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="grid-column:1 / -1"><label class="form-label">ชุดข้อมูล (คั่นด้วย , หรือเว้นวรรค)</label>
                    <textarea class="form-control js-calc" data-calc="n4" rows="2" placeholder="10, 20, 25, 30, 35, 40"></textarea>
                </div>
            </div>
            <div class="calc-result" data-result="n4">—</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">สุ่มตัวเลข / สุ่มรายการ</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ตั้งแต่</label>
                    <input type="number" step="1" class="form-control" id="randMin" value="1"></div>
                <div class="form-group"><label class="form-label">ถึง</label>
                    <input type="number" step="1" class="form-control" id="randMax" value="100"></div>
                <div class="form-group"><label class="form-label">จำนวนที่สุ่ม</label>
                    <input type="number" step="1" min="1" max="100" class="form-control" id="randCount" value="1"></div>
                <div class="form-group"><label class="form-label">ไม่ซ้ำ</label>
                    <select class="form-control" id="randUnique">
                        <option value="1">ใช่</option>
                        <option value="0">ได้ซ้ำ</option>
                    </select>
                </div>
            </div>
            <div class="flex" style="gap:.5rem; margin-bottom:1rem">
                <button type="button" class="btn btn-primary btn-sm" id="btnRandom">สุ่มเลย</button>
                <button type="button" class="btn btn-ghost btn-sm" id="btnFlipCoin">โยนเหรียญ</button>
                <button type="button" class="btn btn-ghost btn-sm" id="btnRollDice">ทอยลูกเต๋า</button>
            </div>
            <div class="calc-result" data-result="rand">—</div>
        </div>
    </div>
</section>

<!-- ============ TAB: TOOLS ============ -->
<section class="calc-panel" data-panel="tools">
    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">สร้างรหัสผ่าน</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">ความยาว</label>
                    <input type="number" step="1" min="4" max="128" class="form-control" id="pwLen" value="16"></div>
                <div class="form-group"><label class="form-label">จำนวนที่สร้าง</label>
                    <input type="number" step="1" min="1" max="20" class="form-control" id="pwCount" value="3"></div>
            </div>
            <div class="flex flex-wrap" style="gap:1rem; margin-bottom:1rem">
                <label class="flex items-center" style="gap:.3rem"><input type="checkbox" id="pwUpper" checked> A–Z</label>
                <label class="flex items-center" style="gap:.3rem"><input type="checkbox" id="pwLower" checked> a–z</label>
                <label class="flex items-center" style="gap:.3rem"><input type="checkbox" id="pwDigit" checked> 0–9</label>
                <label class="flex items-center" style="gap:.3rem"><input type="checkbox" id="pwSym" checked> !@#$%</label>
                <label class="flex items-center" style="gap:.3rem"><input type="checkbox" id="pwExclude"> ไม่ใช้ตัวคล้ายกัน (0,O,1,l,I)</label>
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="btnGenPw">สร้างรหัสผ่าน</button>
            <div class="calc-result" data-result="pw">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">นับตัวอักษร / คำ</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">ข้อความ</label>
                <textarea class="form-control js-calc" data-calc="text" rows="5" placeholder="พิมพ์หรือวางข้อความ…"></textarea>
            </div>
            <div class="calc-result" data-result="text">—</div>
        </div>
    </div>

    <div class="card mb-6 calc-tool">
        <div class="card-header"><span class="card-title">อัตราส่วน / บัญญัติไตรยางศ์</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">A</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="ratio" placeholder="2"></div>
                <div class="form-group"><label class="form-label">B</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="ratio" placeholder="5"></div>
                <div class="form-group"><label class="form-label">C</label>
                    <input type="number" step="any" class="form-control js-calc" data-calc="ratio" placeholder="10"></div>
            </div>
            <div class="calc-result" data-result="ratio">—</div>
            <div class="text-xs text-muted mt-2">A : B = C : ? → หาค่า ? (ถ้า 2 บาทซื้อได้ 5 ชิ้น 10 บาทซื้อได้กี่ชิ้น)</div>
        </div>
    </div>

    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">เวลานอน (รอบละ 90 นาที)</span></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">โหมด</label>
                    <select class="form-control js-calc" data-calc="sleep">
                        <option value="wake">อยากตื่นเวลา…</option>
                        <option value="sleep">จะเข้านอนเวลา…</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">เวลา</label>
                    <input type="time" class="form-control js-calc" data-calc="sleep" value="07:00"></div>
            </div>
            <div class="calc-result" data-result="sleep">—</div>
            <div class="text-xs text-muted mt-2">+ เวลา 15 นาทีสำหรับหลับ · แนะนำ 5–6 รอบ (7.5–9 ชม.)</div>
        </div>
    </div>
</section>

</div><!-- /.calc-main -->

<aside class="calc-side">
    <div class="card calc-tool">
        <div class="card-header"><span class="card-title">ประวัติการคำนวณ</span></div>
        <div class="card-body calc-history" id="calcHistory">
            <div class="text-xs text-muted text-center">ยังไม่มีประวัติ</div>
        </div>
    </div>
</aside>

</div><!-- /.calc-layout -->
