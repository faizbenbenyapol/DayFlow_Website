# DayFlow

PHP 8 MVC (ไม่มี Composer) + MariaDB 11.4 ทดสอบบน Docker `dayflow-app` / `dayflow-db` ที่ http://localhost:8080 (ใช้ทดสอบเท่านั้น)

## คำสั่งตรวจ

ใน Git Bash ใส่ `export MSYS_NO_PATHCONV=1` ก่อน ไม่งั้น path ใน `docker exec` จะถูกแปลงเป็น path ของ Windows

- test ทั้งหมด (~2.5 นาที): `docker exec dayflow-app php /var/www/html/tests/run.php http://localhost`
- e2e บนเบราว์เซอร์: `node scripts/e2e-shell.mjs --base http://localhost:8080`
- PHPStan: `docker run --rm -v "$(pwd -W)":/app -w /app ghcr.io/phpstan/phpstan:2.2.16 analyse --no-progress`
- CSS: `node scripts/check-css.mjs`

## กฎที่เคยพลาดซ้ำ

- `UPDATE` ตารางที่มี `updated_at ... ON UPDATE CURRENT_TIMESTAMP` ถ้าไม่ใช่การแก้ของผู้ใช้ (backfill, ระบบจำค่า) ต้องใส่ `updated_at = updated_at` ไม่งั้นเวลาแก้ไขล่าสุดของผู้ใช้จะเปลี่ยนเอง
- เทียบข้อความภาษาไทยใน SQL ใช้ `COLLATE utf8mb4_bin` เพราะ `utf8mb4_unicode_ci` มองข้ามวรรณยุกต์ (สมชาย = สมช้าย) ชื่อที่ผู้ใช้พิมพ์เองให้แปลง NBSP เป็นช่องว่างก่อน `TRIM` (ดู `nameKeySql()` ใน `scripts/migrate-daily-loop.php`)
- migration ทุกไฟล์ต้องรันซ้ำได้ (`php scripts/migrate.php --force` รันใหม่ทุกไฟล์) backfill ต้องอยู่ใต้เงื่อนไขเดียวกับการเพิ่มคอลัมน์ ไม่งั้นรันรอบสองจะเขียนทับข้อมูลจริง
- สคริปต์ CLI ใน `scripts/` ไม่มี autoloader model ที่ถูกเรียกจากทางนั้น (เช่นผ่าน `AccountData`) ต้อง `require_once` เอง
- "วันนี้" ฝั่ง JS ใช้ `serverToday()` ใน `public/assets/js/app.js` ห้ามใช้ `new Date()` หรือ `toISOString()` เพราะเครื่องผู้ใช้กับเซิร์ฟเวอร์อาจอยู่คนละวัน
- API ที่รับ id ต้องตอบเหมือนกันทั้ง id ของคนอื่นและ id ที่ถูกลบ ไม่บอกว่า id ไหนมีอยู่
