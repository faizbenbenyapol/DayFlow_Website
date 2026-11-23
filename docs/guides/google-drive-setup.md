# คู่มือ: เปิดใช้ Google Drive ใน DayFlow (สำหรับเจ้าของระบบ)

ทำครั้งเดียวต่อหนึ่งเว็บ หลังจากนั้นผู้ใช้แต่ละคนกด "เชื่อมบัญชี Google" เองในหน้าตั้งค่า > Google Drive
ใช้เวลาราว 15 นาที ไม่มีค่าใช้จ่าย

ป้ายกำกับ: *อนุมาน* = เขียนจากเอกสารของ Google และความรู้ทั่วไป ยังไม่ได้ทำตามจริงใน session ที่เขียนคู่มือ (ยังไม่มีบัญชีทดสอบ) ทำตามแล้วเจอหน้าจอไม่ตรง ให้จดไว้แล้วแจ้งเพื่อแก้คู่มือ

## 1. สิ่งที่ต้องมี

- บัญชี Google ที่จะเป็นเจ้าของโปรเจกต์ใน Google Cloud
- ที่อยู่เว็บจริงของ DayFlow เช่น `https://dayflow.example.com` (ค่า `APP_URL` ใน `.env` ของ production)
- สิทธิ์แก้ `.env` บนเครื่อง production และรัน `docker compose` ได้

## 2. สร้างโปรเจกต์และเปิด Google Drive API (อนุมาน)

1. เปิด https://console.cloud.google.com/ แล้วเลือกโปรเจกต์ที่ใช้กับปุ่มเข้าสู่ระบบด้วย Google อยู่แล้ว (ถ้ามี) หรือกด "New Project" ตั้งชื่อเช่น `DayFlow`
2. เมนู "APIs & Services" > "Library" ค้นหา `Google Drive API` แล้วกด "Enable"

## 3. ตั้งหน้าขออนุญาต (OAuth consent screen) (อนุมาน)

1. "APIs & Services" > "OAuth consent screen" (หน้าใหม่อาจชื่อ "Google Auth Platform" > "Branding")
2. ประเภทผู้ใช้: **External** (ผู้ใช้ทั่วไปที่สมัคร DayFlow)
3. ใส่ชื่อแอป `DayFlow`, อีเมลติดต่อ, และโดเมนของเว็บ
4. ส่วน Scopes / Data access: กด "Add or remove scopes" แล้วเลือก **`.../auth/drive.file`** อย่างเดียว
   - ห้ามเลือก `.../auth/drive` (เห็นทั้ง Drive) เพราะเป็นสิทธิ์ระดับจำกัดที่ต้องผ่านการตรวจของ Google (ดู Q-1 ในไฟล์รีไควร์เมนต์)
5. ระหว่างทดสอบ สถานะเป็น "Testing": เพิ่มอีเมลของคนที่จะลองใช้ใน "Test users" (คนอื่นจะเชื่อมไม่ได้)
6. พร้อมให้ทุกคนใช้: กด "Publish app" ให้เป็น "In production"
   - ข้อสำคัญ: ตอนเป็น "Testing" Google ให้ refresh token อยู่ได้ราว 7 วัน ผู้ใช้จะเห็น "การเชื่อม Google Drive หลุด" ทุกสัปดาห์ ต้อง publish ก่อนเปิดให้ใช้จริง
   - `drive.file` ไม่ใช่สิทธิ์ระดับจำกัด จึงไม่ต้องผ่านการประเมินความปลอดภัย แต่ Google อาจขอยืนยันชื่อและโดเมน (brand verification)

## 4. สร้าง OAuth client (อนุมาน)

1. "APIs & Services" > "Credentials" > "Create credentials" > "OAuth client ID"
   - ถ้าใช้ client ของปุ่มเข้าสู่ระบบอยู่แล้ว (ค่า `GOOGLE_CLIENT_ID`) จะใช้ตัวเดิมก็ได้ แค่เพิ่ม redirect URI ในข้อ 3 แต่แยก client ใหม่ดูแลง่ายกว่า
2. Application type: **Web application** ตั้งชื่อเช่น `DayFlow Drive`
3. Authorized redirect URIs กด "Add URI" ใส่ให้ตรงทุกตัวอักษร:
   `https://<โดเมนของคุณ>/settings/drive/callback`
   (ต้องเป็น `APP_URL` + `/settings/drive/callback` ไม่มีเครื่องหมาย / ท้าย)
4. กด Create แล้วคัดลอก **Client ID** และ **Client secret**
   - Client secret คือความลับ ห้ามส่งในแชท อีเมล หรือ commit ลง git

## 5. ใส่ค่าใน production

1. เปิด `.env` บนเครื่อง production เพิ่ม (หรือแก้) สองบรรทัด:

   ```
   GOOGLE_DRIVE_CLIENT_ID=<Client ID ที่คัดลอกมา>
   GOOGLE_DRIVE_CLIENT_SECRET=<Client secret ที่คัดลอกมา>
   ```

2. รัน migration และเริ่มคอนเทนเนอร์ใหม่ให้รับค่า (แบบ A ใน DEPLOYMENT.md ถ้าใช้แบบ B ให้เปลี่ยน -f ตามนั้น):

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php scripts/migrate.php
   docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile prod --profile prod-worker up -d
   ```

   (*ยังไม่ได้รัน* บน production) ต้องมี worker (`prod-worker`) ด้วย ไม่งั้นการย้ายไฟล์เดิมจะหยุดเมื่อผู้ใช้ปิดหน้าตั้งค่า

## 6. ตรวจว่าใช้ได้

1. เข้าสู่ระบบ DayFlow ด้วยบัญชีที่อยู่ใน Test users (ถ้ายังไม่ publish)
2. ตั้งค่า > Google Drive > กด "เชื่อมบัญชี Google" > เลือกบัญชี > อนุญาต
3. ต้องกลับมาที่หน้าตั้งค่าพร้อม "เชื่อม Google Drive แล้ว" เห็นอีเมลและโฟลเดอร์ "DayFlow (ใน My Drive)"
4. เปิด drive.google.com ต้องเห็นโฟลเดอร์ "DayFlow" และไฟล์เดิมทยอยขึ้นมาตามโครงโฟลเดอร์เดิม
5. อัปโหลดไฟล์จากเมนูไฟล์ ต้องเห็นไฟล์นั้นในโฟลเดอร์ DayFlow ใน Drive

## 7. ปัญหาที่อาจเจอ

| อาการ | สาเหตุ | แก้ |
| --- | --- | --- |
| หน้า Google ขึ้น `redirect_uri_mismatch` | redirect URI ในข้อ 4.3 ไม่ตรงกับ `APP_URL` | แก้ให้ตรงทุกตัวอักษร (http/https, www, / ท้าย) |
| หน้า Google ขึ้น "Access blocked" / "app has not completed verification" | อีเมลนั้นไม่อยู่ใน Test users ขณะเป็น Testing | เพิ่มใน Test users หรือ publish |
| หน้าตั้งค่าขึ้น "เจ้าของระบบยังไม่ได้เปิดใช้ Google Drive" | ค่าใน `.env` ว่าง หรือยังไม่เริ่มคอนเทนเนอร์ใหม่ | ทำข้อ 5 อีกครั้ง |
| ผู้ใช้เห็น "การเชื่อม Google Drive หลุด" ทุกสัปดาห์ | แอปยังเป็น Testing | publish (ข้อ 3.6) |
| ไฟล์เดิมย้ายไม่ครบหลังปิดหน้าตั้งค่า | worker ไม่ได้เปิด | เริ่ม `--profile prod-worker` |

## 8. ทดสอบกับ Google จริงบนเครื่อง (สำหรับนักพัฒนา)

ชุดทดสอบ (`docker compose up -d` ที่ localhost:8080) ชี้ไป Google ปลอมผ่าน `docker-compose.override.yml`
ถ้าจะลองกับ Google จริง:

1. เพิ่ม redirect URI `http://localhost:8080/settings/drive/callback` ใน client (Google อนุญาต http เฉพาะ localhost)
2. ใน shell ที่จะรัน compose:

   ```bash
   export GOOGLE_DRIVE_CLIENT_ID=<จริง> GOOGLE_DRIVE_CLIENT_SECRET=<จริง>
   export GOOGLE_AUTH_URL= GOOGLE_TOKEN_URL= GOOGLE_REVOKE_URL= GOOGLE_API_URL=
   docker compose up -d app cron
   ```

   ค่าว่างของ 4 URL ทำให้แอปใช้ที่อยู่จริงของ Google (`config/config.php`)
3. เสร็จแล้ว `unset` ตัวแปรเหล่านั้นและ `docker compose up -d app cron` อีกครั้งเพื่อกลับไปใช้ Google ปลอม
