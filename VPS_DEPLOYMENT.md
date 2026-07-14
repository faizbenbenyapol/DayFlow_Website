# DayFlow บน VPS แบบส่วนตัว

## ทดลองบนเครื่องด้วย Docker (แนะนำ)

ติดตั้ง Docker Desktop ก่อน จากนั้นเปิด PowerShell ในโฟลเดอร์โปรเจคแล้วรัน:

```powershell
docker compose up --build -d
docker compose ps
```

ตรวจสถานะระบบได้ที่ `http://localhost:8080/health` โดยควรได้ JSON ที่มี `"ok":true`

เปิด `http://localhost:8080` แล้วสมัครบัญชีผ่านหน้าเว็บได้เลย ฐานข้อมูลหลักและ migrations ของทุกโมดูลจะถูกสร้างอัตโนมัติ

หยุดระบบโดยเก็บข้อมูลไว้:

```powershell
docker compose down
```

ถ้าต้องการล้างฐานข้อมูลทดสอบทั้งหมด:

```powershell
docker compose down -v
```

รหัสผ่านใน `docker-compose.yml` ใช้สำหรับทดสอบเท่านั้น ต้องเปลี่ยนใหม่ทั้งหมดก่อนนำไปใช้บน VPS จริง

ถ้าต้องการเปิด worker สำหรับ Telegram/Cron ในเครื่องทดสอบ:

```powershell
docker compose --profile worker up -d
```

รัน migration และ smoke test หลังอัปเดตโค้ด:

```powershell
docker compose exec app php scripts/migrate.php
docker compose exec app php scripts/smoke.php
```

สำรองฐานข้อมูล:

```powershell
docker compose --profile backup run --rm backup
```

สำรองไฟล์ uploads และ storage เพิ่มเติม:

```powershell
docker compose --profile backup run --rm backup-files
```

ไฟล์สำรองจะอยู่ในโฟลเดอร์ `backups/` และถูกกันออกจาก Git แล้ว

## ค่าที่ควรตั้งก่อนเปิดใช้งาน

1. คัดลอก `.env.example` เป็น `.env` แล้วเปลี่ยน `APP_URL`, ชื่อฐานข้อมูล, ผู้ใช้ฐานข้อมูล และรหัสผ่านให้เป็นค่าจริง
2. ตั้ง `APP_ENV=production` และใช้ HTTPS ผ่าน reverse proxy เช่น Caddy หรือ Nginx
3. ให้ผู้ใช้ฐานข้อมูลมีสิทธิ์เฉพาะฐานข้อมูล DayFlow ไม่ใช้ `root`
4. ตั้งสิทธิ์ให้ `config/.app_key` และ `.env` อ่านได้เฉพาะ user ที่รัน PHP
5. ลบหรือปิดโฟลเดอร์ `install/` หลังติดตั้งเสร็จ (โปรเจคปิด route นี้ไว้แล้วใน `.htaccess`)
6. ตั้ง cron ให้เรียก `cron.php` ด้วย PHP CLI ตามรอบที่ต้องการ และสำรองฐานข้อมูลกับ `uploads/` เป็นประจำ

## โครงสร้างที่แนะนำ

- Apache/Nginx ชี้ document root มาที่โฟลเดอร์โปรเจคนี้
- MySQL/MariaDB รับการเชื่อมต่อเฉพาะ `127.0.0.1`
- เปิดพอร์ตจากอินเทอร์เน็ตเฉพาะ 80/443 และ SSH
- ใช้ fail2ban หรือระบบจำกัดการลองรหัสผ่านสำหรับ SSH

ไฟล์ `.env` และข้อมูลใน `uploads/` ถูกกันออกจาก Git อยู่แล้ว ห้าม commit ค่ารหัสผ่านจริงลง repository
