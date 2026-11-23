# คู่มือส่งมอบ: วงจรใช้งานประจำวัน (Daily Loop)

| รายการ | ค่า |
| --- | --- |
| เวอร์ชัน | 1.0 (PR 1–3) |
| วันที่ส่งมอบ | 2026-11-20 |
| branch / commit | PR 1 `feat/daily-loop` (#6) → PR 2 `feat/daily-loop-today` (#8) → PR 3 `feat/daily-loop-focus` @ e3723a5 |
| รีไควร์เมนต์ | docs/requirements/daily-loop.md |
| สถานะ | พร้อมใช้แต่มีข้อจำกัด (ดูหัวข้อ 9) ยังไม่ได้ deploy |

## 1. สรุปสิ่งที่ส่งมอบ

เดิม "สิ่งที่ต้องทำ" แยกอยู่ 4 ที่ (Tasks, to-do ใน Planner, จดด่วน, งานโปรเจกต์) ตอนนี้งานส่วนตัวทั้งหมดอยู่ในที่เก็บงานเดียว (ตาราง `tasks`) งานที่ยังไม่ได้จัดช่องความสำคัญอยู่ในกล่อง "ยังไม่จัด" ของหน้า Tasks ผู้ใช้จดงานได้จากทุกหน้าด้วยปุ่ม + หรือคีย์ N หน้า "วันนี้" แสดงงานค้าง งานวันนี้ และงานโปรเจกต์ที่มอบให้ตัวเองในที่เดียว จัดการงานค้างได้ทีละงานหรือทั้งกลุ่ม กดปุ่มโฟกัสที่งานแล้วไปจับเวลาโดยประวัติผูกกับงานนั้น ส่วนบัญชีที่สมัครใหม่จะเจอการ์ดต้อนรับที่บอกว่าเริ่มจากตรงไหน

## 2. ตารางตรวจย้อนรีไควร์เมนต์ (traceability)

ผลทั้งหมด *รันแล้ว* บน Docker `localhost:8080` เว้นแต่ระบุไว้ ไฟล์ test อยู่ใน `tests/integration/` e2e คือ `scripts/e2e-shell.mjs`

| รหัส | รีไควร์เมนต์ (ย่อ) | ทำที่ไหน | ตรวจยังไง | ผล |
| --- | --- | --- | --- | --- |
| REQ-1 | to-do รายวันเดิมกลายเป็นงาน | `scripts/migrate-daily-loop.php`, `models/LegacyTaskMapper.php` | `migrate_daily_loop_test.php`, dry-run/apply บน :8080 | ผ่าน |
| REQ-2 | จดด่วนเดิมกลายเป็นงาน "ยังไม่จัด" | เหมือน REQ-1 | เหมือน REQ-1 | ผ่าน |
| REQ-3 | กล่อง "ยังไม่จัด" (งานเสร็จแสดง 7 วัน, CR-2) | `models/Task.php`, `public/assets/js/tasks.js` | `task_inbox_test.php`, `task_inbox_recent_done_test.php`, e2e "Inbox" | ผ่าน |
| REQ-4 | Planner ใช้ที่เก็บงานเดียว เรียงเอง (CR-3) | `controllers/PlannerController.php`, `tasks.day_position` | test ของ planner, e2e | ผ่าน |
| REQ-5 | หน้าจดด่วนเดิมไม่พัง | redirect `/quick-notes` → `/tasks#inbox` | `task_inbox_test.php`, e2e | ผ่าน |
| REQ-6 | ค้นหาเจองานที่ย้ายมา (รวมงานที่ซ่อน, CR-3) | `/api/tasks?show=<id>` | `task_inbox_test.php` | ผ่าน |
| REQ-7 | Export/Import ไฟล์ใหม่และเก่า | `models/AccountData.php` | test ของ account | ผ่าน |
| REQ-8 | งานทำซ้ำทำงานเหมือนเดิม | `Task::scheduleNextOccurrence` | unit + integration test | ผ่าน |
| REQ-9 | หน้า "วันนี้" แสดงงานค้าง | `controllers/DashboardController.php`, `public/assets/js/dashboard.js` | `dashboard_test.php`, เดินหน้าจอ | ผ่าน |
| REQ-10 | งานค้างอยู่จนผู้ใช้จัดการ | เหมือน REQ-9 | `dashboard_test.php` | ผ่าน |
| REQ-11 | จัดการงานค้างทีละงาน/ทั้งกลุ่ม | `POST /api/tasks/overdue` | `dashboard_test.php`, เดินหน้าจอ | ผ่าน |
| REQ-12 | งานวันนี้จากที่เก็บเดียว | summary `today_items` | `dashboard_test.php` | ผ่าน |
| REQ-13 | งานโปรเจกต์ที่มอบให้ฉัน | summary `my_project_items` | `dashboard_test.php` | ผ่าน |
| REQ-14 | งานโปรเจกต์กดเสร็จ/เปิดโปรเจกต์ | `dashboard.js`, `projects.js ?project=` | e2e `?project=` | ผ่าน |
| REQ-15 | กำหนดการและนิสัยยังเหมือนเดิม | หน้า "วันนี้" | `dashboard_test.php`, เดินหน้าจอ | ผ่าน |
| REQ-16 | จดงานได้ทุกหน้า + คีย์ N | `public/assets/js/shell.js`, `views/layout/header.php` | e2e (3 หน้า, แป้นไทย, Dvorak) | ผ่าน |
| REQ-17 | ชิปวัน ไม่ระบุ/วันนี้/พรุ่งนี้ | `shell.js`, `TaskController` รับ `today`/`tomorrow` | `task_inbox_test.php`, e2e | ผ่าน |
| REQ-18 | ใช้ได้บนมือถือ | `shell.css` | e2e 375px | ผ่านแบบมีเงื่อนไข: คีย์บอร์ดบน iPhone *ยังไม่ได้ทดสอบ* |
| REQ-19 | เลือกผู้รับผิดชอบจากสมาชิก | `ProjectTaskController`, `projects-board.js` | `project_task_assignee_test.php` | ผ่าน |
| REQ-20 | จับคู่ชื่อผู้รับผิดชอบเดิม (ตัดช่องว่างและ NBSP หัวท้าย) | `migrate-daily-loop.php` | `project_task_assignee_test.php` | ผ่าน |
| REQ-21 | กดโฟกัสจากงานบนหน้า "วันนี้" | `dashboard.js focusLink`, `FocusController::index`, `focus.js` | `focus_welcome_test.php`, e2e "New account", เดินหน้าจอ | ผ่าน |
| REQ-22 | หน้า Focus เลือกงานเองได้ | dropdown เดิม + `?task=` | `focus_welcome_test.php` | ผ่าน |
| REQ-23 | ส่วนต้อนรับผู้ใช้ใหม่ | `users.first_task_at`, `Task::shouldWelcome()` (ใช้ทั้งตอน render หน้าและใน summary), `views/dashboard/index.php` | `focus_welcome_test.php`, e2e, เดินหน้าจอ | ผ่าน |
| REQ-24 | สคริปต์ย้ายมี dry-run | `migrate-daily-loop.php` | รันบน :8080 | ผ่าน |
| REQ-25 | ย้ายแบบทั้งหมดหรือไม่เลย + รายงาน | transaction ในสคริปต์ | `migrate_daily_loop_test.php` | ผ่าน |
| REQ-26 | ตารางเดิมไม่ถูกลบ + มี rollback (CR-1) | `--rollback` | `migrate_daily_loop_test.php` | ผ่าน |
| REQ-27 | รันซ้ำไม่สร้างงานซ้ำ | ตาราง `legacy_task_copies` | `migrate_daily_loop_test.php` | ผ่าน |
| EDGE-1–4 | ไม่มีข้อมูล, ชื่อว่าง, ข้อความยาว, to-do เก่าค้าง | สคริปต์ย้าย | `migrate_daily_loop_test.php` | ผ่าน |
| EDGE-5 | งานค้างมากกว่าที่แสดง | `dashboard.js` (5 แถว + แสดงอีก, LIMIT 100) | `dashboard_test.php`, เดินหน้าจอ | ผ่าน |
| EDGE-6 | Viewer เห็น "ดูอย่างเดียว" | summary `can_edit` | `dashboard_test.php` | ผ่าน |
| EDGE-7 | สมาชิกถูกเอาออก/บัญชีถูกลบ | `ProjectMember::removeMember`, `User::deleteAccount` | `project_task_assignee_test.php` | ผ่าน |
| EDGE-8 | ชื่อซ้ำหลายคน/ชื่อแขก | สคริปต์ย้าย (เทียบแบบ binary) | `project_task_assignee_test.php` | ผ่าน |
| EDGE-9 | จดข้อความว่าง | `shell.js` | e2e | ผ่าน |
| EDGE-10 | จดตอนเน็ตหลุด | `shell.js` | e2e (offline) | ผ่าน |
| EDGE-11 | เขตเวลาต่างกัน | `data-today`, `meta.today`, `today`/`tomorrow` ฝั่งเซิร์ฟเวอร์ | เดินหน้าจอข้ามเที่ยงคืน | ผ่าน |
| EDGE-12 | เลื่อนงานทำซ้ำที่ค้าง | `apiOverdueBulk` | `dashboard_test.php` | ผ่าน |
| EDGE-13 | งานถูกลบระหว่างโฟกัส | `FocusController::apiCreate` | `focus_welcome_test.php`, `tracking_test.php`, e2e (รีโหลดกลางรอบ) | ผ่าน |
| EDGE-14 | สองแท็บ | หน้า "วันนี้" | `dashboard_test.php` | ผ่าน |
| EDGE-15 | บัญชี demo | สคริปต์ย้าย | `migrate_daily_loop_test.php` | ผ่าน |
| EDGE-16 | ลบงานแรกแล้วส่วนต้อนรับไม่กลับมา | `Task::create` ตั้ง `first_task_at` | `focus_welcome_test.php`, e2e | ผ่าน |

## 3. การเปลี่ยนจากรีไควร์เมนต์เดิม

| CR | เปลี่ยนอะไร | เพราะอะไร | อนุมัติโดย |
| --- | --- | --- | --- |
| CR-1 | REQ-26: ไม่เปลี่ยนชื่อตารางเดิม | `migrate.php --force` จะสร้างตารางชื่อเดิมกลับมา | ผู้ใช้ 2026-11-18 |
| CR-2 | REQ-3: งานเสร็จแสดงในกล่องแค่ 7 วัน | to-do เก่าที่เสร็จแล้วจะท่วมกล่อง | ผู้ใช้ 2026-11-19 |
| CR-3 | REQ-4/6: Planner เรียงเอง, ค้นหาพาไปงานที่ซ่อน | ลากในหน้า Tasks ทำลำดับ Planner เพี้ยน | ผู้ใช้ 2026-11-19 |
| CR-4 | REQ-23: บัญชีที่มีอยู่ตอน deploy ไม่เห็นส่วนต้อนรับ | คนที่ใช้มานานไม่ควรเจอข้อความต้อนรับ | ผู้ใช้ 2026-11-20 |
| CR-5 | EDGE-13: `/api/focus` บันทึกรอบที่ task_id ไม่ใช่ของผู้ใช้โดยไม่ผูกงาน (เดิมตอบ 422) | แยกงานที่ถูกลบกับงานของคนอื่นไม่ได้ และไม่บอกว่า id ไหนมีอยู่ | Claude ตาม EDGE-13, ผู้ใช้รับทราบ 2026-11-23 |

## 4. ความต้องการของระบบและการติดตั้ง

- สิ่งที่ต้องมีก่อน: PHP 8 และ MariaDB เหมือนเดิม ไม่มี dependency หรือตัวแปรสภาพแวดล้อมใหม่
- migration ใหม่ (รันตามลำดับโดย `scripts/migrate.php`):

| ไฟล์ | ทำอะไร |
| --- | --- |
| `029_task_inbox.sql` | `tasks.quadrant` เป็น NULL ได้, `completed_at`, `day_position`, ตาราง `legacy_task_copies` |
| `031_project_task_assignee.sql` | `project_tasks.assignee_user_id` + FK + index |
| `032_user_first_task.sql` | `users.first_task_at` บัญชีที่มีอยู่ตอนรันนับว่าเคยมีงานแล้ว |

เลข 030 เป็นของ PR subtasks (#7) ถ้า merge #7 ด้วย จะมี migration นั้นเพิ่ม

## 5. การรันและการนำขึ้นระบบ (deploy)

ทุกขั้นบน production *ยังไม่ได้รัน* ขั้นเดียวกันรันผ่านบน :8080 แล้ว

1. สำรองฐานข้อมูล production ทั้งก้อน
2. merge ตามลำดับ: #6 → (#7 ถ้าจะเอา) → #8 → PR 3 ทุกตัวต้องย้าย base ไป `main` ก่อน merge
3. รัน migration **ก่อน** อัปโหลดโค้ด เพราะโค้ดใหม่อ่าน `users.first_task_at` และ `tasks.day_position`:
   `php scripts/migrate.php`
4. อัปโหลดโค้ด
5. ดูจำนวนที่จะย้าย (ไม่เขียนอะไร): `php scripts/migrate-daily-loop.php`
6. ย้ายจริง **หลังโค้ดใหม่ขึ้นแล้วเท่านั้น**: `php scripts/migrate-daily-loop.php --apply` ต้องได้ exit 0 แล้วรัน `php scripts/migrate-daily-loop.php` อีกครั้ง บรรทัด "not yet copied" ของ Daily todos และ Quick items ต้องเป็น 0 *(รันแล้วบน :8080)*
7. เช็กว่าใช้ได้: เปิดหน้า "วันนี้", กล่อง "ยังไม่จัด" ในหน้า Tasks, to-do ใน Planner และจดงานด้วยคีย์ N

## 6. คู่มือการใช้งาน

1. **จดงาน:** กดปุ่ม + (มือถือ) หรือคีย์ N จากหน้าไหนก็ได้ พิมพ์แล้ว Enter เลือกวันได้ด้วยชิป ไม่ระบุ/วันนี้/พรุ่งนี้ งานที่ไม่ระบุวันไปอยู่ในกล่อง "ยังไม่จัด"
2. **เคลียร์งานค้าง:** หน้า "วันนี้" กลุ่ม "ค้าง" ใช้แถบด้านบนจัดทั้งกลุ่ม หรือปุ่ม ⋯ ที่แถวจัดทีละงาน
3. **โฟกัส:** กดปุ่มโฟกัสท้ายแถวงาน หน้าโฟกัสเปิดพร้อมเลือกงานนั้นไว้ กด "เริ่มจับเวลา" จบรอบแล้วประวัติขึ้นว่า "งาน: <ชื่อ>"
4. **งานโปรเจกต์:** เลือกผู้รับผิดชอบจากรายชื่อสมาชิกในฟอร์มงาน งานที่มอบให้ตัวเองขึ้นหน้า "วันนี้" เมื่อครบกำหนด
5. **ผู้ใช้ใหม่:** หน้า "วันนี้" แสดงการ์ดต้อนรับพร้อมช่องจดงานแรก จดแล้วการ์ดหายไปถาวร

ข้อความผิดพลาดที่อาจเจอ: "ผู้รับผิดชอบที่เลือกไม่ใช่สมาชิกของโปรเจคนี้" แปลว่าเลือกคนที่ไม่ได้อยู่ในโปรเจกต์ ให้เชิญเข้าโปรเจกต์ก่อน

## 7. โครงสร้างระบบสำหรับผู้ดูแล

```
ช่องจด (shell.js) ──POST /api/tasks──▶ tasks ◀── Planner (day_position)
                                        │
หน้า "วันนี้" ◀── GET /api/dashboard/summary (6 query คงที่) ── project_tasks.assignee_user_id
     │ ปุ่มโฟกัส
     ▼
/focus?task=<id> ──POST /api/focus──▶ focus_sessions.task_id (ON DELETE SET NULL)
```

- `models/Task.php`: ที่เก็บงานเดียว, `rememberHadTask()` ตั้ง `users.first_task_at` (เรียกจาก `create()` และการนำเข้าไฟล์สำรอง)
- `controllers/DashboardController.php`: summary ของหน้า "วันนี้" รวม `welcome`
- `controllers/TaskController.php`: `apiOverdueBulk`, รับ `due_date` เป็น `today`/`tomorrow`
- `controllers/FocusController.php`: `?task=` และ EDGE-13
- `public/assets/js/focus.js`: รอบที่กำลังเดินจำงานของตัวเองใน localStorage (`dayflow.focus`) กลับมาที่หน้าโฟกัสทางไหนก็ยังผูกกับงานเดิม
- `scripts/migrate-daily-loop.php`: ย้ายข้อมูล, จับคู่ผู้รับผิดชอบ, rollback
- จุดที่ต้องระวังเวลาแก้ต่อ:
  - เทียบวันด้วยวันของเซิร์ฟเวอร์ (`serverToday()` ใน `app.js`) ห้ามใช้ `toISOString()` กับวันที่ท้องถิ่น
  - `UPDATE` ตารางที่มี `updated_at ... ON UPDATE` ต้องใส่ `updated_at = updated_at` ถ้าไม่ใช่การแก้ของผู้ใช้
  - เทียบชื่อภาษาไทยใน SQL ใช้ `COLLATE utf8mb4_bin` เพราะ `utf8mb4_unicode_ci` มองข้ามวรรณยุกต์
  - migration ทุกไฟล์ต้องรันซ้ำได้ (`migrate.php --force`) และ backfill ต้องอยู่ใต้เงื่อนไขเดียวกับการเพิ่มคอลัมน์

## 8. การทดสอบ

- test ทั้งหมด: `docker exec dayflow-app php /var/www/html/tests/run.php http://localhost`
- e2e บนเบราว์เซอร์: `node scripts/e2e-shell.mjs --base http://localhost:8080`
- PHPStan: `docker run --rm -v "$(pwd -W)":/app -w /app ghcr.io/phpstan/phpstan:2.2.16 analyse --no-progress`
- CSS: `node scripts/check-css.mjs`
- ผลล่าสุด (PR 3, 2026-11-20): test 459 passed 0 failed, e2e 304 checks passed, PHPStan No errors, check-css ผ่าน, ไม่มี PHP warning ใหม่ *(รันแล้ว)*
- ทำโค้ดพังทีละจุด (mutation) แล้ว test แดงตรงตัว: PR 2 15 จุด, PR 3 7 จุด *(รันแล้ว)*
- ตรวจบนหน้าจอจริงแล้ว: สมัครบัญชีใหม่ → การ์ดต้อนรับ → จดงานแรก → ปุ่มโฟกัส → หน้าโฟกัสเลือกงานไว้ ที่ 1366px และ 375px
- reviewer อิสระ (Opus) ตรวจ PR 1 สองรอบ, PR 2 สามรอบ, PR 3 หนึ่งรอบ ข้อค้นพบแก้ครบและมี test กันไว้ *(รันแล้ว)*
- ยังไม่ได้ทดสอบ: คีย์บอร์ดบนจอ iPhone จริง (REQ-18), การ deploy บน production

## 9. ข้อจำกัด ความเสี่ยง และหนี้เทคนิค

- ไม่ทำรอบนี้: ดูหัวข้อ 10 ของไฟล์รีไควร์เมนต์ (จัดเมนูใหม่, สรุปท้ายสัปดาห์, จดตอนออฟไลน์, อัปเดตสดข้ามแท็บ ฯลฯ)
- iOS ไม่รองรับ `interactive-widget` ช่องจดอาจถูกคีย์บอร์ดบังบน iPhone
- ถ้าอัปโหลดโค้ดก่อนรัน migration 032 การสร้างงานจะได้ 500 ทั้งที่งานถูกบันทึกแล้ว จึงต้องทำตามลำดับในหัวข้อ 5 *(อนุมาน)*
- งานโปรเจกต์ไม่มีปุ่มโฟกัส เพราะ `focus_sessions.task_id` ผูกได้แค่ตาราง `tasks`
- บัญชีที่มีอยู่ก่อน deploy ไม่เห็นการ์ดต้อนรับเลย แม้ไม่เคยมีงาน (ผู้ใช้เลือก)
- "วันนี้" ของทุกหน้า (รวม Planner) คือวันของเซิร์ฟเวอร์ตอนโหลดหน้า เปิดหน้าทิ้งไว้ข้ามเที่ยงคืนต้องรีเฟรชถึงจะเลื่อนวัน
- #7 (subtasks) กับ #8 แก้ `models/Task.php` และ `tasks.js` ทั้งคู่ ตัวที่ merge ทีหลังต้องแก้ conflict

## 10. การย้อนกลับ (rollback)

1. ย้อนโค้ดเป็นเวอร์ชันก่อน Daily Loop
2. `php scripts/migrate-daily-loop.php --rollback` ดูจำนวนก่อน แล้ว `--rollback --yes` ลบงานที่คัดลอกมา คืนกล่อง "ยังไม่จัด" ไปช่อง 2
3. คอลัมน์ใหม่ (`completed_at`, `day_position`, `assignee_user_id`, `first_task_at`) ปล่อยไว้ได้ โค้ดเดิมไม่อ่าน

สิ่งที่ย้อนไม่ได้:
- งานที่แก้หลังย้ายแล้วจะหาย (ตารางเดิมยังเป็นข้อมูลก่อนย้าย)
- งานที่สร้างหลังย้ายจะอยู่ในหน้า Tasks ช่อง 2 และไม่ขึ้นใน Planner เดิม
- ไฟล์สำรองที่ export จากเวอร์ชันใหม่ import เข้าเวอร์ชันเก่าไม่ได้

## 11. สิ่งที่ผู้ใช้ต้องทำเอง

1. review และ merge #6, #8, PR 3 (และ #7 ถ้าจะเอา) ตามลำดับในหัวข้อ 5
2. สำรองฐานข้อมูล production และรันขั้นในหัวข้อ 5 บนเซิร์ฟเวอร์
3. ลองจดงานบน iPhone จริงว่าคีย์บอร์ดไม่บังช่องจด

## 12. การตัดสินใจสำคัญ

| เรื่อง | เลือกอะไร | ใครตัดสิน | เหตุผล / อ้างอิง |
| --- | --- | --- | --- |
| ที่เก็บงาน | รวมเข้า `tasks` ไม่สร้างตารางใหม่ | ผู้ใช้ 2026-11-18 | รีไควร์เมนต์หัวข้อ 4 |
| ตารางเดิม | เก็บไว้ ไม่เปลี่ยนชื่อ | ผู้ใช้ (CR-1) | หัวข้อ 3 |
| คีย์ลัด | N | ค่าแนะนำ (Q-2) | ไม่ชนคีย์ลัดเบราว์เซอร์ |
| ลบงานค้าง | ลบจริง ถามยืนยันพร้อมจำนวน | ค่าแนะนำ (Q-1) | ไม่ต้องเพิ่มสถานะใหม่ |
| หน้าจอ "วันนี้", ช่องจด, โฟกัส, ต้อนรับ | ตามร่าง | ผู้ใช้ 2026-11-19, 2026-11-20 | รีไควร์เมนต์หัวข้อ 8 |
| จำว่า "เคยมีงาน" | `users.first_task_at` | ผู้ใช้ 2026-11-20 | ทุกบัญชีมีแถวใน `users` |
| บัญชีเดิมกับการ์ดต้อนรับ | ไม่เห็น (CR-4) | ผู้ใช้ 2026-11-20 | ไม่ให้คนที่ใช้มานานเจอข้อความต้อนรับ |
| งานแรกจากการ์ดต้อนรับ | เป็นงานวันนี้ | ผู้ใช้ 2026-11-20 | ขึ้นหน้า "วันนี้" ทันที |
| task_id ที่ไม่ใช่ของผู้ใช้ใน `/api/focus` | บันทึกรอบไว้ ไม่ผูกงาน (เดิม 422, CR-5) | Claude (EDGE-13), ผู้ใช้รับทราบ 2026-11-23 | แยกงานที่ถูกลบกับงานของคนอื่นไม่ได้ และไม่บอกว่า id ไหนมีอยู่ |

## 13. งานถัดไปที่แนะนำ

- รอบหน้าของรีไควร์เมนต์: จัดเมนูใหม่, สรุปท้ายสัปดาห์, ลบตารางเดิมทิ้งเมื่อใช้จริงแล้วไม่มีปัญหา

## ภาคผนวก ก. รายการ commit

```
4f96a9b docs(requirements): agree the daily loop scope before building it
13498da docs(requirements): keep the old todo tables in place (CR-1)
e2e3b6d feat(tasks): copy daily todos and quick items into tasks, safely
7d80de6 feat(tasks): run Planner to-dos, quick notes, search and backups on tasks
81a68cd feat(tasks): give unsorted tasks their own box on the Tasks page
a77e4dc docs(requirements): the inbox shows done tasks from the last 7 days (CR-2)
f770e24 docs(requirements): Planner keeps its own order, search reaches hidden tasks (CR-3)
00cd5d2 fix(migrate): re-runs survive deleted accounts and report real tasks
eac44ea feat(tasks): hide old finished inbox tasks, give the Planner its own order
dcac17d feat(projects): assign project tasks to a member account
915692d feat(today): overdue, today and my project tasks on Today, capture from any page
e3723a5 feat(today): start focus from a task, welcome accounts that never had one
```

## ภาคผนวก ข. ประวัติเวอร์ชัน

| เวอร์ชัน | วันที่ | เปลี่ยนอะไร |
| --- | --- | --- |
| 1.0 | 2026-11-20 | ส่งมอบครบ PR 1–3 |
| 1.1 | 2026-11-20 | Planner ใช้วันของเซิร์ฟเวอร์, เปลี่ยนโปรเจกต์แล้วล้าง `?project=` (commit `fix(planner): ...` ใน #9) e2e 309 checks ผ่าน |
| 1.2 | 2026-11-20 | คีย์ลัด N ไม่ติดปุ่ม b ของแป้น Dvorak (แป้นไทยยังใช้ได้), จับคู่ชื่อผู้รับผิดชอบตัด NBSP ด้วย |
| 1.3 | 2026-11-20 | การ์ดต้อนรับมากับ HTML เลย วัดแล้วเดิมบัญชีใหม่เห็นกลุ่ม "วันนี้" ก่อน ~64 ms (เครื่องเร็ว) ถึง ~380 ms (CPU 4x, latency 300 ms) หลังแก้เฟรมแรกเป็นการ์ดต้อนรับและไม่สลับอีก |
| 1.4 | 2026-11-23 | ผู้ใช้รับทราบ CR-5 (`/api/focus` บันทึกรอบที่ task_id ไม่ใช่ของผู้ใช้โดยไม่ผูกงาน) |
