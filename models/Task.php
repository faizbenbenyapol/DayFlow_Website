<?php
// =====================================================
// models/Task.php
// =====================================================

class Task
{
    public static function getAllForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, title, description, quadrant, status, due_date,
                    repeat_rule, repeat_until, position
             FROM tasks
             WHERE user_id = ?
             ORDER BY quadrant ASC, position ASC, id ASC',
            [$userId]
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
     * คืนรายการงานที่ครบกำหนดในวันที่ระบุ พร้อม is_done ในรูป 0/1
     *
     * @param int $userId รหัสผู้ใช้
     * @param string $date วันที่ Y-m-d
     * @return array แถวงานเรียงตาม position, id
     */
    public static function getForDate(int $userId, string $date): array
    {
        return DB::run(
            'SELECT id, title, status, position
             FROM tasks
             WHERE user_id = ? AND due_date = ?
             ORDER BY position ASC, id ASC',
            [$userId, $date]
        )->fetchAll();
    }

    /**
     * คืนตำแหน่งถัดไปสำหรับงานที่ครบกำหนดในวันนั้น
     *
     * @param int $userId รหัสผู้ใช้
     * @param string $date วันที่ Y-m-d
     * @return int ตำแหน่งถัดไป (เริ่มที่ 0)
     */
    public static function nextPositionForDate(int $userId, string $date): int
    {
        return 1 + (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM tasks WHERE user_id = ? AND due_date = ?',
            [$userId, $date]
        )->fetchColumn();
    }

    /**
     * คืนรายการจดด่วน (inbox task ที่ไม่มี due_date) พร้อม is_done ในรูป 0/1
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

        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM tasks WHERE user_id = ? AND quadrant <=> ?',
            [$userId, $quadrant]
        )->fetchColumn();

        DB::run(
            'INSERT INTO tasks (user_id, title, description, quadrant, status, due_date,
                                repeat_rule, repeat_until, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['title'],
                $data['description'] ?? null,
                $quadrant,
                $data['status'] ?? 'open',
                ($data['due_date'] ?? null) ?: null,
                Recurrence::normalise($data['repeat_rule'] ?? 'none'),
                ($data['repeat_until'] ?? null) ?: null,
                array_key_exists('position', $data) ? (int)$data['position'] : $maxPos + 1
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

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
            $params[] = (int)$data['quadrant'];
        }
        if (array_key_exists('status', $data)) {
            $fields[] = 'status = ?';
            $params[] = $data['status'];
        }
        if (array_key_exists('due_date', $data)) {
            $fields[] = 'due_date = ?';
            $params[] = $data['due_date'] ?: null;
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
     * Schedules the next occurrence of a repeating task once one is completed,
     * the same roll-forward a subscription uses when it renews. The finished
     * task stays put as history.
     *
     * Returns the new task's id, or null when nothing was scheduled: the task
     * does not repeat, has no due date, or the series has run out.
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
            'quadrant'     => (int)($task['quadrant'] ?? 1),
            'status'       => 'open',
            'due_date'     => $next,
            'repeat_rule'  => $rule,
            'repeat_until' => $until,
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
            "UPDATE tasks SET status = IF(status = 'done', 'open', 'done'), updated_at = NOW()
             WHERE id = ? AND user_id = ?",
            [$id, $userId]
        )->rowCount() > 0;
    }

    /**
     * เรียงตำแหน่งให้กับงานหนึ่งชุด โดยเปลี่ยนเฉพาะ position
     *
     * @param int $userId รหัสผู้ใช้
     * @param array<int, array<string, mixed>> $items แต่ละรายการมี id, position
     */
    public static function reorderPositions(int $userId, array $items): void
    {
        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE tasks SET position = ?, updated_at = NOW()
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

    public static function reorder(int $userId, array $items): void
    {
        // $items = [['id' => 1, 'quadrant' => 1, 'position' => 0], ...]
        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE tasks SET quadrant = ?, position = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        foreach ($items as $item) {
            $stmt->execute([
                (int)$item['quadrant'],
                (int)$item['position'],
                (int)$item['id'],
                $userId
            ]);
        }
    }
}
