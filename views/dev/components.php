<?php
// =====================================================
// views/dev/components.php — every component, in every state it can be in
//
// Open /dev/components while developing. It is the page to check a change to
// components.css against: light and dark, a phone width, long text, and the
// empty, loading and error states a real list passes through.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ชุด component</h1>
        <p class="sub">ใช้ตรวจการเปลี่ยนแปลงของ components.css · เปิดได้เฉพาะตอนพัฒนา</p>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn" data-act="openModal" data-args='["demoModal"]'>เปิดหน้าต่าง</button>
        <button type="button" class="btn btn-primary" data-act="openQuickAdd">เพิ่มอย่างรวดเร็ว</button>
    </div>
</div>

<style>
    /* Layout of this gallery only. Nothing here is a component. */
    .gal-section { margin-bottom: var(--space-10); }
    .gal-section > h2 { margin-bottom: var(--space-3); padding-bottom: var(--space-1); border-bottom: 1px solid var(--rule-strong); font-size: 1.125rem; }
    .gal-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); margin-bottom: var(--space-3); }
    .gal-note { margin-bottom: var(--space-2); font-size: 0.8125rem; color: var(--pencil); }
    .gal-narrow { max-width: 360px; }
</style>

<section class="gal-section" id="buttons">
    <h2>ปุ่ม</h2>
    <p class="gal-note">หน้าละหนึ่งปุ่มหลัก ข้อความบอกสิ่งที่จะเกิด</p>
    <div class="gal-row">
        <button class="btn btn-primary">บันทึกรายจ่าย</button>
        <button class="btn">ยกเลิก</button>
        <button class="btn btn-ghost">ดูทั้งหมด</button>
        <button class="btn btn-danger">ลบงานนี้</button>
        <button class="btn btn-link">ล้างตัวกรอง</button>
    </div>
    <div class="gal-row">
        <button class="btn btn-primary btn-sm">เล็ก</button>
        <button class="btn btn-sm">เล็ก</button>
        <button class="btn btn-primary btn-lg">ใหญ่</button>
        <button class="btn btn-icon" aria-label="รีเฟรช"><svg class="icon"><use href="#i-search"/></svg></button>
        <button class="btn btn-primary" disabled>กำลังส่ง <span class="spinner" aria-hidden="true"></span></button>
        <button class="btn" disabled>ปิดใช้งาน</button>
    </div>
    <div class="gal-row">
        <button class="btn btn-primary">ข้อความที่ยาวมากเพื่อดูว่าปุ่มรับความยาวภาษาไทยได้ไหมโดยไม่ล้นกรอบ</button>
    </div>
</section>

<section class="gal-section" id="date-ledger">
    <h2>วันที่ รายการเส้นบรรทัด และสมุดบัญชี</h2>
    <div class="gal-row" style="align-items: stretch;">
        <div class="date-block"><span class="d">2</span><span class="m">ต.ค.</span></div>
        <div class="date-block" style="width: 72px;"><span class="d" style="font-size: 2.75rem;">28</span><span class="m">ก.ย.</span></div>
    </div>

    <div class="grid-2 mb-6">
        <div>
            <div class="ruled-list">
                <div class="ruled-row"><input type="checkbox" aria-label="เสร็จแล้ว"><span class="grow"><span class="title">ส่งรายงานสรุปโปรเจกต์ Q3 ให้หัวหน้าทีม</span><span class="meta">โปรเจค · เว็บไซต์บริษัท</span></span><span class="side late">เกิน 3 วัน</span></div>
                <div class="ruled-row"><input type="checkbox" aria-label="เสร็จแล้ว"><span class="grow"><span class="title">ชื่อรายการที่ยาวมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมากมาก</span><span class="meta">ตัดด้วย … ไม่ดันกรอบ</span></span><span class="side">วันนี้</span></div>
                <div class="ruled-row"><input type="checkbox" checked aria-label="เสร็จแล้ว"><span class="grow"><span class="title">จองโต๊ะร้านอาหาร</span></span><span class="side">เสร็จ 08:12</span></div>
            </div>
        </div>
        <div>
            <table class="ledger ledger-lg">
                <tr><td>ยอดยกมา</td><td class="num">8,214.25</td></tr>
                <tr><td>รายรับ</td><td class="num in">+45,000.00</td></tr>
                <tr><td>รายจ่าย</td><td class="num out">−3,286.50</td></tr>
                <tr class="total"><td>คงเหลือ</td><td class="num">49,927.75</td></tr>
            </table>
            <p class="gal-note mt-3">ตัวเลขแปดหลัก:</p>
            <table class="ledger ledger-lg">
                <tr class="total"><td>รวม</td><td class="num">12,345,678.90</td></tr>
            </table>
        </div>
    </div>
</section>

<section class="gal-section" id="forms">
    <h2>ฟอร์ม</h2>
    <div class="grid-2">
        <div>
            <div class="form-group">
                <label class="form-label" for="g-text">ชื่อรายการ</label>
                <input class="form-control" id="g-text" placeholder="เช่น ข้าวมันไก่ + ชาเย็น">
                <p class="form-hint">บอกรูปแบบก่อนพิมพ์ผิด ไม่ใช่หลังจากนั้น</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="g-err">จำนวนเงิน (บาท)</label>
                <input class="form-control" id="g-err" value="abc">
                <p class="form-error">ใส่จำนวนเงินเป็นตัวเลขที่มากกว่า 0</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="g-dis">ไม่แก้ได้</label>
                <input class="form-control" id="g-dis" value="ค่าที่ระบบตั้งให้" disabled>
            </div>
        </div>
        <div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="g-date">กำหนดส่ง</label>
                    <input class="form-control" type="date" id="g-date" value="2026-10-02">
                </div>
                <div class="form-group">
                    <label class="form-label" for="g-sel">หมวดหมู่</label>
                    <select class="form-control" id="g-sel"><option>อาหาร</option><option>เดินทาง</option></select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="g-area">หมายเหตุ</label>
                <textarea class="form-control" id="g-area" rows="3" placeholder="พิมพ์ได้หลายบรรทัด"></textarea>
            </div>
        </div>
    </div>

    <div class="gal-row">
        <label class="flex items-center gap-2"><input type="checkbox"> ยังไม่ติ๊ก</label>
        <label class="flex items-center gap-2"><input type="checkbox" checked> ติ๊กแล้ว</label>
        <label class="flex items-center gap-2"><input type="checkbox" disabled> ปิดใช้งาน</label>
        <label class="flex items-center gap-2"><input type="radio" name="g-radio"> ตัวเลือก ก</label>
        <label class="flex items-center gap-2"><input type="radio" name="g-radio" checked> ตัวเลือก ข</label>
        <label class="switch" aria-label="สวิตช์ปิด"><input type="checkbox"><span class="slider"></span></label>
        <label class="switch" aria-label="สวิตช์เปิด"><input type="checkbox" checked><span class="slider"></span></label>
    </div>
</section>

<section class="gal-section" id="messages">
    <h2>ข้อความ แจ้งเตือน และสถานะ</h2>
    <div class="alert">ข้อมูลเดือนนี้ยังไม่ครบ เพิ่มรายการได้ทุกเมื่อ</div>
    <div class="alert alert-danger">อัปโหลดไม่สำเร็จเพราะไฟล์เกิน 10 MB ลองบีบอัดแล้วส่งใหม่</div>
    <div class="alert alert-success">นำเข้าข้อมูลครบ 108 รายการ</div>
    <div class="alert alert-warning">ใช้งบรายจ่ายไปแล้ว 92% ของเดือนนี้</div>

    <div class="gal-row">
        <span class="badge">ปกติ</span>
        <span class="badge badge-gray">ร่าง</span>
        <span class="badge badge-danger">เกินกำหนด 3 วัน</span>
        <span class="badge badge-success">เสร็จแล้ว</span>
        <span class="badge badge-warning">วันนี้</span>
        <span class="badge badge-dark">ใหม่</span>
    </div>
    <div class="tag-list mb-4">
        <span class="tag">งาน</span><span class="tag active">ส่วนตัว</span><span class="tag">ไอเดีย</span>
        <span class="workout-type-badge" data-wtype="วิ่ง">วิ่ง</span>
    </div>
    <div class="gal-row">
        <span class="flex items-center gap-2"><span class="status-dot status-open"></span> ยังไม่เสร็จ</span>
        <span class="flex items-center gap-2"><span class="status-dot status-done"></span> เสร็จแล้ว</span>
        <span class="flex items-center gap-2"><span class="status-dot status-urgent"></span> เร่งด่วน</span>
    </div>
    <div class="gal-narrow mb-4">
        <div class="progress" role="img" aria-label="ใช้งบไป 13 เปอร์เซ็นต์"><div class="progress-bar" style="width: 13%"></div></div>
    </div>
    <div class="gal-row">
        <button class="btn" data-act="toast" data-args='["เพิ่มงานแล้ว","success"]'>toast: สำเร็จ</button>
        <button class="btn" data-act="toast" data-args='["บันทึกไม่สำเร็จ","danger"]'>toast: ผิดพลาด</button>
        <button class="btn" data-act="toast" data-args='["ใช้งบเกิน 90%","warning"]'>toast: เตือน</button>
        <button class="btn" data-act="toast" data-args='["ลบงานแล้ว"]'>toast: ทั่วไป</button>
    </div>
</section>

<section class="gal-section" id="table">
    <h2>ตาราง</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>วันที่</th><th>รายการ</th><th>หมวดหมู่</th><th class="num">จำนวน (บาท)</th></tr></thead>
            <tbody>
                <tr><td>2 ต.ค. 2569</td><td>เติมน้ำมัน</td><td><span class="tag">เดินทาง</span></td><td class="num">1,200.00</td></tr>
                <tr><td>1 ต.ค. 2569</td><td>เงินเดือนเดือนนี้</td><td><span class="tag">เงินเดือน</span></td><td class="num in" style="color: var(--ledger-green)">+45,000.00</td></tr>
                <tr><td>1 ต.ค. 2569</td><td>รายการที่ชื่อยาวมากเพื่อดูว่าตารางตัดข้อความหรือขยายแถวอย่างไร ไม่ให้ดันตัวเลขหลุดจอ</td><td><span class="tag">ที่พัก</span></td><td class="num">9,500.00</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="gal-section" id="states">
    <h2>ว่าง กำลังโหลด และผิดพลาด</h2>
    <div class="grid-3">
        <div>
            <p class="gal-note">ว่าง: ชวนลงมือ มีปุ่มให้ทำต่อ</p>
            <div class="empty-state">
                <p class="empty-state-title">ยังไม่มีรายการเดือนตุลาคม</p>
                <p class="empty-state-text">บันทึกรายรับหรือรายจ่ายแรก แล้วยอดคงเหลือจะขึ้นที่นี่</p>
                <button class="btn">บันทึกรายการแรก</button>
            </div>
        </div>
        <div>
            <p class="gal-note">กำลังโหลด: โครงของสิ่งที่จะมา</p>
            <div aria-busy="true">
                <div class="skel-row"><span class="skel box"></span><span class="skel" style="width: 62%"></span></div>
                <div class="skel-row"><span class="skel box"></span><span class="skel" style="width: 48%"></span></div>
                <div class="skel-row"><span class="skel box"></span><span class="skel" style="width: 55%"></span></div>
            </div>
        </div>
        <div>
            <p class="gal-note">ผิดพลาด: บอกสาเหตุและทางแก้</p>
            <div class="alert alert-danger" role="alert">
                โหลดรายการไม่สำเร็จเพราะเชื่อมต่อเซิร์ฟเวอร์ไม่ได้
                <div class="mt-2"><button class="btn btn-sm">ลองอีกครั้ง</button></div>
            </div>
        </div>
    </div>
</section>

<section class="gal-section" id="cards">
    <h2>กล่อง</h2>
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><span class="card-title">เงินเดือนตุลาคม</span><a class="btn-link" href="#">เปิดสมุดบัญชี</a></div>
            <div class="card-body">ใช้เมื่อสิ่งที่อยู่ด้วยกันต้องแยกจากรอบข้างจริง ๆ</div>
        </div>
        <div class="card card-sm">
            <div class="card-body">กล่องขนาดเล็ก ไม่มีหัว</div>
        </div>
    </div>
</section>

<!-- A dialog of the older kind, opened with openModal() -->
<div class="modal-backdrop" id="demoModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="demoModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="demoModalTitle">เพิ่มรายการ</h2>
            <button type="button" class="modal-close" data-close-modal aria-label="ปิด" data-act="closeModal" data-args='["demoModal"]'>&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="g-modal">ชื่อรายการ</label>
                <input class="form-control" id="g-modal">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-act="closeModal" data-args='["demoModal"]'>ยกเลิก</button>
            <button class="btn btn-primary" data-act="closeModal" data-args='["demoModal"]'>เพิ่มรายการ</button>
        </div>
    </div>
</div>
