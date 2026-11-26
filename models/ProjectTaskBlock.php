<?php
// =====================================================
// models/ProjectTaskBlock.php — งานที่ต้องรอกัน (blockers) ภายในโปรเจค
//
// หนึ่งแถวของ project_task_blocks หมายถึง blocker_id ต้องเสร็จก่อน blocked_id
// ("A ต้องรอ B" = แถว blocker=B, blocked=A) การผูกทุกครั้งจึงต้องถือ lock ของ
// โปรเจคและตรวจวงจรจากขอบทั้งหมดที่โหลดครั้งเดียว เพื่อไม่ให้เกิดวงที่งานรอกันเอง
// =====================================================

// ProjectColumnException อยู่ใน ProjectColumn.php autoloader หาจากชื่อคลาสไม่เจอ จึงต้องโหลดเอง
require_once __DIR__ . '/ProjectColumn.php';

class ProjectTaskBlock
{
    /**
     * ดึงขอบ blocker → blocked ทั้งหมดของโปรเจคในคำสั่งเดียว
     *
     * กรองด้วยโปรเจคของงานปลายทาง (blocked_id) เพราะ FK ทั้งสองฝั่งชี้ project_tasks
     * และแถวที่บันทึกได้ต้องมีงานทั้งสองอยู่ในโปรเจคเดียวกันอยู่แล้ว
     *
     * @param int $projectId รหัสโปรเจค
     * @return list<array{blocker_id: int, blocked_id: int}> ขอบทั้งหมด (รหัสเป็น int)
     */
    public static function listForProject(int $projectId): array
    {
        $rows = DB::run(
            'SELECT b.blocker_id, b.blocked_id
             FROM project_task_blocks b
             JOIN project_tasks t ON t.id = b.blocked_id
             WHERE t.project_id = ?',
            [$projectId]
        )->fetchAll();

        return array_map(static fn(array $row): array => [
            'blocker_id' => (int)$row['blocker_id'],
            'blocked_id' => (int)$row['blocked_id'],
        ], $rows);
    }

    /**
     * ผูกงานสองงานในโปรเจค: blockerId ต้องเสร็จก่อน blockedId
     *
     * ทั้งหมดทำใน lock ของโปรเจค: งานทั้งสองต้องเป็นของโปรเจคนี้ (ไม่บอกว่า id ไหนมีอยู่)
     * งานขวางตัวเองถูกปฏิเสธ การผูกซ้ำไม่ทำอะไร (idempotent) และการผูกที่ปิดวงจร
     * (ตรงหรืออ้อม) ถูกปฏิเสธด้วยข้อความที่บอกเลขงานทั้งสอง
     *
     * ผลข้างเคียง: เพิ่มแถวใน project_task_blocks และบรรทัดประวัติของงานที่ต้องรอ
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $blockerId งานที่ต้องเสร็จก่อน
     * @param int $blockedId งานที่ต้องรอ
     * @param int $userId ผู้ที่ผูก (0 = แขก)
     * @throws ProjectColumnException 404 เมื่อไม่พบงาน / 422 เมื่อผูกตัวเองหรือปิดวงจร
     */
    public static function link(int $projectId, int $blockerId, int $blockedId, int $userId): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $blockerId, $blockedId, $userId): void {
            $tasks = self::tasksFor($projectId, [$blockerId, $blockedId]);
            $expected = $blockerId === $blockedId ? 1 : 2;
            // id ที่ไม่มี ถูกลบ หรืออยู่คนละโปรเจค ต้องตอบเหมือนกันเสมอ (EDGE-P4)
            if (count($tasks) !== $expected) {
                throw new ProjectColumnException(404, 'ไม่พบงานที่จะผูก');
            }
            if ($blockerId === $blockedId) {
                throw new ProjectColumnException(422, 'งานขวางตัวเองไม่ได้');
            }
            if (self::rowExists($blockerId, $blockedId)) {
                return;
            }

            $prefix = self::keyPrefix($projectId);
            $hops = self::cycleHops($projectId, $blockedId, $blockerId);
            if ($hops !== null) {
                $blockerKey = self::taskKey($prefix, $tasks[$blockerId]);
                $blockedKey = self::taskKey($prefix, $tasks[$blockedId]);
                if ($hops === 1) {
                    throw new ProjectColumnException(422, 'ผูกไม่ได้: ' . $blockerKey . ' รอ ' . $blockedKey
                        . ' อยู่แล้ว ถ้า ' . $blockedKey . ' รอ ' . $blockerKey . ' อีก งานทั้งสองจะรอกันเองไม่มีวันเริ่ม');
                }
                throw new ProjectColumnException(422, 'ผูกไม่ได้: ' . $blockerKey . ' รอ ' . $blockedKey
                    . ' อยู่แล้วผ่าน ' . ($hops - 1) . ' งาน ถ้าผูกอีก งานจะรอกันเป็นวงไม่มีวันเริ่ม');
            }

            DB::run(
                'INSERT INTO project_task_blocks (blocker_id, blocked_id) VALUES (?, ?)',
                [$blockerId, $blockedId]
            );
            ProjectActivity::log(
                $projectId,
                $userId,
                self::taskKey($prefix, $tasks[$blockedId]) . ' ต้องรอ ' . self::taskKey($prefix, $tasks[$blockerId])
                    . ' ' . (string)$tasks[$blockerId]['title'],
                $blockedId
            );
        });
    }

    /**
     * เอาความสัมพันธ์ระหว่างงานสองงานออก (มีได้ทางเดียวเท่านั้น)
     *
     * อ่านแถวก่อนลบเพื่อรู้ทิศและบันทึกประวัติ เมื่อไม่มีแถวก็จบเงียบ ๆ
     * ทั้งสองงานต้องเป็นของโปรเจคนี้ ไม่งั้นตอบ 404 เดียวกับการผูกงานที่ไม่มี
     *
     * ผลข้างเคียง: ลบแถวใน project_task_blocks และบรรทัดประวัติของงานที่ต้องรอ (ถ้ามีแถว)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $taskId งานฝั่งหนึ่งที่ URL อ้างถึง
     * @param int $otherId งานอีกฝั่ง
     * @param int $userId ผู้ที่เอาออก (0 = แขก)
     * @throws ProjectColumnException 404 เมื่อไม่พบงานฝั่งใดฝั่งหนึ่ง
     */
    public static function unlink(int $projectId, int $taskId, int $otherId, int $userId): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $taskId, $otherId, $userId): void {
            $tasks = self::tasksFor($projectId, [$taskId, $otherId]);
            $expected = $taskId === $otherId ? 1 : 2;
            if (count($tasks) !== $expected) {
                throw new ProjectColumnException(404, 'ไม่พบงานที่จะผูก');
            }
            if ($taskId === $otherId) {
                return;
            }

            $row = DB::run(
                'SELECT blocker_id, blocked_id FROM project_task_blocks
                 WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)',
                [$taskId, $otherId, $otherId, $taskId]
            )->fetch();
            if (!$row) {
                return;
            }

            $blockerId = (int)$row['blocker_id'];
            $blockedId = (int)$row['blocked_id'];
            DB::run(
                'DELETE FROM project_task_blocks WHERE blocker_id = ? AND blocked_id = ?',
                [$blockerId, $blockedId]
            );

            $prefix = self::keyPrefix($projectId);
            ProjectActivity::log(
                $projectId,
                $userId,
                self::taskKey($prefix, $tasks[$blockedId]) . ' ไม่ต้องรอ ' . self::taskKey($prefix, $tasks[$blockerId]) . ' แล้ว',
                $blockedId
            );
        });
    }

    /**
     * โหลดงานของโปรเจคตามรหัสที่ขอ (เฉพาะที่มีอยู่จริงในโปรเจคนี้)
     *
     * @param int $projectId รหัสโปรเจค
     * @param list<int> $ids รหัสงาน (ซ้ำได้)
     * @return array<int, array<string, mixed>> รหัสงาน => แถวงาน (id, number, title)
     */
    private static function tasksFor(int $projectId, array $ids): array
    {
        $unique = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $rows = DB::run(
            'SELECT id, number, title FROM project_tasks
             WHERE project_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$projectId], $unique)
        )->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        return $byId;
    }

    /**
     * ตรวจว่ามีแถว blocker → blocked อยู่แล้วหรือไม่
     *
     * @param int $blockerId งานที่ต้องเสร็จก่อน
     * @param int $blockedId งานที่ต้องรอ
     * @return bool true เมื่อมีแถวนี้แล้ว
     */
    private static function rowExists(int $blockerId, int $blockedId): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM project_task_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1',
            [$blockerId, $blockedId]
        )->fetchColumn();
    }

    /**
     * หาจำนวนขอบที่สั้นที่สุดจากงานหนึ่งไปถึงอีกงานตามทิศ blocker → blocked
     *
     * โหลดขอบของโปรเจคครั้งเดียวแล้วไล่ BFS ไม่มี SQL ในลูป ค่า null คือไปไม่ถึง
     * (การเพิ่มขอบ blocker → blocked จึงไม่ปิดวงจร)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $fromId งานที่เริ่มไล่ (งานที่ต้องรอ)
     * @param int $toId งานที่ต้องการไปถึง (งานที่จะเป็น blocker)
     * @return int|null จำนวนขอบของเส้นทางสั้นสุด หรือ null เมื่อไปไม่ถึง
     */
    private static function cycleHops(int $projectId, int $fromId, int $toId): ?int
    {
        $adjacency = [];
        foreach (self::listForProject($projectId) as $edge) {
            $adjacency[$edge['blocker_id']][] = $edge['blocked_id'];
        }

        $queue = [[$fromId, 0]];
        $seen = [$fromId => true];
        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            if ($node === $toId) {
                return $depth;
            }
            foreach ($adjacency[$node] ?? [] as $next) {
                if (isset($seen[$next])) continue;
                $seen[$next] = true;
                $queue[] = [$next, $depth + 1];
            }
        }
        return null;
    }

    /**
     * คำนำหน้าเลขงานของโปรเจค (ค่าว่างถ้าโปรเจคไม่มี)
     *
     * @param int $projectId รหัสโปรเจค
     * @return string คำนำหน้า เช่น DF
     */
    private static function keyPrefix(int $projectId): string
    {
        return (string)DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$projectId])->fetchColumn();
    }

    /**
     * เลขงานที่ผู้ใช้เห็น เช่น DF-49
     *
     * @param string $prefix คำนำหน้าของโปรเจค
     * @param array<string, mixed> $task แถวงาน (ต้องมี number)
     * @return string เลขงาน
     */
    private static function taskKey(string $prefix, array $task): string
    {
        return $prefix . '-' . (int)$task['number'];
    }
}
