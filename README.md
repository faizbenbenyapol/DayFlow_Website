# DayFlow — Productivity & Life Management Platform

ระบบบริหารจัดการประสิทธิภาพการทำงานแบบครบวงจร (All-in-One Productivity Platform) รวมงาน โน้ต แพลนเนอร์ โครงการ การเงิน สุขภาพ และอีกหลายเครื่องมือไว้ในที่เดียว ออกแบบภายใต้แนวคิด Neumorphism & Soft UI รองรับการแสดงผลทุกขนาดหน้าจอ

**เวอร์ชัน: v1.3.0**

เข้าใช้งานได้ที่: **https://dayflow.benyapol.com**

---

## ฟีเจอร์ทั้งหมดของระบบ (Complete Feature Set)

### 1. Dashboard (แดชบอร์ดหลัก)
- ศูนย์รวมข้อมูลสรุปประจำวัน — งาน, โน้ต, การเงิน, สุขภาพ
- Widget แบบ **Modular** ที่สามารถเปิด/ปิดและจัดลำดับได้ตามต้องการ
- บันทึกตำแหน่ง Layout ต่อ User ผ่าน API (`/api/dashboard/layout`)
- Quick Summary Widget แสดงสถิติรายวันแบบ Real-time

### 2. Notes (บันทึกอัจฉริยะ)
- Editor แบบ **Block-Based** รองรับ 3 ประเภทบล็อก: Text, Link, Checklist
- ปักหมุด (Pin) บันทึกสำคัญและจัดหมวดหมู่ผ่าน **Tags**
- **Note Encryption** — เข้ารหัสบันทึกส่วนตัวด้วย AES-256-CBC ต้องใส่รหัสผ่านเพื่อถอดรหัส
- Reorder Blocks ด้วย Drag-and-Drop
- แบ่งปันบันทึกผ่าน Public Link พร้อมกำหนดวันหมดอายุ

### 3. Planner (ปฏิทินและ To-Do รายวัน)
- ปฏิทินรายเดือน รองรับ Event สร้าง/แก้ไข/ลบ
- **Daily To-Do List** — งานรายวันพร้อมระบบ Reorder
- ส่งแจ้งเตือน Planner ผ่าน Telegram อัตโนมัติ

### 4. Projects & Tasks (โครงการและงาน)
- **Kanban Board** — คอลัมน์ Todo / In Progress / Done พร้อม Drag-and-Drop
- บริหารสมาชิกโครงการ (Project Members) และกำหนดสิทธิ์
- **Project Chat** — ห้องสนทนาภายในโครงการ
- **Activity Log** — ติดตามประวัติการเปลี่ยนแปลงในโครงการ
- แชร์โครงการผ่าน Public Token (`/project/shared/{token}`)
- กำหนด Priority, Due Date, Status ต่อ Task
- **Personal Tasks** — งานส่วนตัวแยกจากโครงการ พร้อม Reorder

### 5. Pomodoro Focus Timer (จับเวลาโฟกัส)
- Timer แบบ Pomodoro ตั้งเวลาทำงานและพัก
- บันทึก Session Log ทุกรอบ
- แสดงสถิติการโฟกัสสะสม

### 6. Exercise & Workout (บันทึกการออกกำลังกาย)
- บันทึก Workout รายวันพร้อมระบุประเภทท่าออกกำลังกาย (Sets/Reps/Weight)
- จัดการ **Exercise Categories** แบบ Custom
- แสดงสถิติรายสัปดาห์ (`/api/exercise/stats`)

### 7. Food Notes (บันทึกโภชนาการ)
- บันทึกรายการอาหารและแคลอรีประจำวัน
- แสดงสรุปพลังงานที่ได้รับในแต่ละมื้อ

### 8. Finance & Budget (การเงินและงบประมาณ)
- บันทึกรายรับ-รายจ่ายส่วนบุคคลแบบ Real-time
- **Custom Categories** สำหรับรายรับและรายจ่าย
- สรุปงบประมาณรายเดือนและกราฟวิเคราะห์ทางการเงิน (`/api/finance/chart`)

### 9. Subscriptions (ติดตามการสมัครสมาชิก)
- บันทึก Subscription และค่าใช้จ่ายซ้ำรายคาบ (Weekly / Monthly / Yearly / One-time)
- ระบบ **Renew** บันทึกรอบถัดไปอัตโนมัติ
- แจ้งเตือนผ่าน Telegram เมื่อใกล้ครบกำหนด

### 10. Stocks Portfolio (พอร์ตการลงทุน)
- บันทึก Transaction ซื้อ-ขายสินทรัพย์ (หุ้น, กองทุน, คริปโต)
- ดึงราคาตลาดผ่าน **Stock API Key** (รองรับหลาย Provider)
- Price Cache 5 นาที เพื่อลดการเรียก API
- **Watchlist** — ติดตามราคาสินทรัพย์ที่สนใจ
- **Capital Tracking** — บันทึกเงินทุนที่ใส่เข้าพอร์ต
- **Screenshots** — อัปโหลดภาพหน้าจอพอร์ตการลงทุน
- **AI Analysis** — วิเคราะห์พอร์ตด้วย AI (`/api/stocks/analyze`)
- สรุป P&L, กราฟมูลค่าพอร์ต (`/api/stocks/chart`)

### 11. AI Assistant (ผู้ช่วย AI)
- รองรับหลาย AI Provider (บันทึก API Key ต่อ Provider ต่อ User)
- ทดสอบ Key ก่อนบันทึก (`/api/ai/keys/test`)
- **Script Generation** — สร้างเนื้อหาหรือสรุปข้อความ
- **Section Regeneration** — สร้างเนื้อหาส่วนย่อยใหม่
- **Video Generation** — สร้างวิดีโอ พร้อมตรวจสอบสถานะ Async (`/api/ai/video/{id}/status`)
- ประวัติการสร้างเนื้อหา (Generation History)

### 12. Skills Time Tracker (ติดตามเวลาพัฒนาทักษะ)
- สร้าง Skill พร้อมกำหนด Target Hours (เป้าหมายชั่วโมง)
- **Live Timer** — เริ่ม/หยุดจับเวลาพร้อมบันทึก Log อัตโนมัติ
- แสดงสถิติความคืบหน้าต่อ Skill
- ดูประวัติ Session Log และลบรายการได้

### 13. Daily Habits (นิสัยประจำวัน)
- สร้างนิสัยที่อยากทำประจำวัน พร้อมกำหนด Target Days ต่อสัปดาห์ (1–7 วัน)
- Toggle เสร็จ/ยังไม่เสร็จในแต่ละวัน
- แสดงสถิติ Streak และ Completion Rate

### 14. Quick Notes (จดด่วน)
- จดข้อความสั้น (สูงสุด 500 ตัวอักษร) แบบ Capture รวดเร็ว
- Toggle สถานะเสร็จ/ยังไม่เสร็จ

### 15. Bookmarks (ลิงก์สำคัญ)
- บันทึก URL พร้อมชื่อและหมวดหมู่
- รองรับเฉพาะ `http://` และ `https://` เพื่อความปลอดภัย

### 16. File Manager (จัดการไฟล์)
- อัปโหลดและจัดการไฟล์ส่วนตัวในโครงสร้างโฟลเดอร์
- สร้างโฟลเดอร์, เปลี่ยนชื่อ, ย้าย, ลบไฟล์
- ดาวน์โหลดไฟล์ผ่าน Secure Download Endpoint

### 17. File Tools (เครื่องมือไฟล์)
- **Image Tool** — ปรับขนาด, แปลงฟอร์แมต, Compress รูปภาพ (PNG, JPEG, WEBP)
- **ZIP Creator** — สร้างไฟล์ ZIP จากหลายไฟล์
- **ZIP Inspector** — ดูรายการไฟล์ภายใน ZIP
- **ZIP Extractor** — แตกไฟล์จาก ZIP

### 18. File Transfer (ส่งไฟล์ข้ามอุปกรณ์)
- สร้าง **Transfer Code** สำหรับส่งไฟล์ระหว่างอุปกรณ์
- Code หมดอายุใน 10 นาที พร้อมจำกัดจำนวนครั้งดาวน์โหลด
- รับไฟล์ผ่าน Code โดยไม่ต้องล็อกอิน (`/api/transfer/receive`)
- **QR Code** สำหรับรับไฟล์ด้วยมือถือ

### 19. Share Links (แบ่งปันข้อมูล)
- สร้าง Public Link สำหรับแบ่งปันไฟล์/บันทึกแก่บุคคลภายนอก
- กำหนดวันหมดอายุและจำนวนครั้งดาวน์โหลดสูงสุด
- **App Shares** — แบ่งปัน Module ทั้งหมด (เช่น Notes, Finance) ผ่าน Token (`/shared/{token}`) โดยไม่ต้องล็อกอิน และคงสถานะได้แม้ถูกบันทึกเป็น Shortcut บนมือถือ
- **Demo Account** — ปุ่ม "Demo" ในหน้าล็อกอิน พาผู้เยี่ยมชมเข้าดูตัวอย่างระบบแบบอ่านอย่างเดียวผ่านกลไก App Shares โดยไม่ต้องสมัครสมาชิก (`/demo`)

### 20. Search (ค้นหาทั่วระบบ)
- ค้นหาแบบ Global ครอบคลุมโน้ต, งาน, ไฟล์

### 21. Calculator (เครื่องคิดเลข)
- เครื่องคิดเลขขั้นสูงพร้อมประวัติการคำนวณ

### 22. Telegram Notifications & Cron Jobs
- เชื่อมต่อ **Telegram Bot API** ต่อ User (Bot Token เข้ารหัสก่อนบันทึก)
- ส่งแจ้งเตือน Planner Events, Task Due, Subscription ที่ใกล้ครบกำหนด
- กำหนดประเภทแจ้งเตือนที่ต้องการต่อ User
- Cron Script (`cron.php`) รันผ่าน CLI เท่านั้น (CLI-only, ปิด Web Access)
- รองรับ Timezone ต่อ User

### 23. Settings (การตั้งค่า)
- **Profile** — เปลี่ยนชื่อ, อีเมล, รหัสผ่าน
- **Theme** — เลือกธีม Neumorphism 6 แบบ (Light, Dark, Pastel Soft, Lavender, Mint/Ocean, Rose/Peach)
- **Timezone** — กำหนด Timezone ส่วนตัว
- **Menu Order** — จัดลำดับเมนู Sidebar ตามต้องการ
- **Telegram** — กำหนดค่า Bot Token, Chat ID และทดสอบการเชื่อมต่อ
- **AI Keys** — จัดการ API Key ต่อ AI Provider
- **Devices** — ดูและยกเลิก Remember Token ของอุปกรณ์ที่ล็อกอินไว้
- **Export/Import** — สำรองและนำเข้าข้อมูลส่วนตัว
- **Delete Account** — ลบบัญชีพร้อมข้อมูลทั้งหมด
