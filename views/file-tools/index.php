<div class="page-header">
    <div>
        <h1 class="page-title">จัดการไฟล์</h1>
        <div class="text-xs text-muted">เครื่องมือไฟล์ครบวงจร — PDF · รูปภาพ · แปลงข้อมูล · ZIP</div>
    </div>
</div>

<!-- Tab bar -->
<div class="ft-tabs" id="ftTabs">
    <button type="button" class="ft-tab active" data-tab="pdf">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        PDF
    </button>
    <button type="button" class="ft-tab" data-tab="image">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        รูปภาพ
    </button>
    <button type="button" class="ft-tab" data-tab="data">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
        แปลงข้อมูล
    </button>
    <button type="button" class="ft-tab" data-tab="zip">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        ZIP
    </button>
</div>

<div class="ft-panels">

<!-- ═══════════════════════════════════════════════════
     TAB: PDF
═══════════════════════════════════════════════════ -->
<section class="ft-panel active" data-panel="pdf">

    <!-- ── รวมไฟล์ PDF (Merge) ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">รวมไฟล์ PDF (Merge)</span><span class="text-xs text-muted">รวมหลายไฟล์เป็นไฟล์เดียว</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="mergeDrop" data-target="mergeInput">
                <div>ลากไฟล์ PDF มาวาง หรือ <label for="mergeInput" class="ft-link">คลิกเพื่อเลือก</label></div>
                <input type="file" id="mergeInput" accept=".pdf,application/pdf" multiple hidden>
            </div>
            <div id="mergeFileList" class="ft-file-list"></div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnMerge" disabled>รวมไฟล์ PDF (Merge)</button>
            </div>
            <div id="mergeStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── แยกไฟล์ PDF (Split) ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">แยกไฟล์ PDF (Split)</span><span class="text-xs text-muted">ระบุช่วงหน้าที่ต้องการ</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="splitDrop" data-target="splitInput">
                <div>ลากไฟล์ PDF หรือ <label for="splitInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="splitInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="splitFileName" class="ft-chosen-file"></div>
            <div class="form-group" style="margin-top:.5rem">
                <label class="form-label">ช่วงหน้า <span class="text-xs text-muted">(เช่น 1-3,5,7-9)</span></label>
                <input type="text" class="form-control" id="splitRange" placeholder="1-3,5,7-9">
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnSplit" disabled>แยกหน้า</button>
            </div>
            <div id="splitStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ลบหน้า PDF ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ลบหน้า PDF</span><span class="text-xs text-muted">เลือกหน้าที่ต้องการลบออก</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="deleteDrop" data-target="deleteInput">
                <div>ลากไฟล์ PDF หรือ <label for="deleteInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="deleteInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="deleteFileName" class="ft-chosen-file"></div>
            <div id="deletePageGrid" class="ft-page-grid" hidden></div>
            <div id="deleteSelInfo" class="text-xs text-muted" hidden></div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnDeletePages" disabled>ลบหน้าที่เลือก</button>
            </div>
            <div id="deleteStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── หมุนหน้า PDF ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">หมุนหน้า PDF</span><span class="text-xs text-muted">หมุนเฉพาะหน้าที่เลือก</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="rotateDrop" data-target="rotateInput">
                <div>ลากไฟล์ PDF หรือ <label for="rotateInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="rotateInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="rotateFileName" class="ft-chosen-file"></div>
            <div id="rotatePageGrid" class="ft-page-grid" hidden></div>
            <div class="form-group" id="rotateAngleRow" style="margin-top:.5rem" hidden>
                <label class="form-label">มุมหมุน</label>
                <div class="ft-btn-group">
                    <button type="button" class="btn btn-sm btn-ghost ft-angle-btn active" data-angle="90">90°</button>
                    <button type="button" class="btn btn-sm btn-ghost ft-angle-btn" data-angle="180">180°</button>
                    <button type="button" class="btn btn-sm btn-ghost ft-angle-btn" data-angle="270">270°</button>
                </div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnRotate" disabled>หมุนหน้าที่เลือก</button>
            </div>
            <div id="rotateStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ใส่ลายน้ำ ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ใส่ลายน้ำข้อความ</span><span class="text-xs text-muted">เพิ่มข้อความบนทุกหน้า</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="wmDrop" data-target="wmInput">
                <div>ลากไฟล์ PDF หรือ <label for="wmInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="wmInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="wmFileName" class="ft-chosen-file"></div>
            <div class="ft-form-grid" style="margin-top:.5rem">
                <div class="form-group">
                    <label class="form-label">ข้อความ</label>
                    <input type="text" class="form-control" id="wmText" placeholder="CONFIDENTIAL">
                </div>
                <div class="form-group">
                    <label class="form-label">ขนาดตัวอักษร</label>
                    <input type="number" class="form-control" id="wmSize" value="60" min="10" max="200">
                </div>
                <div class="form-group">
                    <label class="form-label">ความโปร่งใส (0–1)</label>
                    <input type="number" class="form-control" id="wmOpacity" value="0.25" min="0.05" max="1" step="0.05">
                </div>
                <div class="form-group">
                    <label class="form-label">สีข้อความ</label>
                    <input type="color" class="form-control" id="wmColor" value="#ff0000" style="height:2.5rem">
                </div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnWatermark" disabled>ใส่ลายน้ำ</button>
            </div>
            <div id="wmStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── รูปเป็น PDF ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">แปลงรูปภาพเป็น PDF</span><span class="text-xs text-muted">รวมรูป JPG/PNG หลายรูปเป็น PDF</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="img2pdfDrop" data-target="img2pdfInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="img2pdfInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="img2pdfInput" accept="image/jpeg,image/png,image/webp" multiple hidden>
            </div>
            <div id="img2pdfFileList" class="ft-file-list"></div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImg2Pdf" disabled>สร้าง PDF</button>
            </div>
            <div id="img2pdfStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── PDF เป็นรูป ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">แปลง PDF เป็นรูปภาพ</span><span class="text-xs text-muted">แปลงแต่ละหน้าเป็น PNG → ดาวน์โหลด ZIP</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="pdf2imgDrop" data-target="pdf2imgInput">
                <div>ลากไฟล์ PDF หรือ <label for="pdf2imgInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="pdf2imgInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="pdf2imgFileName" class="ft-chosen-file"></div>
            <div class="form-group" style="margin-top:.5rem">
                <label class="form-label">ความละเอียด</label>
                <select class="form-control" id="pdf2imgDpi">
                    <option value="1">72 DPI (เร็ว)</option>
                    <option value="2" selected>150 DPI (มาตรฐาน)</option>
                    <option value="3">216 DPI (สูง)</option>
                </select>
            </div>
            <div id="pdf2imgProgress" class="ft-progress-wrap" hidden>
                <div class="ft-progress-bar"><div class="ft-progress-fill" id="pdf2imgFill"></div></div>
                <div class="ft-progress-label" id="pdf2imgLabel"></div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnPdf2Img" disabled>แปลงเป็นรูป</button>
            </div>
            <div id="pdf2imgStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ใส่รหัสผ่าน PDF ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ใส่รหัสผ่าน PDF</span><span class="text-xs text-muted">ป้องกันการเปิดอ่านด้วยรหัสผ่าน</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="pwdDrop" data-target="pwdInput">
                <div>ลากไฟล์ PDF หรือ <label for="pwdInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="pwdInput" accept=".pdf,application/pdf" hidden>
            </div>
            <div id="pwdFileName" class="ft-chosen-file"></div>
            <div class="ft-form-grid" style="margin-top:.5rem">
                <div class="form-group">
                    <label class="form-label">รหัสผ่านผู้ใช้</label>
                    <input type="password" class="form-control" id="pwdUser" placeholder="รหัสผ่านสำหรับเปิดไฟล์">
                </div>
                <div class="form-group">
                    <label class="form-label">รหัสผ่านเจ้าของ</label>
                    <input type="password" class="form-control" id="pwdOwner" placeholder="รหัสผ่านแก้ไข (ไม่บังคับ)">
                </div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnPwd" disabled>ใส่รหัสผ่าน</button>
            </div>
            <div id="pwdStatus" class="ft-status"></div>
        </div>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════
     TAB: รูปภาพ
═══════════════════════════════════════════════════ -->
<section class="ft-panel" data-panel="image">

    <!-- ── แปลงนามสกุล ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">แปลงนามสกุลรูปภาพ</span><span class="text-xs text-muted">JPG · PNG · WEBP · GIF · BMP</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="imgConvDrop" data-target="imgConvInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="imgConvInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="imgConvInput" accept="image/*" hidden>
            </div>
            <div id="imgConvPreview" class="ft-img-preview" hidden></div>
            <div class="form-group" style="margin-top:.5rem">
                <label class="form-label">แปลงเป็น</label>
                <select class="form-control" id="imgConvFormat">
                    <option value="jpg">JPG</option>
                    <option value="png" selected>PNG</option>
                    <option value="webp">WEBP</option>
                    <option value="gif">GIF</option>
                    <option value="bmp">BMP</option>
                </select>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImgConv" disabled>แปลงไฟล์</button>
            </div>
            <div id="imgConvStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ปรับขนาด ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ปรับขนาดรูป</span><span class="text-xs text-muted">ตั้งความกว้าง/สูง</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="imgResizeDrop" data-target="imgResizeInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="imgResizeInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="imgResizeInput" accept="image/*" hidden>
            </div>
            <div id="imgResizeInfo" class="ft-chosen-file"></div>
            <div class="ft-form-grid" style="margin-top:.5rem">
                <div class="form-group">
                    <label class="form-label">ความกว้าง (px)</label>
                    <input type="number" class="form-control" id="resizeW" placeholder="เช่น 800" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">ความสูง (px)</label>
                    <input type="number" class="form-control" id="resizeH" placeholder="เช่น 600" min="1">
                </div>
            </div>
            <label class="flex items-center gap-2" style="gap:.5rem;margin-bottom:.5rem;font-size:.875rem">
                <input type="checkbox" id="resizeRatio" checked> รักษาอัตราส่วน
            </label>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImgResize" disabled>ปรับขนาด</button>
            </div>
            <div id="imgResizeStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── บีบอัด ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">บีบอัดรูปภาพ</span><span class="text-xs text-muted">ลดขนาดไฟล์โดยปรับคุณภาพ</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="imgCmpDrop" data-target="imgCmpInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="imgCmpInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="imgCmpInput" accept="image/*" hidden>
            </div>
            <div id="imgCmpInfo" class="ft-chosen-file"></div>
            <div class="form-group" style="margin-top:.5rem">
                <label class="form-label">คุณภาพ: <strong id="cmpQualityVal">80</strong>%</label>
                <input type="range" id="cmpQuality" min="10" max="100" value="80" class="ft-range">
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImgCmp" disabled>บีบอัดรูป</button>
            </div>
            <div id="imgCmpStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── หมุน/พลิก ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">หมุน / พลิกรูป</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="imgRotDrop" data-target="imgRotInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="imgRotInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="imgRotInput" accept="image/*" hidden>
            </div>
            <div id="imgRotInfo" class="ft-chosen-file"></div>
            <div class="ft-btn-group" style="margin-top:.5rem">
                <button type="button" class="btn btn-sm btn-ghost ft-op-btn active" data-op="rotate90">หมุนขวา 90°</button>
                <button type="button" class="btn btn-sm btn-ghost ft-op-btn" data-op="rotate180">หมุน 180°</button>
                <button type="button" class="btn btn-sm btn-ghost ft-op-btn" data-op="rotate270">หมุน 270°</button>
                <button type="button" class="btn btn-sm btn-ghost ft-op-btn" data-op="fliph">พลิกซ้าย-ขวา</button>
                <button type="button" class="btn btn-sm btn-ghost ft-op-btn" data-op="flipv">พลิกบน-ล่าง</button>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImgRot" disabled>ดำเนินการ</button>
            </div>
            <div id="imgRotStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ปรับสี/ฟิลเตอร์ ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ปรับสี / ฟิลเตอร์</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="imgFxDrop" data-target="imgFxInput">
                <div>ลากรูปภาพมาวาง หรือ <label for="imgFxInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="imgFxInput" accept="image/*" hidden>
            </div>
            <div id="imgFxInfo" class="ft-chosen-file"></div>
            <div class="ft-btn-group" style="margin-top:.5rem" id="fxOpBtns">
                <button type="button" class="btn btn-sm btn-ghost ft-fx-btn active" data-op="grayscale">ขาวดำ</button>
                <button type="button" class="btn btn-sm btn-ghost ft-fx-btn" data-op="blur">เบลอ</button>
                <button type="button" class="btn btn-sm btn-ghost ft-fx-btn" data-op="brightness">ความสว่าง</button>
                <button type="button" class="btn btn-sm btn-ghost ft-fx-btn" data-op="contrast">คอนทราสต์</button>
            </div>
            <div id="fxLevelRow" class="form-group" style="margin-top:.5rem" hidden>
                <label class="form-label" id="fxLevelLabel">ระดับ</label>
                <input type="range" class="ft-range" id="fxLevel" min="-100" max="100" value="30">
                <div class="text-xs text-muted" id="fxLevelVal">30</div>
            </div>
            <div id="fxBlurRow" class="form-group" style="margin-top:.5rem" hidden>
                <label class="form-label">ระดับเบลอ (1–20)</label>
                <input type="range" class="ft-range" id="fxBlurPasses" min="1" max="20" value="5">
                <div class="text-xs text-muted" id="fxBlurVal">5</div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnImgFx" disabled>ใช้ฟิลเตอร์</button>
            </div>
            <div id="imgFxStatus" class="ft-status"></div>
        </div>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════
     TAB: แปลงข้อมูล
═══════════════════════════════════════════════════ -->
<section class="ft-panel" data-panel="data">

    <!-- ── JSON ↔ CSV ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">JSON ↔ CSV</span></div>
        <div class="card-body">
            <div class="ft-btn-group" style="margin-bottom:.5rem">
                <button type="button" class="btn btn-sm btn-ghost ft-dir-btn active" data-dir="json2csv">JSON → CSV</button>
                <button type="button" class="btn btn-sm btn-ghost ft-dir-btn" data-dir="csv2json">CSV → JSON</button>
            </div>
            <textarea class="form-control ft-textarea" id="jcInput" placeholder='[{"name":"Alice","age":25}]'></textarea>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnJC">แปลง</button>
                <button class="btn btn-ghost" id="btnJCCopy">คัดลอก</button>
                <button class="btn btn-ghost" id="btnJCDownload">ดาวน์โหลด</button>
            </div>
            <textarea class="form-control ft-textarea ft-output" id="jcOutput" placeholder="ผลลัพธ์จะแสดงที่นี่" readonly></textarea>
            <div id="jcStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── JSON ↔ XML ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">JSON ↔ XML</span></div>
        <div class="card-body">
            <div class="ft-btn-group" style="margin-bottom:.5rem">
                <button type="button" class="btn btn-sm btn-ghost ft-dir-btn active" data-dir="json2xml">JSON → XML</button>
                <button type="button" class="btn btn-sm btn-ghost ft-dir-btn" data-dir="xml2json">XML → JSON</button>
            </div>
            <textarea class="form-control ft-textarea" id="jxInput" placeholder='{"root":{"item":"value"}}'></textarea>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnJX">แปลง</button>
                <button class="btn btn-ghost" id="btnJXCopy">คัดลอก</button>
                <button class="btn btn-ghost" id="btnJXDownload">ดาวน์โหลด</button>
            </div>
            <textarea class="form-control ft-textarea ft-output" id="jxOutput" placeholder="ผลลัพธ์จะแสดงที่นี่" readonly></textarea>
            <div id="jxStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── JSON Prettify / Minify ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">JSON Prettify / Minify</span></div>
        <div class="card-body">
            <textarea class="form-control ft-textarea" id="jsonFmtInput" placeholder='{"a":1,"b":2}'></textarea>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnJsonPretty">Prettify</button>
                <button class="btn btn-ghost" id="btnJsonMinify">Minify</button>
                <button class="btn btn-ghost" id="btnJsonFmtCopy">คัดลอก</button>
            </div>
            <textarea class="form-control ft-textarea ft-output" id="jsonFmtOutput" readonly></textarea>
        </div>
    </div>

    <!-- ── Base64 ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">Base64 Encode / Decode</span></div>
        <div class="card-body">
            <div class="ft-tabs-inner" style="margin-bottom:.5rem">
                <button type="button" class="btn btn-sm btn-ghost ft-b64-tab active" data-b64="text">ข้อความ</button>
                <button type="button" class="btn btn-sm btn-ghost ft-b64-tab" data-b64="file">ไฟล์</button>
            </div>
            <div id="b64TextSection">
                <textarea class="form-control ft-textarea" id="b64Input" placeholder="ข้อความ หรือ Base64"></textarea>
                <div class="ft-actions">
                    <button class="btn btn-primary" id="btnB64Enc">Encode</button>
                    <button class="btn btn-ghost" id="btnB64Dec">Decode</button>
                    <button class="btn btn-ghost" id="btnB64Copy">คัดลอก</button>
                </div>
                <textarea class="form-control ft-textarea ft-output" id="b64Output" readonly></textarea>
            </div>
            <div id="b64FileSection" hidden>
                <div class="ft-drop-zone" id="b64FileDrop" data-target="b64FileInput">
                    <div>ลากไฟล์มาวาง หรือ <label for="b64FileInput" class="ft-link">คลิกเลือก</label></div>
                    <input type="file" id="b64FileInput" hidden>
                </div>
                <div class="ft-actions" style="margin-top:.5rem">
                    <button class="btn btn-ghost" id="btnB64FileCopy">คัดลอก Base64</button>
                    <button class="btn btn-ghost" id="btnB64FileDownload">ดาวน์โหลดไฟล์ต้นฉบับ</button>
                </div>
                <textarea class="form-control ft-textarea ft-output" id="b64FileOutput" readonly style="font-size:.75rem"></textarea>
            </div>
        </div>
    </div>

    <!-- ── Hash ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">Hash ไฟล์ / ข้อความ</span><span class="text-xs text-muted">SHA-256 · SHA-1 · MD5</span></div>
        <div class="card-body">
            <div class="ft-tabs-inner" style="margin-bottom:.5rem">
                <button type="button" class="btn btn-sm btn-ghost ft-hash-tab active" data-hsrc="text">ข้อความ</button>
                <button type="button" class="btn btn-sm btn-ghost ft-hash-tab" data-hsrc="file">ไฟล์</button>
            </div>
            <div id="hashTextSection">
                <textarea class="form-control ft-textarea" id="hashInput" placeholder="พิมพ์ข้อความ…"></textarea>
            </div>
            <div id="hashFileSection" hidden>
                <div class="ft-drop-zone" id="hashFileDrop" data-target="hashFileInput">
                    <div>ลากไฟล์มาวาง หรือ <label for="hashFileInput" class="ft-link">คลิกเลือก</label></div>
                    <input type="file" id="hashFileInput" hidden>
                </div>
                <div id="hashFileInfo" class="ft-chosen-file"></div>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnHash">คำนวณ Hash</button>
            </div>
            <div id="hashResults" class="ft-hash-results"></div>
        </div>
    </div>

    <!-- ── URL Encode/Decode ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">URL Encode / Decode</span></div>
        <div class="card-body">
            <textarea class="form-control ft-textarea" id="urlInput" placeholder="ข้อความ หรือ URL ที่ต้องการแปลง"></textarea>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnUrlEnc">Encode</button>
                <button class="btn btn-ghost" id="btnUrlDec">Decode</button>
                <button class="btn btn-ghost" id="btnUrlCopy">คัดลอก</button>
            </div>
            <textarea class="form-control ft-textarea ft-output" id="urlOutput" readonly></textarea>
        </div>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════
     TAB: ZIP
═══════════════════════════════════════════════════ -->
<section class="ft-panel" data-panel="zip">

    <!-- ── บีบอัดเป็น ZIP ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">บีบอัดเป็น ZIP</span><span class="text-xs text-muted">รวมหลายไฟล์เป็น ZIP เดียว</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="zipCreateDrop" data-target="zipCreateInput">
                <div>ลากไฟล์มาวาง หรือ <label for="zipCreateInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="zipCreateInput" multiple hidden>
            </div>
            <div id="zipCreateFileList" class="ft-file-list"></div>
            <div class="form-group" style="margin-top:.5rem">
                <label class="form-label">ชื่อไฟล์ ZIP</label>
                <input type="text" class="form-control" id="zipCreateName" value="archive" placeholder="archive">
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnZipCreate" disabled>บีบอัดเป็น ZIP</button>
            </div>
            <div id="zipCreateStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── ตรวจสอบไฟล์ใน ZIP ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">ตรวจสอบไฟล์ใน ZIP</span><span class="text-xs text-muted">แสดงรายการไฟล์ภายใน</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="zipInspDrop" data-target="zipInspInput">
                <div>ลากไฟล์ ZIP มาวาง หรือ <label for="zipInspInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="zipInspInput" accept=".zip,application/zip,application/x-zip-compressed" hidden>
            </div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnZipInsp" disabled>ดูเนื้อหา</button>
            </div>
            <div id="zipInspResults" class="ft-zip-table-wrap" hidden></div>
            <div id="zipInspStatus" class="ft-status"></div>
        </div>
    </div>

    <!-- ── แตกไฟล์ ZIP (Extract) ── -->
    <div class="card ft-tool-card">
        <div class="card-header"><span class="card-title">แตกไฟล์ ZIP (Extract)</span><span class="text-xs text-muted">เลือกไฟล์ที่ต้องการดาวน์โหลด</span></div>
        <div class="card-body">
            <div class="ft-drop-zone" id="zipExtDrop" data-target="zipExtInput">
                <div>ลากไฟล์ ZIP มาวาง หรือ <label for="zipExtInput" class="ft-link">คลิกเลือก</label></div>
                <input type="file" id="zipExtInput" accept=".zip,application/zip,application/x-zip-compressed" hidden>
            </div>
            <div id="zipExtEntries" class="ft-zip-table-wrap" hidden></div>
            <div id="zipExtSelInfo" class="text-xs text-muted" style="margin-top:.5rem" hidden></div>
            <div class="ft-actions">
                <button class="btn btn-primary" id="btnZipExtAll" disabled>ดาวน์โหลดทั้งหมด</button>
                <button class="btn btn-ghost" id="btnZipExtSel" disabled>ดาวน์โหลดที่เลือก</button>
            </div>
            <div id="zipExtStatus" class="ft-status"></div>
        </div>
    </div>

</section>

</div><!-- /.ft-panels -->
