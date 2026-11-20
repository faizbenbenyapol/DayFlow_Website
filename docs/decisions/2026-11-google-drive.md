# ร่างแบบ: เก็บไฟล์ใน Google Drive ของผู้ใช้ (เฟส 4)

สถานะ: ผู้ใช้ยืนยันแล้ว 2026-11-20 (ร่าง A)
รีไควร์เมนต์: docs/requirements/feedback-2026-11.md REQ-20–24, EDGE-11–14, Q-1
ระดับ: เต็ม (OAuth และย้ายไฟล์จริงของผู้ใช้ ลบไฟล์บนเซิร์ฟเวอร์หลังย้าย)

## 1. ระบบที่มีอยู่ (สืบจากโค้ด)

| ส่วน | ตอนนี้ | Drive จะกระทบยังไง |
| --- | --- | --- |
| ตาราง `files` | สารบัญ: id, ชื่อ, โฟลเดอร์แม่, ชนิด, ขนาด, `file_path` ชี้ไฟล์ใน `uploads/` | ต้องรู้ว่าไฟล์แต่ละแถวอยู่ที่ไหน (เซิร์ฟเวอร์หรือ Drive) |
| FileController | อัปโหลด สร้างโฟลเดอร์ เปลี่ยนชื่อ ย้าย ลบ ดาวน์โหลด ทั้งหมดผ่าน FileModel | ทุกคำสั่งต้องทำกับ Drive ด้วยเมื่อเชื่อมแล้ว |
| ShareController | ลิงก์แชร์อ้าง `file_id` แล้ว `readfile(UPLOAD_DIR . file_path)` เอง 3 จุด | EDGE-14: ลิงก์เดิมต้องเปิดได้หลังไฟล์ย้ายไป Drive |
| การเข้ารหัส | `appEncrypt()` / `appDecrypt()` (ใช้กับ API key ของ AI และ token Telegram) | ใช้เก็บ refresh token ของ Google |
| การสำรองบัญชี | ไม่รวมไฟล์ (AccountData) | ไม่กระทบ |
| Google | มี `GOOGLE_CLIENT_ID` สำหรับเข้าสู่ระบบ ไม่มี client secret | Drive ต้องใช้ OAuth แบบฝั่งเซิร์ฟเวอร์ ต้องมี secret และ redirect URI |

## 2. ร่าง A: สารบัญอยู่ที่ DayFlow ตัวไฟล์อยู่หลัง "ที่เก็บ" (เลือก)

ตาราง `files` ยังเป็นสารบัญเดียวของเมนูไฟล์ ทุกแถวเพิ่มคอลัมน์ `drive_id` ตัวไฟล์อยู่ที่ไหนดูจากแถว: มี `drive_id` = อยู่ใน Drive, มี `file_path` = อยู่บนเซิร์ฟเวอร์

ฝั่งผู้เรียก (สิ่งที่ FileController และ ShareController จะเขียน):

```php
// อัปโหลด: ไฟล์ใหม่ไปที่ที่เก็บของผู้ใช้ (Drive ถ้าเชื่อมอยู่ ไม่งั้นเซิร์ฟเวอร์)
$id = FileStore::forUser($userId)->upload($userId, $tmpPath, $name, $mime, $parentId);

// ดาวน์โหลด (รวมลิงก์แชร์): อ่านตามที่ไฟล์แถวนั้นอยู่จริง ไม่ใช่ตามผู้ใช้
FileStore::send($row);                       // stream ไปที่เบราว์เซอร์ แล้วจบคำขอ

// เปลี่ยนชื่อ ย้าย ลบ สร้างโฟลเดอร์: แก้สารบัญ และสะท้อนไป Drive ถ้าแถวนั้นอยู่ใน Drive
FileStore::forUser($userId)->rename($row, $newName);
```

โมดูล:

```
FileStore (interface สาธารณะเดียว)
  ├─ forUser(int $userId): FileStore        เลือกที่เก็บของไฟล์ใหม่
  ├─ send(array $row): never                อ่านตามแถว (local หรือ drive)
  ├─ upload / createFolder / rename / move / delete
  ├─ LocalStore   ทำแบบเดิม (ย้ายโค้ดจาก FileController + FileModel มา)
  └─ DriveStore   ใช้ DriveClient
DriveClient      คุยกับ Google ด้วย cURL: token, files.create/update/delete, resumable upload, stream download
DriveLink        การเชื่อมของผู้ใช้หนึ่งคน: token (เข้ารหัส), อีเมล, โฟลเดอร์, สถานะการย้าย
DriveMigration   ย้ายไฟล์เดิมขึ้น Drive ทีละไฟล์ ทำต่อได้ (step) ใช้ทั้งหน้าตั้งค่าและ cron
```

ข้อดี: รายการไฟล์ยังเร็ว (อ่านจากฐานข้อมูล ไม่เรียก Google ทุกครั้ง) ลิงก์แชร์ใช้ id เดิมจึงไม่พัง (EDGE-14) ระหว่างย้าย ไฟล์ครึ่งหนึ่งอยู่เซิร์ฟเวอร์ ครึ่งหนึ่งอยู่ Drive ก็ยังเปิดได้ทุกไฟล์ เพราะอ่านตามแถว
ข้อเสีย: สิ่งที่ผู้ใช้แก้ในเว็บ Google Drive เอง (เปลี่ยนชื่อ ลบ) ไม่สะท้อนกลับมา ไฟล์ที่ถูกลบจากฝั่ง Drive จะเปิดไม่ได้และบอกผู้ใช้ (ตรงกับที่ตกลงว่า Drive เป็นแค่ที่เก็บ และสิทธิ์ drive.file ก็ไม่เห็นไฟล์ที่ผู้ใช้เพิ่มเองอยู่แล้ว)

## 3. ร่าง B: Drive เป็นตัวจริง แอปเป็นหน้าต่าง (ไม่เลือก)

ผู้ใช้ที่เชื่อม Drive ไม่มีแถวใน `files` เลย FileController มีสองทาง: ผู้ใช้ไม่เชื่อมใช้ฐานข้อมูลเหมือนเดิม ผู้ใช้เชื่อมแล้วเรียก Drive API ตรง (list ลูกของโฟลเดอร์ทุกครั้งที่เปิด) id ของไฟล์เป็นสตริงของ Google

ข้อดี: แก้ในเว็บ Drive แล้วเห็นใน DayFlow ทันที ไม่มีสารบัญสองชุด
ข้อเสีย:
- เปิดโฟลเดอร์ทุกครั้งต้องรอ Google (ช้ากว่า และกินโควตา API)
- ลิงก์แชร์เดิมอ้าง id ตัวเลข ต้องมีตารางแปลง id (EDGE-14 ยากขึ้นมาก)
- red flag "สองทางทำงานเดียว": ทุก endpoint ของไฟล์มีสองสายโค้ด คนแก้ทีหลังแก้สายเดียวแล้วอีกสายพัง
- สิทธิ์ drive.file ไม่เห็นไฟล์ที่ผู้ใช้เพิ่มเองใน Drive อยู่แล้ว ข้อดีหลักของร่างนี้จึงได้ไม่เต็ม

## 4. คัดกรองด้วย red flags

| red flag | ร่าง A | ร่าง B |
| --- | --- | --- |
| สองทางทำงานเดียว | ไม่มี: ทุก caller ผ่าน FileStore | มี: ทุก endpoint แยกสองสาย |
| ข้อมูลรั่ว | รูปแบบของ Google อยู่ใน DriveClient ที่เดียว | id ของ Google ไหลไปถึงหน้าเว็บและลิงก์แชร์ |
| ความเป็นเจ้าของแตก | `files` เป็นเจ้าของโครงสร้าง Drive เป็นแค่ที่เก็บไบต์ | ฐานข้อมูลกับ Drive เป็นเจ้าของคนละครึ่ง |
| import ไส้ในได้ | ShareController เลิก readfile เอง ใช้ FileStore::send แทน (ปิดทางลัด) | ShareController ต้องรู้ทั้งสองแบบ |

เลือก A ยกจากร่าง B มาหนึ่งอย่าง: ถ้า Google ตอบว่าไฟล์ถูกลบจากฝั่ง Drive ให้บอกผู้ใช้ตรง ๆ ว่า "ไฟล์นี้ถูกลบจาก Google Drive แล้ว" แทน error ทั่วไป

## 5. ข้อมูลที่เพิ่ม (migration 035)

- `files.drive_id VARCHAR(128) NULL` (ไฟล์และโฟลเดอร์) + index
- ตารางใหม่ `drive_links` หนึ่งแถวต่อผู้ใช้: `user_id` PK, `google_email`, `refresh_token_enc`, `access_token_enc`, `access_expires_at`, `folder_id`, `folder_name`, `state` ENUM('connected','migrating','paused_full','disconnected'), `last_error`, timestamps

## 6. การย้ายไฟล์เดิม (REQ-22, EDGE-12, EDGE-13)

`DriveMigration::step(userId, budgetSeconds)` ย้ายทีละไฟล์จนหมดเวลา:
1. โฟลเดอร์ก่อน (สร้างใน Drive ตามโครงเดิม เก็บ drive_id)
2. ไฟล์ทีละไฟล์: อัปโหลดแบบ resumable → ตรวจว่าขนาดใน Drive เท่ากับไฟล์บนเซิร์ฟเวอร์ → บันทึก `drive_id` → ค่อยลบไฟล์บนเซิร์ฟเวอร์
3. แถวที่มี `drive_id` แล้วถูกข้าม จึงไม่ย้ายซ้ำ (EDGE-13) ใช้ lock ของ MariaDB ต่อผู้ใช้ กันหน้าเว็บกับ cron ย้ายพร้อมกัน
4. Drive เต็ม (Google ตอบ storageQuotaExceeded): หยุด state = paused_full ไฟล์ที่ยังไม่ขึ้นยังอยู่บนเซิร์ฟเวอร์ครบ กด "ลองต่อ" ได้ (EDGE-12)

หน้าตั้งค่าเรียก step ซ้ำระหว่างเปิดหน้าอยู่ (เห็นความคืบหน้าทันที) ปิดหน้าแล้ว cron เรียก step ต่อทุก 5 นาที

## 7. การตรวจ

- **fake Google server ที่เขียนเอง** (PHP เล็ก ๆ ในคอนเทนเนอร์ทดสอบ) ทำตามส่วนของ API ที่ใช้: token, files, resumable upload, alt=media, storageQuotaExceeded, invalid_grant ที่อยู่ของ Google ตั้งผ่าน env ได้ (ค่าเริ่มต้นคือของจริง) integration test และการตรวจบนเบราว์เซอร์ใช้ตัวนี้ได้โดยไม่ต้องมีบัญชี Google
- **บัญชี Google ทดสอบจริง** ตามเกณฑ์ของ REQ-20–22 ต้องมี OAuth client ของเจ้าของระบบก่อน (REQ-24 คู่มือ)
- blast radius + reviewer อิสระก่อนจบ (ระดับเต็ม)

## 8. เรื่องที่ต้องให้ผู้ใช้ตัดสิน

ผู้ใช้ตอบ 2026-11-20:

| เรื่อง | คำตอบ |
| --- | --- |
| ร่างแบบ | A |
| เลือกโฟลเดอร์ | รอบนี้แอปสร้างโฟลเดอร์ "DayFlow" เอง Picker ทีหลัง (CR-1 ในไฟล์รีไควร์เมนต์) |
| ยกเลิกการเชื่อม | คงรายการไว้ ไฟล์อยู่ใน Drive ครบ เปิดไม่ได้จนเชื่อมบัญชีเดิมกลับ |
| ทดสอบ | fake Google server ก่อน บัญชีจริงเมื่อเจ้าของระบบสร้าง OAuth client ตามคู่มือ (REQ-24) |
