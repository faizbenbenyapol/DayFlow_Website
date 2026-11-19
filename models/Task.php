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
     * คืนรายการงานทั้งหมดของผู้ใช้ พร้อมเวลาเตือนและจำนวนงานย่อย โดย inbox (quadrant IS NULL)
     * ซ่อนงาน done ที่ทำเสร็จเกิน INBOX_DONE_DAYS วัน งานใน quadrant 1-4 แสดงทั้งหมด
     *
     * ถ้าระบุ $showId งานนั้นจะถูกคืนเสมอ แม้จะถูกกฎ 7 วันซ่อนไว้
     * (ใช้เมื่อผู้ใช้คลิกผลค้นหาที่ลิงก์มายังงานนั้น)
     *
     * @param int $userId รหัสผู้ใช้
     * @param int|null $showId รหัสงานที่ต้องการบังคับแสดง
     * @return array<int, array<string, mixed>> แถวงานเรียงตาม quadrant, position, id
     */
    public static function getAllForUser(int $userId, ?int $showId = null): array
    {
        $params = [$userId];
        $force  = '';
        if ($showId !== null) {
            $force  = ' OR t.id = ?';
            $params[] = $showId;
        }

        return DB::run(
            'SELECT t.id, t.title, t.description, t.quadrant, t.status, t.due_date,
                    t.repeat_rule, t.repeat_until, t.remind_at, t.position,
                    COALESCE(s.subtask_total, 0) AS subtask_total,
                    COALESCE(s.subtask_done, 0) AS subtask_done
             FROM tasks t
             LEFT JOIN (
                 SELECT task_id,
                        COUNT(*) AS subtask_total,
                        SUM(is_done) AS subtask_done
                 FROM task_subtasks
                 GROUP BY task_id
             ) s ON s.task_id = t.id
             WHERE t.user_id = ?
               AND (t.quadrant IS NOT NULL OR t.status = \'open\'
                    OR t.completed_at >= NOW() - INTERVAL ' . self::INBOX_DONE_DAYS . ' DAY' . $force . ')
             ORDER BY t.quadrant ASC, t.position ASC, t.id ASC',
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
     * สร้างงานใหม่ รองรับ quadrant null (inbox), ตำแหน่งล่วงหน้า และเวลาเตือน
     * ถ้าไม่ได้ระบุ day_position และมี due_date งานจะไปต่อท้ายวันนั้น
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
                                repeat_rule, repeat_until, remind_at, position, day_position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                ($data['remind_at'] ?? null) ?: null,
                array_key_exists('position', $data) ? (int)$data['position'] : $maxPos + 1,
                $dayPos,
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    /**
     * อัปเดตงาน โดย quadrant 0/null เป็น inbox และเมื่อเปลี่ยน remind_at จะลบ reminded_at
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
        if (array_key_exists('remind_at', $data)) {
            // บรรทัดนี้ต้องอยู่ก่อน remind_at = ? เพราะ MariaDB ประเมิน SET จากซ้ายไปขวา
            // ตอนนี้ remind_at ในฟังก์ชัน IF ยังเป็นค่าเก่าอยู่ จึงเปรียบเทียบกับค่าใหม่ได้ถูกต้อง
            $fields[] = 'reminded_at = IF(remind_at <=> ?, reminded_at, NULL)';
            $fields[] = 'remind_at = ?';
            $params[] = $data['remind_at'] ?: null;
            $params[] = $data['remind_at'] ?: null;
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
     * สร้างงานถัดไปของงานที่ทำซ้ำเมื่อทำเสร็จ พร้อมเลื่อน remind_at และคัดลอกงานย่อย
     *
     * งานเดิมยังคงอยู่เป็นประวัติ รอบใหม่อยู่ช่องเดิม (รวม inbox) และต่อท้ายวันใหม่ใน Planner
     *
     * @param array<string, mixed> $task แถวงานเดิม
     * @param int $userId รหัสผู้ใช้
     * @return int|null รหัสงานใหม่ หรือ null ถ้าไม่มีการทำซ้ำ/ครบกำหนด/เกิน repeat_until
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

        $remindAt = null;
        if (!empty($task['remind_at'])) {
            $diffDays = (int)(new DateTimeImmutable($task['due_date']))
                ->diff(new DateTimeImmutable($next))
                ->format('%r%a');
            $remindAt = (new DateTimeImmutable($task['remind_at']))
                ->modify("{$diffDays} days")
                ->format('Y-m-d H:i:s');
        }

        $newId = self::create($userId, [
            'title'        => $task['title'],
            'description'  => $task['description'] ?? null,
            'quadrant'     => $task['quadrant'] ?? null,
            'status'       => 'open',
            'due_date'     => $next,
            'repeat_rule'  => $rule,
            'repeat_until' => $until,
            'remind_at'    => $remindAt,
            'day_position' => self::nextPositionForDate($userId, $next),
        ]);

        DB::run(
            'INSERT INTO task_subtasks (task_id, user_id, title, is_done, position)
             SELECT ?, user_id, title, 0, position
             FROM task_subtasks
             WHERE task_id = ? AND user_id = ?',
            [$newId, (int)$task['id'], $userId]
        );

        return $newId;
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
