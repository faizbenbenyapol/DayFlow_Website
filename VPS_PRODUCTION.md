# DayFlow production บน Mini PC

## เปิด HTTPS ด้วย Caddy

เมื่อมี Domain หรือ DDNS ที่ชี้มายัง IP ของ Mini PC แล้ว ให้กำหนด `DOMAIN` เป็นชื่อจริง เช่น `dayflow.example.com` และเปิดพอร์ต 80/443 ที่ router กับ firewall จากนั้นรัน:

```powershell
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile prod up -d
```

Caddy จะขอและต่ออายุใบรับรอง HTTPS ให้อัตโนมัติ ส่วนแอปจะไม่เปิดพอร์ต 8080 ออกสู่ host ใน production profile

ก่อนใช้งานจริง:

1. เปลี่ยนรหัสผ่านฐานข้อมูลทั้งหมดจากค่าทดสอบ
2. ตั้ง `APP_ENV=production`
3. ใส่ค่า `APP_URL=https://ชื่อโดเมนจริง` ใน `.env`
4. สำรอง `backups/`, `uploads/`, `storage/` และ Docker volumes
5. ห้ามเปิดพอร์ต MariaDB ออกอินเทอร์เน็ต

ทดสอบก่อนเปิด HTTPS ได้ด้วยโหมดปกติที่ `http://localhost:8080`
