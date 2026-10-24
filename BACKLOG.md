# Backlog — งานที่ยังค้าง

รายการที่เหลือจากการตรวจโค้ดรอบกันยายน 2026 เรียงจากสำคัญมากไปน้อย ทำเสร็จข้อไหนให้ลบออกจากไฟล์นี้ แล้วบันทึกไว้ใน `CHANGELOG.md`

---

## 1. ต้องทำตอน deploy ครั้งถัดไป

- [ ] Rebuild image (`docker compose ... build app cron`) เพราะ config ของ Apache อยู่ใน image
- [ ] รัน `php scripts/migrate.php` บนเซิร์ฟเวอร์ (มี migration ใหม่ 024–026 และแอปไม่สร้างตารางเองแล้ว)
- [ ] รัน `php scripts/migrate.php` กับฐานข้อมูล XAMPP ในเครื่อง (`mylife_db`) ด้วย
- [ ] หลัง deploy: ตั้งข้อมูล demo ให้เรียบร้อยแล้วรัน `php scripts/demo-reset.php --snapshot` หนึ่งครั้ง ไม่งั้นการรีเซ็ตรายคืนจะไม่ทำงาน

## 2. ทดสอบบนเว็บจริงด้วยมือ (เทสต์อัตโนมัติทำไม่ได้)

- [ ] ล็อกอินด้วย Google กับบัญชีที่เปิด 2FA แล้วต้องถามรหัส 2FA
- [ ] เครื่องมือ PDF ใน File Tools (pdf.js และ worker ทำงานภายใต้ CSP)
- [ ] Export แล้ว Import กลับ ข้อมูลครบทุกโมดูล
- [ ] รูปหน้าจอหุ้นยังแสดงได้ (ตอนนี้โหลดผ่าน `/api/stocks/screenshots/{id}/image`)
- [ ] หน้าที่เปิด SweetAlert ยังทำงาน (pin เวอร์ชัน 11.26.25 พร้อม SRI)
- [ ] การแจ้งเตือนผ่านเบราว์เซอร์: ตั้ง `VAPID_*` ใน `.env` แล้วทดสอบ

## 3. Frontend

- [ ] **inline style ที่เหลือ** (วัดหลังออกแบบใหม่ 3 ต.ค. 2026): 10 จุดใน view, 60 จุดใน JS (57 จุดเป็นรายงาน PDF ของ `finance.js` ซึ่งต้องเป็นแบบนี้ เพราะรายงานต้องอยู่ได้ด้วยตัวเอง)
- [ ] **`!important` 8 คำสั่ง** (วัด 24 ต.ค. 2026) อยู่ใน utility ซ่อน/แสดงของ `base.css`, `components.css` และ `notes.css` ซึ่งตั้งใจให้ชนะกฎอื่น โหมดแชร์ไม่ใช้แล้ว
- [ ] **สี hex นอก `tokens.css`** ไม่มีใน CSS แล้ว (`check-css.mjs` ตรวจใน CI) เหลือ 81 จุดใน JS/PHP: รายงาน PDF 49, ที่เหลือเป็นสีหมวดเริ่มต้น/ตัวอย่างสีที่ผู้ใช้เลือกและ `theme-color` ของเบราว์เซอร์
- [ ] PHPStan: ระดับ 6 ผ่านแล้ว แต่ยังพักกฎ `missingType.iterableValue` ไว้ใน `phpstan.neon` (228 จุดที่ array ไม่บอกชนิดของค่า) เปิดกฎกลับเมื่อจะเขียน shape ของแถวข้อมูลจริง ๆ
- [ ] **เขตเวลาของผู้ใช้กับหน้าเว็บ** (ตัดสินใจ 24 ต.ค. 2026: ยังไม่ทำ เปลี่ยนป้ายให้ตรงความจริงแทน) การตั้งค่านี้ใช้กับวันของการแจ้งเตือน Telegram เท่านั้น หน้าเว็บใช้ `TIMEZONE` ของเซิร์ฟเวอร์ ถ้าวันหนึ่งจะให้หน้าเว็บใช้เขตเวลาของผู้ใช้ ต้องตั้ง PHP และ DB session ตามผู้ใช้ทุก request และย้าย `expires_at` ของ `app_shares`, `file_shares`, `file_transfers`, `remember_tokens` กับ `skill_active_timers.start_time` ไปเก็บแบบไม่ผูกกับเขตเวลาก่อน
- [ ] ตัดสินใจเรื่อง Composer + namespace เมื่อจะเริ่มใช้ library ภายนอก (รอบนี้เลือกข้ามไปก่อน)

---

## เครื่องมือตรวจที่ใช้ในรอบนี้

- `php tests/run.php http://localhost` รันใน container (389 เทสต์) ถ้าชื่อ container ชนกับโปรเจกต์เก่า ใช้ `docker compose -p dayflowtest -f docker-compose.yml -f <override>` ที่เปลี่ยน `container_name` และพอร์ต
- PHPStan: `docker run --rm -v "$PWD":/app -w /app ghcr.io/phpstan/phpstan:2.2.16 analyse`
- งาน CSS/JS ตรวจด้วยการเทียบภาพหน้าจอก่อน/หลังทีละพิกเซลผ่าน headless Edge (สคริปต์อยู่นอก repo ถ้าจะใช้อีกให้ขอให้สร้างใหม่)
