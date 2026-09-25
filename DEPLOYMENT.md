# การติดตั้งและ Deploy DayFlow

คู่มือเดียวสำหรับทุกสภาพแวดล้อม: ทดลองบนเครื่อง, เปิดใช้งานจริงบนเซิร์ฟเวอร์ (ผ่าน Caddy หรือ Cloudflare Tunnel), อัปเดต และสำรองข้อมูล

ทุกคำสั่งรันจากโฟลเดอร์โปรเจค และใช้ Docker Compose

---

## 1. ทดลองบนเครื่อง

ติดตั้ง Docker Desktop แล้วรัน:

```bash
docker compose up --build -d
docker compose ps
```

- ตรวจสถานะที่ `http://localhost:8080/health` ควรได้ JSON ที่มี `"ok":true`
- เปิด `http://localhost:8080` แล้วสมัครบัญชีผ่านหน้าเว็บ ฐานข้อมูลและ migrations ถูกสร้างอัตโนมัติ
- รหัสผ่านใน `docker-compose.yml` ใช้ทดสอบเท่านั้น

| ต้องการ | คำสั่ง |
|---|---|
| เปิด worker แจ้งเตือน (Telegram / Web Push) | `docker compose --profile worker up -d` |
| หยุดระบบ (เก็บข้อมูลไว้) | `docker compose down` |
| ล้างข้อมูลทดสอบทั้งหมด | `docker compose down -v` |
| รันชุดทดสอบ | `docker compose exec app php tests/run.php http://localhost` |

---

## 2. ตั้งค่าก่อนเปิดใช้งานจริง

```bash
cp .env.example .env
chmod 600 .env
```

แก้ค่าใน `.env`:

| ค่า | หมายเหตุ |
|---|---|
| `APP_URL` | เช่น `https://dayflow.example.com` |
| `DOMAIN` | ชื่อโดเมนเดียวกัน (ใช้กับ Caddy) |
| `APP_KEY` | สร้างด้วย `openssl rand -hex 32` — **ห้ามเปลี่ยนหลังเปิดใช้** ข้อมูลที่เข้ารหัสไว้ (API key, 2FA) จะอ่านไม่ได้ |
| `CRON_TOKEN` | สร้างด้วย `openssl rand -base64 48` |
| `MARIADB_*` | รหัสผ่านยาวและสุ่ม ผู้ใช้ฐานข้อมูลต้องไม่ใช่ `root` |
| `GOOGLE_CLIENT_ID` | ถ้าเปิด Google Sign-In |
| `VAPID_*` | ถ้าเปิดการแจ้งเตือนบนเบราว์เซอร์ สร้างด้วย `php scripts/generate-vapid-keys.php` |

ข้อควรจำ:

- `.env` ถูกกันออกจาก Git อยู่แล้ว ห้าม commit ค่าจริง
- ห้ามเปิดพอร์ต MariaDB หรือพอร์ต 8080 ออกอินเทอร์เน็ต
- เปิดพอร์ตจากภายนอกเฉพาะที่จำเป็น (80/443 เมื่อใช้ Caddy, หรือไม่ต้องเปิดเลยเมื่อใช้ Cloudflare Tunnel) และ SSH

---

## 3. เปิดใช้งานจริง

เลือกแบบใดแบบหนึ่ง ทั้งสองแบบใช้ `docker-compose.prod.yml` ซึ่งบังคับให้ตั้งค่าใน `.env` ครบก่อนเริ่ม

แอปรู้ IP จริงของผู้ใช้ผ่าน `X-Forwarded-For` จาก proxy ของเราเอง (ดู `docker/remoteip.conf`) ซึ่งจำเป็นต่อ rate limit ของการล็อกอิน สมัครสมาชิก และรหัส File Transfer

### แบบ A: Caddy (เปิดพอร์ต 80/443 เอง)

ชี้โดเมนมายัง IP ของเครื่อง เปิดพอร์ต 80/443 ที่ router และ firewall แล้วรัน:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile prod --profile prod-worker up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php scripts/migrate.php
```

Caddy ขอและต่ออายุใบรับรอง HTTPS ให้อัตโนมัติ

### แบบ B: Cloudflare Tunnel (ไม่ต้องเปิดพอร์ต)

แอปผูกพอร์ต 8080 ไว้กับ `127.0.0.1` เท่านั้น แล้วให้ `cloudflared` บนเครื่องเชื่อมออกไปหา Cloudflare

```bash
COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tunnel.yml"
$COMPOSE --profile prod-worker up -d db app cron
$COMPOSE exec -T app php scripts/migrate.php
curl -fsS http://127.0.0.1:8080/health
```

ใน Cloudflare Zero Trust สร้าง Public Hostname:

```text
Hostname: dayflow.example.com
Service:  http://127.0.0.1:8080
```

ติดตั้ง `cloudflared` เป็น service ด้วย token จาก Cloudflare:

```bash
sudo cloudflared service install <YOUR_TUNNEL_TOKEN>
sudo systemctl enable --now cloudflared
```

### แบบ C: Apache/Nginx โดยไม่ใช้ Docker (รวม XAMPP)

- ตั้ง document root ไปที่โฟลเดอร์ **`public/`** เท่านั้น — โค้ด config uploads และ log อยู่นอกโฟลเดอร์นี้และเข้าถึงผ่าน URL ไม่ได้
- ถ้าเปลี่ยน document root ไม่ได้ (เช่น XAMPP ที่ `http://localhost/DayFlow` หรือ shared hosting) ไฟล์ `.htaccess` ที่ root ของโปรเจคจะส่งทุก request เข้า `public/` ให้เอง ต้องเปิด `mod_rewrite` และ `AllowOverride All`
- Nginx: ใช้ `root /path/to/DayFlow/public;` และ `try_files $uri /index.php?$query_string;`
- รัน `php scripts/migrate.php` หลังติดตั้งและหลังอัปเดตทุกครั้ง แอปไม่สร้างตารางเองระหว่างใช้งาน
- ตั้ง cron ให้รัน `php cron.php` ทุก 5 นาที

---

## 4. อัปเดตหลัง push ขึ้น GitHub

```bash
cd /opt/dayflow
git pull --ff-only origin main
$COMPOSE build app cron        # ต้อง build ใหม่ทุกครั้ง: config ของ Apache อยู่ใน image
$COMPOSE --profile prod-worker up -d db app cron
$COMPOSE exec -T app php scripts/migrate.php
curl -fsS http://127.0.0.1:8080/health
```

(แบบ A ใช้ `docker compose -f docker-compose.yml -f docker-compose.prod.yml` แทน `$COMPOSE`)

`scripts/migrate.php --status` แสดงว่า migration ไหนรันแล้วบ้าง

---

## 5. สำรองข้อมูล

```bash
docker compose --profile backup run --rm backup         # ฐานข้อมูล
docker compose --profile backup run --rm backup-files   # uploads/ และ storage/
```

ไฟล์สำรองอยู่ใน `backups/` (ถูกกันออกจาก Git) ควรคัดลอกออกไปเก็บนอกเครื่องเป็นประจำ

ผู้ใช้แต่ละคนยังสำรองข้อมูลของตัวเองได้จากหน้า **ตั้งค่า → ข้อมูล** (ไม่รวมไฟล์ โปรเจค และ credential)

---

## 6. ตรวจสอบระบบ

```bash
$COMPOSE ps
$COMPOSE logs --tail=100 app
$COMPOSE logs --tail=100 cron
$COMPOSE exec -T app php scripts/smoke.php
sudo journalctl -u cloudflared -n 100 --no-pager   # แบบ B
```
