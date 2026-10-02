# Backlog — งานที่ยังค้าง

รายการที่เหลือจากการตรวจโค้ดรอบกันยายน 2026 เรียงจากสำคัญมากไปน้อย ทำเสร็จข้อไหนให้ลบออกจากไฟล์นี้ แล้วบันทึกไว้ใน `CHANGELOG.md`

---

## 1. ต้องทำตอน deploy ครั้งถัดไป

- [ ] Rebuild image (`docker compose ... build app cron`) เพราะ config ของ Apache อยู่ใน image
- [ ] รัน `php scripts/migrate.php` บนเซิร์ฟเวอร์ (มี migration ใหม่ 024–026 และแอปไม่สร้างตารางเองแล้ว)
- [ ] รัน `php scripts/migrate.php` กับฐานข้อมูล XAMPP ในเครื่อง (`mylife_db`) ด้วย
- [ ] หลัง deploy: ตั้งข้อมูล demo ให้เรียบร้อยแล้วรัน `php scripts/demo-reset.php --snapshot` หนึ่งครั้ง ไม่งั้นการรีเซ็ตรายคืนจะไม่ทำงาน
- [ ] ย้ายหัวข้อ `[Unreleased]` ใน `CHANGELOG.md` เป็นเลขเวอร์ชัน และแก้เวอร์ชันใน `README.md`

## 2. ทดสอบบนเว็บจริงด้วยมือ (เทสต์อัตโนมัติทำไม่ได้)

- [ ] ล็อกอินด้วย Google กับบัญชีที่เปิด 2FA แล้วต้องถามรหัส 2FA
- [ ] เครื่องมือ PDF ใน File Tools (pdf.js และ worker ทำงานภายใต้ CSP)
- [ ] Export แล้ว Import กลับ ข้อมูลครบทุกโมดูล
- [ ] รูปหน้าจอหุ้นยังแสดงได้ (ตอนนี้โหลดผ่าน `/api/stocks/screenshots/{id}/image`)
- [ ] หน้าที่เปิด SweetAlert ยังทำงาน (pin เวอร์ชัน 11.26.25 พร้อม SRI)
- [ ] การแจ้งเตือนผ่านเบราว์เซอร์: ตั้ง `VAPID_*` ใน `.env` แล้วทดสอบ

## 3. Frontend

- [ ] **inline style ที่เหลือ 551 จุด**
  - 57 จุดอยู่ในรายงาน PDF ของ `finance.js` ต้องเป็นแบบนี้ เพราะรายงานต้องอยู่ได้ด้วยตัวเอง
  - ราว 60 จุดเป็น `display:none` ที่ JS ใช้ซ่อน/แสดง
  - ที่เหลือส่วนใหญ่เป็นจุดเดียวไม่ซ้ำใครใน `views/stocks`, `views/projects`, `views/finance`, `dashboard.js`, `stocks.js`
- [ ] **`!important` 135 จุด** ไล่ดู cascade ทีละไฟล์ โดยเฉพาะ `settings.css` (26), `projects.css` (22), `files.css` (21), `app.css` (21) ระวังสถานะที่ภาพหน้าจอจับไม่ได้ เช่น hover และ focus
- [ ] **สี hardcode ที่เหลือ 162 จุด** ส่วนใหญ่ใช้ครั้งเดียวหรือเป็นสีขาว บางจุดไม่เปลี่ยนตาม dark mode ต้องตัดสินใจเรื่องดีไซน์ก่อน
- [ ] PHPStan ระดับ 6 ขึ้นไป (ตอนนี้ระดับ 5 ผ่าน 0 error) ส่วนใหญ่ต้องเพิ่ม type ให้ array
- [ ] ตัดสินใจเรื่อง Composer + namespace เมื่อจะเริ่มใช้ library ภายนอก (รอบนี้เลือกข้ามไปก่อน)
- [ ] บัญชี demo: snapshot ยังไม่ครอบคลุมโปรเจคที่ผู้เยี่ยมชมสร้างไว้ (ตอนนี้ค้างจนกว่าจะลบเอง)

---

## เครื่องมือตรวจที่ใช้ในรอบนี้

- `php tests/run.php http://localhost` รันใน container (358 เทสต์) ถ้าชื่อ container ชนกับโปรเจกต์เก่า ใช้ `docker compose -p dayflowtest -f docker-compose.yml -f <override>` ที่เปลี่ยน `container_name` และพอร์ต
- PHPStan: `docker run --rm -v "$PWD":/app -w /app ghcr.io/phpstan/phpstan:2.2.16 analyse`
- งาน CSS/JS ตรวจด้วยการเทียบภาพหน้าจอก่อน/หลังทีละพิกเซลผ่าน headless Edge (สคริปต์อยู่นอก repo ถ้าจะใช้อีกให้ขอให้สร้างใหม่)
