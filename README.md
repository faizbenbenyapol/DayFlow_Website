# DayFlow — Productivity & Life Management Platform

ระบบบริหารจัดการประสิทธิภาพการทำงานแบบครบวงจร (All-in-One Productivity Platform) พัฒนาด้วย **Custom PHP MVC Framework** ออกแบบภายใต้แนวคิด **Neumorphism & Soft UI** รองรับการแสดงผลทุกขนาดหน้าจออย่างสมบูรณ์

**เวอร์ชัน: v1.2.0** &nbsp;|&nbsp; **PHP 8.2** &nbsp;|&nbsp; **MariaDB 11.4**

> [!WARNING]
> สถานะการพัฒนา: ระบบนี้กำลังอยู่ในช่วงการพัฒนาอย่างต่อเนื่อง (Active Development) ฟังก์ชันการทำงานบางส่วนอาจมีการเปลี่ยนแปลงหรือปรับปรุงเพื่อความเสถียรยิ่งขึ้น

🌐 เข้าใช้งานได้ที่: **https://dayflow.benyapol.online/login**

---

## ภาพรวมของระบบ (Project Overview)

DayFlow เป็นเว็บแอปพลิเคชันที่สร้างขึ้นด้วยสถาปัตยกรรมแบบ **Custom PHP MVC Framework** โดยไม่พึ่งพาเฟรมเวิร์กสำเร็จรูปใดๆ เพื่อความยืดหยุ่น ประสิทธิภาพ และความปลอดภัยสูงสุด ระบบ Front Controller รวมศูนย์อยู่ที่ `index.php` เดียว โดยมี Router ที่กำหนด Route ทุกเส้นทางผ่าน Pattern Matching แบบ `{id}` พร้อมระบบ Middleware ตรวจสอบสิทธิ์ก่อนเข้าถึงทุก API

### คุณลักษณะเด่นของสถาปัตยกรรม

- **Single Entry Point** — `index.php` โหลด config, database, session, core modules และ dispatch ผ่าน `Router`
- **PDO Singleton (DB class)** — ป้องกัน SQL Injection ผ่าน Prepared Statements ทุก Query
- **AES-256-CBC Encryption** — เข้ารหัสข้อมูลสำคัญ (บันทึกส่วนตัว, Telegram Token) ด้วย HMAC ป้องกันการแก้ไข
- **CSRF Protection** — Token แนบทุก Form/API Request
- **Rate Limiter** — จำกัดอัตราการเรียก API เพื่อป้องกัน Brute Force
- **Remember Token** — ระบบ "จำฉันไว้" ด้วย Secure Token แบบ Rotate-on-use
- **Security Headers** — ตั้งค่า `X-Frame-Options`, `X-Content-Type-Options`, `Content-Security-Policy` ทุก Request
- **PWA-Ready** — มี `manifest.json` รองรับการติดตั้งบนมือถือ
- **Health Check Endpoint** — `/health` ส่งคืน JSON `{"ok":true}` สำหรับ Docker Healthcheck

---

## ฟีเจอร์ทั้งหมดของระบบ (Complete Feature Set)

### 🏠 1. Dashboard (แดชบอร์ดหลัก)
- ศูนย์รวมข้อมูลสรุปประจำวัน — งาน, โน้ต, การเงิน, สุขภาพ
- Widget แบบ **Modular** ที่สามารถเปิด/ปิดและจัดลำดับได้ตามต้องการ
- บันทึกตำแหน่ง Layout ต่อ User ผ่าน API (`/api/dashboard/layout`)
- Quick Summary Widget แสดงสถิติรายวันแบบ Real-time

### 📝 2. Notes (บันทึกอัจฉริยะ)
- Editor แบบ **Block-Based** รองรับ 3 ประเภทบล็อก: Text, Link, Checklist
- ปักหมุด (Pin) บันทึกสำคัญและจัดหมวดหมู่ผ่าน **Tags**
- **Note Encryption** — เข้ารหัสบันทึกส่วนตัวด้วย AES-256-CBC ต้องใส่รหัสผ่านเพื่อถอดรหัส
- Reorder Blocks ด้วย Drag-and-Drop
- แบ่งปันบันทึกผ่าน Public Link พร้อมกำหนดวันหมดอายุ

### 📅 3. Planner (ปฏิทินและ To-Do รายวัน)
- ปฏิทินรายเดือน รองรับ Event สร้าง/แก้ไข/ลบ
- **Daily To-Do List** — งานรายวันพร้อมระบบ Reorder
- ส่งแจ้งเตือน Planner ผ่าน Telegram อัตโนมัติ

### 📋 4. Projects & Tasks (โครงการและงาน)
- **Kanban Board** — คอลัมน์ Todo / In Progress / Done พร้อม Drag-and-Drop
- บริหารสมาชิกโครงการ (Project Members) และกำหนดสิทธิ์
- **Project Chat** — ห้องสนทนาภายในโครงการ
- **Activity Log** — ติดตามประวัติการเปลี่ยนแปลงในโครงการ
- แชร์โครงการผ่าน Public Token (`/project/shared/{token}`)
- กำหนด Priority, Due Date, Status ต่อ Task
- **Personal Tasks** — งานส่วนตัวแยกจากโครงการ พร้อม Reorder

### ⏱️ 5. Pomodoro Focus Timer (จับเวลาโฟกัส)
- Timer แบบ Pomodoro ตั้งเวลาทำงานและพัก
- บันทึก Session Log ทุกรอบ
- แสดงสถิติการโฟกัสสะสม

### 🏋️ 6. Exercise & Workout (บันทึกการออกกำลังกาย)
- บันทึก Workout รายวันพร้อมระบุประเภทท่าออกกำลังกาย (Sets/Reps/Weight)
- จัดการ **Exercise Categories** แบบ Custom
- แสดงสถิติรายสัปดาห์ (`/api/exercise/stats`)

### 🥗 7. Food Notes (บันทึกโภชนาการ)
- บันทึกรายการอาหารและแคลอรีประจำวัน
- แสดงสรุปพลังงานที่ได้รับในแต่ละมื้อ

### 💰 8. Finance & Budget (การเงินและงบประมาณ)
- บันทึกรายรับ-รายจ่ายส่วนบุคคลแบบ Real-time
- **Custom Categories** สำหรับรายรับและรายจ่าย
- สรุปงบประมาณรายเดือนและกราฟวิเคราะห์ทางการเงิน (`/api/finance/chart`)

### 📆 9. Subscriptions (ติดตามการสมัครสมาชิก)
- บันทึก Subscription และค่าใช้จ่ายซ้ำรายคาบ (Weekly / Monthly / Yearly / One-time)
- ระบบ **Renew** บันทึกรอบถัดไปอัตโนมัติ
- แจ้งเตือนผ่าน Telegram เมื่อใกล้ครบกำหนด

### 📈 10. Stocks Portfolio (พอร์ตการลงทุน)
- บันทึก Transaction ซื้อ-ขายสินทรัพย์ (หุ้น, กองทุน, คริปโต)
- ดึงราคาตลาดผ่าน **Stock API Key** (รองรับหลาย Provider)
- Price Cache 5 นาที เพื่อลดการเรียก API
- **Watchlist** — ติดตามราคาสินทรัพย์ที่สนใจ
- **Capital Tracking** — บันทึกเงินทุนที่ใส่เข้าพอร์ต
- **Screenshots** — อัปโหลดภาพหน้าจอพอร์ตการลงทุน
- **AI Analysis** — วิเคราะห์พอร์ตด้วย AI (`/api/stocks/analyze`)
- สรุป P&L, กราฟมูลค่าพอร์ต (`/api/stocks/chart`)

### 🤖 11. AI Assistant (ผู้ช่วย AI)
- รองรับหลาย AI Provider (บันทึก API Key ต่อ Provider ต่อ User)
- ทดสอบ Key ก่อนบันทึก (`/api/ai/keys/test`)
- **Script Generation** — สร้างเนื้อหาหรือสรุปข้อความ
- **Section Regeneration** — สร้างเนื้อหาส่วนย่อยใหม่
- **Video Generation** — สร้างวิดีโอ พร้อมตรวจสอบสถานะ Async (`/api/ai/video/{id}/status`)
- ประวัติการสร้างเนื้อหา (Generation History)

### 🎯 12. Skills Time Tracker (ติดตามเวลาพัฒนาทักษะ)
- สร้าง Skill พร้อมกำหนด Target Hours (เป้าหมายชั่วโมง)
- **Live Timer** — เริ่ม/หยุดจับเวลาพร้อมบันทึก Log อัตโนมัติ
- แสดงสถิติความคืบหน้าต่อ Skill
- ดูประวัติ Session Log และลบรายการได้

### 🔄 13. Daily Habits (นิสัยประจำวัน)
- สร้างนิสัยที่อยากทำประจำวัน พร้อมกำหนด Target Days ต่อสัปดาห์ (1–7 วัน)
- Toggle เสร็จ/ยังไม่เสร็จในแต่ละวัน
- แสดงสถิติ Streak และ Completion Rate

### ⚡ 14. Quick Notes (จดด่วน)
- จดข้อความสั้น (สูงสุด 500 ตัวอักษร) แบบ Capture รวดเร็ว
- Toggle สถานะเสร็จ/ยังไม่เสร็จ

### 🔖 15. Bookmarks (ลิงก์สำคัญ)
- บันทึก URL พร้อมชื่อและหมวดหมู่
- รองรับเฉพาะ `http://` และ `https://` เพื่อความปลอดภัย

### 📁 16. File Manager (จัดการไฟล์)
- อัปโหลดและจัดการไฟล์ส่วนตัวในโครงสร้างโฟลเดอร์
- สร้างโฟลเดอร์, เปลี่ยนชื่อ, ย้าย, ลบไฟล์
- ดาวน์โหลดไฟล์ผ่าน Secure Download Endpoint

### 🛠️ 17. File Tools (เครื่องมือไฟล์)
- **Image Tool** — ปรับขนาด, แปลงฟอร์แมต, Compress รูปภาพ (PNG, JPEG, WEBP)
- **ZIP Creator** — สร้างไฟล์ ZIP จากหลายไฟล์
- **ZIP Inspector** — ดูรายการไฟล์ภายใน ZIP
- **ZIP Extractor** — แตกไฟล์จาก ZIP

### 📤 18. File Transfer (ส่งไฟล์ข้ามอุปกรณ์)
- สร้าง **Transfer Code** สำหรับส่งไฟล์ระหว่างอุปกรณ์
- Code หมดอายุใน 10 นาที พร้อมจำกัดจำนวนครั้งดาวน์โหลด
- รับไฟล์ผ่าน Code โดยไม่ต้องล็อกอิน (`/api/transfer/receive`)
- **QR Code** สำหรับรับไฟล์ด้วยมือถือ

### 🔗 19. Share Links (แบ่งปันข้อมูล)
- สร้าง Public Link สำหรับแบ่งปันไฟล์/บันทึกแก่บุคคลภายนอก
- กำหนดวันหมดอายุและจำนวนครั้งดาวน์โหลดสูงสุด
- **App Shares** — แบ่งปัน Module ทั้งหมด (เช่น Notes, Finance) ผ่าน Token (`/shared/{token}`)

### 🔍 20. Search (ค้นหาทั่วระบบ)
- ค้นหาแบบ Global ครอบคลุมโน้ต, งาน, ไฟล์

### 🖩 21. Calculator (เครื่องคิดเลข)
- เครื่องคิดเลขขั้นสูงพร้อมประวัติการคำนวณ

### 🔔 22. Telegram Notifications & Cron Jobs
- เชื่อมต่อ **Telegram Bot API** ต่อ User (Bot Token เข้ารหัสก่อนบันทึก)
- ส่งแจ้งเตือน Planner Events, Task Due, Subscription ที่ใกล้ครบกำหนด
- กำหนดประเภทแจ้งเตือนที่ต้องการต่อ User
- Cron Script (`cron.php`) รันผ่าน CLI เท่านั้น (CLI-only, ปิด Web Access)
- รองรับ Timezone ต่อ User

### ⚙️ 23. Settings (การตั้งค่า)
- **Profile** — เปลี่ยนชื่อ, อีเมล, รหัสผ่าน
- **Theme** — เลือกธีม Neumorphism 4 สีพาสเทล (Pastel Soft, Lavender, Mint/Ocean, Rose/Peach)
- **Timezone** — กำหนด Timezone ส่วนตัว
- **Menu Order** — จัดลำดับเมนู Sidebar ตามต้องการ
- **Telegram** — กำหนดค่า Bot Token, Chat ID และทดสอบการเชื่อมต่อ
- **AI Keys** — จัดการ API Key ต่อ AI Provider
- **Devices** — ดูและยกเลิก Remember Token ของอุปกรณ์ที่ล็อกอินไว้
- **Export/Import** — สำรองและนำเข้าข้อมูลส่วนตัว
- **Delete Account** — ลบบัญชีพร้อมข้อมูลทั้งหมด

---

## เทคโนโลยีที่ใช้ (Technology Stack)

### Backend
| ส่วนประกอบ | รายละเอียด |
|---|---|
| ภาษา | PHP 8.2 |
| Framework | Custom MVC (ไม่ใช้ Framework สำเร็จรูป) |
| Authentication | Session-based + Google OAuth 2.0 + Remember Token |
| Security | CSRF Token, Rate Limiter, AES-256-CBC + HMAC, Security Headers |
| Notifications | Telegram Bot API |
| Background Jobs | PHP CLI Cron Script (`cron.php`) |
| Encryption | `openssl_encrypt` AES-256-CBC + HMAC-SHA256 |

### Database
| ส่วนประกอบ | รายละเอียด |
|---|---|
| Engine | MySQL / MariaDB 11.4 |
| Driver | PDO Singleton Wrapper (`DB` class) |
| Migrations | SQL files ใน `sql/` (Auto-applied ผ่าน Docker) |

### Frontend
| ส่วนประกอบ | รายละเอียด |
|---|---|
| โครงสร้าง | HTML5 Semantic |
| สไตล์ | Vanilla CSS3 (ไม่ใช้ Tailwind หรือ CSS Framework) |
| ตรรกะ | Vanilla JavaScript (ไม่ใช้ React/Vue) |
| ดีไซน์ | Neumorphism & Soft UI, 4 Pastel Themes |
| Charts | Chart.js (โหลดเฉพาะหน้าที่ต้องการ) |
| QR Code | QRCode.js Library |
| PWA | `manifest.json` รองรับ Install บนมือถือ |

### DevOps & Deployment
| ส่วนประกอบ | รายละเอียด |
|---|---|
| Container | Docker + Docker Compose |
| Base Image | `php:8.2-apache` |
| Reverse Proxy | Caddy (HTTPS Auto) / Nginx |
| Web Server | Apache 2 พร้อม `mod_rewrite`, `mod_headers`, `mod_expires` |
| Tunnel | Cloudflare Tunnel (ดู `VPS_CLOUDFLARE_TUNNEL.md`) |

---

## สถาปัตยกรรมโฟลเดอร์ (Directory Structure)

```text
DayFlowV.2/
├── assets/
│   ├── css/
│   │   ├── app.css               # Global styles, Neumorphism tokens, Themes
│   │   ├── components.css        # Shared UI components
│   │   └── modules/              # CSS ต่อหน้า (ai, stocks, projects, ...)
│   └── js/
│       ├── app.js                # Global JS (sidebar, theme, search)
│       └── [module].js           # JS ต่อหน้า (22 ไฟล์)
├── config/
│   ├── config.php                # App constants, helpers, encryption functions
│   ├── database.php              # DB Singleton class
│   ├── session.php               # Session configuration
│   └── .app_key                  # Encryption key (ignored by Git)
├── controllers/                  # 26 Controllers (1 ต่อ Feature)
├── core/
│   ├── Auth.php                  # Session auth helpers
│   ├── Csrf.php                  # CSRF token generation & validation
│   ├── RateLimiter.php           # File-based rate limiting
│   ├── RememberToken.php         # Remember-me token management
│   ├── Request.php               # HTTP input helpers
│   ├── Response.php              # JSON/Redirect/View response helpers
│   ├── Router.php                # URL pattern matching & dispatch
│   ├── Security.php              # Security headers
│   └── TelegramService.php       # Telegram Bot API client
├── home/                         # หน้า Landing/Public
├── install/                      # Setup scripts (ควรปิดหลังติดตั้ง)
├── models/                       # 31 Models (PDO queries)
├── sql/
│   ├── schema.sql                # Database schema หลัก
│   └── migrate_*.sql             # 15 Migration files
├── storage/
│   ├── logs/                     # PHP error logs (production)
│   └── ratelimit/                # Rate limit state files
├── uploads/                      # ไฟล์ที่ผู้ใช้อัปโหลด (ignored by Git)
├── views/                        # 24 View directories (1 ต่อ Module)
├── backups/                      # Database/File backups (ignored by Git)
├── scripts/
│   ├── migrate.php               # Run migrations manually
│   └── smoke.php                 # Smoke test script
├── docker/
│   └── php.ini                   # PHP configuration for Docker
├── cron.php                      # Telegram Cron Job (CLI-only)
├── index.php                     # Front Controller
├── Dockerfile                    # Docker image definition
├── docker-compose.yml            # Development compose (port 8080)
├── docker-compose.prod.yml       # Production compose
├── docker-compose.tunnel.yml     # Cloudflare Tunnel compose
├── Caddyfile                     # Caddy reverse proxy config
├── .env.example                  # Template ค่า Environment Variables
├── manifest.json                 # PWA manifest
└── .htaccess                     # Apache rewrite rules & security headers
```

---

## ตารางแสดง Routes ทั้งหมด (Route Map)

| URL | Method | ฟีเจอร์ |
|---|---|---|
| `/` | GET | Dashboard |
| `/notes` | GET | Notes List |
| `/notes/{id}` | GET | Note Editor |
| `/planner` | GET | Planner (Calendar + Daily Todo) |
| `/tasks` | GET | Personal Tasks |
| `/projects` | GET | Kanban Projects |
| `/focus` | GET | Pomodoro Timer |
| `/habits` | GET | Daily Habits |
| `/skills` | GET | Skills Time Tracker |
| `/quick-notes` | GET | Quick Capture |
| `/bookmarks` | GET | Bookmarks |
| `/exercise` | GET | Exercise Log |
| `/food-notes` | GET | Food & Nutrition |
| `/finance` | GET | Finance Tracker |
| `/subscriptions` | GET | Subscription Manager |
| `/stocks` | GET | Stock Portfolio |
| `/ai` | GET | AI Assistant |
| `/files` | GET | File Manager |
| `/file-tools` | GET | Image & ZIP Tools |
| `/transfer` | GET | File Transfer |
| `/calculator` | GET | Calculator |
| `/settings` | GET | Settings |
| `/share/{token}` | GET | Public File Share |
| `/shared/{token}` | GET | Public App Share |
| `/project/shared/{token}` | GET | Public Project View |
| `/login` | GET | Login / Register |
| `/health` | GET | Health Check (JSON) |

---

## การติดตั้งด้วย Docker (แนะนำ)

### ความต้องการ
- Docker Desktop (Windows/Mac) หรือ Docker Engine (Linux)

### 1. Clone และเตรียมค่า Environment

```powershell
git clone <repo-url> DayFlowV.2
cd DayFlowV.2
copy .env.example .env
```

แก้ไข `.env` ตามต้องการ (หรือใช้ค่า default สำหรับ Development):

```env
APP_ENV=development
APP_URL=http://localhost:8080
DB_HOST=db
DB_NAME=dayflow
DB_USER=dayflow_app
DB_PASS=dayflow_dev_password
TIMEZONE=Asia/Bangkok
GOOGLE_CLIENT_ID=           # ใส่ถ้าต้องการ Google Sign-In
APP_KEY=                    # สร้างอัตโนมัติถ้าไม่ได้กำหนด (development เท่านั้น)
```

### 2. Build และเปิดระบบ

```powershell
docker compose up --build -d
```

ตรวจสอบสถานะ:
```powershell
docker compose ps
# ตรวจสุขภาพระบบ
curl http://localhost:8080/health
```

### 3. เปิดเบราว์เซอร์

```
http://localhost:8080
```

สมัครบัญชีใหม่ได้เลย ฐานข้อมูลและ Migrations ทั้งหมดถูกสร้างอัตโนมัติ

### 4. เปิด Cron Worker (สำหรับ Telegram Notifications)

```powershell
docker compose --profile worker up -d
```

### 5. สำรองข้อมูล

```powershell
# สำรองฐานข้อมูล
docker compose --profile backup run --rm backup

# สำรองไฟล์ uploads + storage
docker compose --profile backup run --rm backup-files
```

ไฟล์สำรองถูกบันทึกในโฟลเดอร์ `backups/`

---

## การติดตั้งบนเครื่องจำลอง XAMPP (Local)

### ความต้องการ
- PHP 8.0 ขึ้นไป (แนะนำ 8.2)
- MySQL 5.7+ หรือ MariaDB 10.4+
- Apache พร้อม `mod_rewrite` (XAMPP / Laragon)
- Extension: `pdo_mysql`, `gd`, `zip`, `openssl`

### ขั้นตอน

1. **วางโปรเจกต์** ใน Web Root เช่น `C:\xampp\htdocs\DayFlowV.2\`

2. **สร้างฐานข้อมูล** ชื่อ `dayflow` ใน phpMyAdmin แล้วนำเข้า:
   ```sql
   -- นำเข้า schema หลักก่อน
   sql/schema.sql
   -- จากนั้น migrate ทีละไฟล์ตามลำดับตัวเลข
   sql/migrate_*.sql
   ```

3. **สร้างไฟล์ `.env`** ในโฟลเดอร์โปรเจกต์:
   ```env
   APP_ENV=development
   APP_URL=http://localhost/DayFlowV.2
   DB_HOST=localhost
   DB_NAME=dayflow
   DB_USER=root
   DB_PASS=
   TIMEZONE=Asia/Bangkok
   ```

4. **เปิดเบราว์เซอร์** ไปที่ `http://localhost/DayFlowV.2`

---

## Environment Variables ทั้งหมด

| Variable | คำอธิบาย | ค่าตัวอย่าง |
|---|---|---|
| `APP_ENV` | Environment (development / production) | `production` |
| `APP_URL` | URL หลักของแอป (ไม่มี trailing slash) | `https://dayflow.example.com` |
| `DB_HOST` | Host ของฐานข้อมูล | `127.0.0.1` |
| `DB_NAME` | ชื่อฐานข้อมูล | `dayflow` |
| `DB_USER` | ชื่อผู้ใช้ฐานข้อมูล | `dayflow_app` |
| `DB_PASS` | รหัสผ่านฐานข้อมูล | `...` |
| `TIMEZONE` | Timezone ของระบบ | `Asia/Bangkok` |
| `MAX_UPLOAD_BYTES` | ขนาดไฟล์อัปโหลดสูงสุด (bytes) | `20971520` (20 MB) |
| `GOOGLE_CLIENT_ID` | Google OAuth 2.0 Client ID | `xxx.apps.googleusercontent.com` |
| `APP_KEY` | Hex key 64 ตัว สำหรับ AES-256 Encryption | `(generate ด้วย openssl)` |
| `CRON_TOKEN` | Token สำหรับ Cron Job authentication | `(random string)` |
| `DOMAIN` | Domain name (ใช้ใน Docker/Caddy) | `dayflow.example.com` |

> [!IMPORTANT]
> บน Production ต้องกำหนด `APP_KEY` เป็น hex string 64 ตัวอักษรที่สร้างจาก `openssl rand -hex 32` และเก็บไว้ใน `.env` หรือ Environment Variable ของ Server เสมอ ห้ามใช้ค่า Default

---

## Database Migrations

ฐานข้อมูลประกอบด้วยไฟล์ SQL ดังนี้:

| ไฟล์ | ตาราง |
|---|---|
| `schema.sql` | ตารางหลักทั้งหมด (users, notes, projects, tasks, finance, ...) |
| `migrate_ai.sql` | AI Keys & Generation History |
| `migrate_app_shares.sql` | App Share Tokens |
| `migrate_file_transfer.sql` | File Transfer (ส่งไฟล์ข้ามอุปกรณ์) |
| `migrate_focus.sql` | Pomodoro Focus Sessions |
| `migrate_habits.sql` | Daily Habits & Logs |
| `migrate_menu_order.sql` | Menu Order per User |
| `migrate_project_collab.sql` | Project Members & Chat |
| `migrate_project_share.sql` | Project Public Share |
| `migrate_projects.sql` | Extended Project Fields |
| `migrate_quick_capture.sql` | Quick Notes |
| `migrate_remember_tokens.sql` | Remember-me Tokens |
| `migrate_shares.sql` | File Share Links |
| `migrate_skills.sql` | Skills & Time Logs |
| `migrate_stock_watchlists.sql` | Stock Watchlists |
| `migrate_telegram_cron.sql` | Telegram Cron Logs |

---

## ความปลอดภัย (Security Checklist)

> [!CAUTION]
> ตรวจสอบรายการนี้ก่อน Deploy ขึ้น Production ทุกครั้ง

- [ ] ตั้ง `APP_ENV=production` ใน `.env`
- [ ] กำหนด `APP_KEY` เป็น hex 64 ตัวจาก `openssl rand -hex 32`
- [ ] เปลี่ยนรหัสผ่านฐานข้อมูลทั้งหมดจากค่า Default ใน `docker-compose.yml`
- [ ] ใช้ HTTPS เท่านั้น (ผ่าน Caddy หรือ Nginx Reverse Proxy)
- [ ] ตั้งสิทธิ์ไฟล์ `.env` และ `config/.app_key` เป็น `0600`
- [ ] ปิดโฟลเดอร์ `install/` (`.htaccess` ปิดไว้แล้ว)
- [ ] รัน Cron Job ผ่าน CLI เท่านั้น (Web access ถูกปิดใน `cron.php`)
- [ ] สำรองฐานข้อมูลและ `uploads/` เป็นประจำ
- [ ] ไม่ Commit `.env`, `config/.app_key`, `uploads/`, `backups/` ลง Git (ถูก `.gitignore` แล้ว)

---

## เอกสารเพิ่มเติม

| ไฟล์ | เนื้อหา |
|---|---|
| [CHANGELOG.md](CHANGELOG.md) | ประวัติการเปลี่ยนแปลงทุก Version |
| [VPS_DEPLOYMENT.md](VPS_DEPLOYMENT.md) | คู่มือ Deploy บน VPS ด้วย Docker |
| [VPS_CLOUDFLARE_TUNNEL.md](VPS_CLOUDFLARE_TUNNEL.md) | ใช้ Cloudflare Tunnel แทน Public IP |
| [VPS_PRODUCTION.md](VPS_PRODUCTION.md) | Checklist สำหรับ Production |

---

## License

โปรเจกต์นี้พัฒนาขึ้นเพื่อการใช้งานส่วนตัว ติดต่อผู้พัฒนาสำหรับข้อมูลเพิ่มเติม
