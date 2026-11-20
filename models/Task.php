<?php
// =====================================================
// models/Task.php
// =====================================================

class Task
{
    /**
     * จำนวนวันที่งาน done ใน inbox ยังคงแสดง นับจาก docs/requirements/daily-loop.md CR-2
     */
    public const INBOX_DONE_DAYS = 7;

    /**
     * คืนรายการงานทั้งหมดของผู้ใช้ โดย inbox (quadrant IS NULL) ซ่อนงาน done
     * ที่ทำเสร็จเกิน INBOX_DONE_DAYS วัน งานใน quadrant 1-4 แสดงทั้งหมด
     *
     * ถ้าระบุ $showId งานนั้นจะถูกคืนเสมอ แม้จะถูกกฎ 7 วันซ่อนไว้
     * (ใช้เมื่อผู้ใช้คลิกผลค้นหาที่ลิงก์มายังงานนั้น)
     *
     * @param int $userId รหัสผู้ใช้
     * @param int|null $showId รหัสงานที่ต้องการบังคับแสดง
     * @return array แถวงานเรียงตาม quadrant, position, id
     */
    public static function getAllForUser(int $userId, ?int $showId = null): array
    {
        $params = [$userId];
        $force  = '';
        if ($showId !== null) {
            $force  = ' OR id = ?';
            $params[] = $showId;
        }

        return DB::run(
            'SELECT id, title, description, quadrant, status, due_date,
                    repeat_rule, repeat_until, position
             FROM tasks
             WHERE user_id = ?
               AND (quadrant IS NOT NULL OR status = \'open\'
                    OR completed_at >= NOW() - INTERVAL ' . self::INBOX_DONE_DAYS . ' DAY' . $force . ')
             ORDER BY quadrant ASC, position ASC, id ASC',
            $params
        )->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM tasks WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    /**
     * คืนรายการงานที่ครบกำหนดในวันที่ระบุ พร้อม status
     *
     * เมื่อ $inboxOnly เป็น true จะคืนเฉพาะงานที่ไม่มี quadrant
     * (ใช้สำหรับลิงก์แชร์ที่แชร์ planner แต่ไม่แชร์ tasks)
     *
     * @param int $userId รหัสผู้ใช้
     * @param string $date วันที่ Y-m-d
     * @param bool $inboxOnly คืนเฉพาะงานที่ไม่มี quadrant
     * @return array แถวงานเรียงตาม day_position, id
     */
    public static function getForDate(int $userId, string $date, bool $inboxOnly = false): array
    {
        $sql = 'SELECT id, title, status, quadrant, day_position, position
                FROM tasks
                WHERE user_id = ? AND due_date = ?';
        if ($inboxOnly) {
            $sql .= ' AND quadrant IS NULL';
        }
        $sql .= ' ORDER BY day_position ASC, id ASC';

        return DB::run($sql, [$userId, $date])->fetchAll();
    }

    /**
     * คืนตำแหน่งถัดไปสำหรับงานที่ครบกำหนดในวันนั้น
     *
     * ใช้ day_position เพื่อให้ลำดับใน Planner แยกจากลำดับในหน้า Tasks
     *
     * @param int $userId รหัสผู้ใช้
     * @param string $date วันที่ Y-m-d
     * @return int ตำแหน่งถัดไป (เริ่มที่ 0)
     */
    public static function nextPositionForDate(int $userId, string $date): int
    {
        return 1 + (int)DB::run(
            'SELECT COALESCE(MAX(day_position), -1) FROM tasks WHERE user_id = ? AND due_date = ?',
            [$userId, $date]
        )->fetchColumn();
    }

    /**
     * คืนรายการจดด่วน (inbox task ที่ไม่มี due_date) พร้อม status
     *
     * @param int $userId รหัสผู้ใช้
     * @return array แถวงาน open ก่อน แล้วตาม created_at DESC
     */
    public static function getInboxForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, title, description, status, created_at
             FROM tasks
             WHERE user_id = ? AND quadrant IS NULL AND due_date IS NULL
             ORDER BY status ASC, created_at DESC',
            [$userId]
        )->fetchAll();
    }

    /**
     * สร้างงานใหม่
     *
     * รองรับ quadrant เป็น null (inbox) และรับตำแหน่งที่ส่งมาล่วงหน้าได้
     * ถ้าไม่ได้ระบุ day_position และมี due_date งานจะไปต่อท้ายวันนั้น
     * ผลข้างเคียง: งานแรกของบัญชีตั้ง users.first_task_at (ปิดส่วนต้อนรับถาวร)
     *
     * @param int $userId รหัสผู้ใช้
     * @param array<string, mixed> $data ข้อมูลงาน
     * @return int รหัสงานที่สร้าง
     */
    public static function create(int $userId, array $data): int
    {
        $quadrant = array_key_exists('quadrant', $data) && $data['quadrant'] === null
            ? null
            : (int)($data['quadrant'] ?? 1);

        $status = ($data['status'] ?? 'open') === 'done' ? 'done' : 'open';

        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM tasks WHERE user_id = ? AND quadrant <=> ?',
            [$userId, $quadrant]
        )->fetchColumn();

        $dueDate = ($data['due_date'] ?? null) ?: null;
        if (array_key_exists('day_position', $data)) {
            $dayPos = (int)$data['day_position'];
        } elseif ($dueDate !== null) {
            $dayPos = self::nextPositionForDate($userId, $dueDate);
        } else {
            $dayPos = 0;
        }

        DB::run(
            'INSERT INTO tasks (user_id, title, description, quadrant, status, completed_at, due_date,
                                repeat_rule, repeat_until, position, day_position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['title'],
                $data['description'] ?? null,
                $quadrant,
                $status,
                $status === 'done' ? date('Y-m-d H:i:s') : null,
                $dueDate,
                Recurrence::normalise($data['repeat_rule'] ?? 'none'),
                ($data['repeat_until'] ?? null) ?: null,
                array_key_exists('position', $data) ? (int)$data['position'] : $maxPos + 1,
                $dayPos,
            ]
        );
        $taskId = (int)DB::conn()->lastInsertId();
        self::rememberHadTask($userId);
        return $taskId;
    }

    /**
     * จำว่าบัญชีนี้เคยมีงานแล้ว ส่วนต้อนรับบนหน้าวันนี้จะไม่กลับมาแม้ลบงานทิ้งหมด (EDGE-16)
     * เรียกจากทุกทางที่ใส่งานลงตาราง tasks: create() และการนำเข้าไฟล์สำรอง
     * ผลข้างเคียง: ตั้ง users.first_task_at ครั้งแรกครั้งเดียว ไม่แตะ updated_at
     * เพราะคอลัมน์ ON UPDATE จะเปลี่ยนเอง และนี่ไม่ใช่การแก้บัญชีของผู้ใช้
     */
    public static function rememberHadTask(int $userId): void
    {
        DB::run(
            'UPDATE users SET first_task_at = NOW(), updated_at = updated_at WHERE id = ? AND first_task_at IS NULL',
            [$userId]
        );
    }

    /**
     * บัญชีนี้ควรเห็นการ์ดต้อนรับไหม (REQ-23): ไม่เคยมีงานเลย
     * first_task_at จำไว้แม้ลบงานแรกทิ้ง (EDGE-16) ส่วน NOT EXISTS กันบัญชีที่มีงานอยู่แล้วแต่ first_task_at ยังว่าง
     * เช่นงานที่สคริปต์ย้ายคัดลอกมาให้ ใช้ทั้งตอน render หน้าวันนี้และใน summary เพื่อให้สองทางตอบตรงกัน
     *
     * @return bool true เมื่อควรต้อนรับ
     */
    public static function shouldWelcome(int $userId): bool
    {
        return (bool)DB::run(
            'SELECT u.first_task_at IS NULL AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.user_id = u.id)
             FROM users u WHERE u.id = ?',
            [$userId]
        )->fetchColumn();
    }

    /**
     * อัปเดตงาน โดย quadrant เป็น 0 หรือ null จะเก็บเป็น NULL (inbox)
     *
     * ถ้าเปลี่ยน due_date ให้ไปต่อท้ายวันใหม่ โดย day_position ต้องอยู่ก่อน due_date
     * ใน SET เพราะ MariaDB ประเมินจากซ้ายไปขวาและใช้ค่าที่อัปเดตแล้ว
     *
     * @param int $id รหัสงาน
     * @param int $userId รหัสผู้ใช้
     * @param array<string, mixed> $data ข้อมูลที่ต้องการอัปเดต
     * @return bool พบและอัปเดตหรือไม่
     */
    public static function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }
        if (array_key_exists('quadrant', $data)) {
            $fields[] = 'quadrant = ?';
            // quadrant 0/null หมายถึง inbox ต้องเก็บเป็น NULL ในฐานข้อมูล
            $params[] = ($data['quadrant'] === null || $data['quadrant'] === 0) ? null : (int)$data['quadrant'];
        }
        if (array_key_exists('status', $data)) {
            $fields[] = "completed_at = IF(? = 'done', IF(status = 'done', completed_at, NOW()), NULL)";
            $params[] = $data['status'];
            $fields[] = 'status = ?';
            $params[] = $data['status'];
        }
        if (array_key_exists('due_date', $data)) {
            $newDate = $data['due_date'] ?: null;
            if ($newDate !== null) {
                $nextDayPos = self::nextPositionForDate($userId, $newDate);
                $fields[] = 'day_position = IF(due_date <=> ?, day_position, ?)';
                $params[] = $newDate;
                $params[] = $nextDayPos;
            }
            $fields[] = 'due_date = ?';
            $params[] = $newDate;
        }
        if (array_key_exists('position', $data)) {
            $fields[] = 'position = ?';
            $params[] = (int)$data['position'];
        }
        if (array_key_exists('repeat_rule', $data)) {
            $fields[] = 'repeat_rule = ?';
            $params[] = Recurrence::normalise($data['repeat_rule']);
        }
        if (array_key_exists('repeat_until', $data)) {
            $fields[] = 'repeat_until = ?';
            $params[] = $data['repeat_until'] ?: null;
        }

        if (empty($fields)) return false;

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $userId;

        $stmt = DB::run(
            'UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        );
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = DB::run('DELETE FROM tasks WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * สร้างงานถัดไปของงานที่ทำซ้ำ เมื่องานปัจจุบันถูกทำเสร็จ
     *
     * งานเดิมยังคงอยู่เป็นประวัติ ฟังก์ชันนี้ insert งานใหม่ลง tasks
     * คืน id ของงานใหม่ หรือ null เมื่องานไม่ซ้ำ ไม่มี due_date หรือหมดช่วงซ้ำ
     *
     * @param array<string, mixed> $task แถวงานที่ทำเสร็จ
     * @param int $userId รหัสผู้ใช้
     * @return int|null id งานใหม่ หรือ null ถ้าไม่ต้องสร้าง
     */
    public static function scheduleNextOccurrence(array $task, int $userId): ?int
    {
        $rule = Recurrence::normalise($task['repeat_rule'] ?? 'none');
        if ($rule === 'none' || empty($task['due_date'])) return null;

        $next = Recurrence::next((string)$task['due_date'], $rule);
        if ($next === null) return null;

        $until = $task['repeat_until'] ?? null;
        if ($until && $next > substr((string)$until, 0, 10)) return null;

        // A repeating task that is completed several cycles late should come
        // back on the next date that is still ahead, not pile up behind.
        $today = date('Y-m-d');
        $guard = 0;
        while ($next < $today && $guard++ < 500) {
            $candidate = Recurrence::next($next, $rule);
            if ($candidate === null || $candidate <= $next) break;
            if ($until && $candidate > substr((string)$until, 0, 10)) return null;
            $next = $candidate;
        }

        return self::create($userId, [
            'title'        => $task['title'],
            'description'  => $task['description'] ?? null,
            'quadrant'     => $task['quadrant'] ?? null,
            'status'       => 'open',
            'due_date'     => $next,
            'repeat_rule'  => $rule,
            'repeat_until' => $until,
            'day_position' => self::nextPositionForDate($userId, $next),
        ]);
    }

    /**
     * สลับสถานะ open/done ของงาน คืน true เมื่อพบงานของผู้ใช้
     *
     * @param int $id รหัสงาน
     * @param int $userId รหัสผู้ใช้
     * @return bool พบและอัปเดตหรือไม่
     */
    public static function toggle(int $id, int $userId): bool
    {
        return DB::run(
            "UPDATE tasks SET completed_at = IF(status = 'done', NULL, NOW()),
                              status = IF(status = 'done', 'open', 'done'),
                              updated_at = NOW()
             WHERE id = ? AND user_id = ?",
            [$id, $userId]
        )->rowCount() > 0;
    }

    /**
     * เรียงตำแหน่งให้กับงานหนึ่งชุด โดยเปลี่ยนเฉพาะ day_position
     *
     * ใช้สำหรับ Planner reorder เท่านั้น ไม่ต้องอัปเดต updated_at
     * เพราะการเปลี่ยนลำดับไม่ใช่การแก้ไขเนื้อหา
     *
     * @param int $userId รหัสผู้ใช้
     * @param array<int, array<string, mixed>> $items แต่ละรายการมี id, position
     */
    public static function reorderPositions(int $userId, array $items): void
    {
        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE tasks SET day_position = ?
             WHERE id = ? AND user_id = ?'
        );
        foreach ($items as $item) {
            $stmt->execute([
                (int)$item['position'],
                (int)$item['id'],
                $userId
            ]);
        }
    }

    /**
     * ย้ายงานระหว่าง quadrant รองรับการลากเข้า/ออก inbox (quadrant 0 = NULL)
     *
     * @param int $userId รหัสผู้ใช้
     * @param array<int, array<string, mixed>> $items รายการที่มี id, quadrant, position
     */
    public static function reorder(int $userId, array $items): void
    {
        // $items = [['id' => 1, 'quadrant' => 0, 'position' => 0], ...]
        // quadrant 0 หมายถึง inbox ต้องเก็บเป็น NULL
        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE tasks SET quadrant = ?, position = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        foreach ($items as $item) {
            $quadrant = ($item['quadrant'] === null || (int)$item['quadrant'] === 0) ? null : (int)$item['quadrant'];
            $stmt->execute([
                $quadrant,
                (int)$item['position'],
                (int)$item['id'],
                $userId
            ]);
        }
    }
}
