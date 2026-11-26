<?php
// =====================================================
// models/ProjectTask.php — Kanban Task Model
// =====================================================

// ProjectColumnException อยู่ใน ProjectColumn.php autoloader หาจากชื่อคลาสไม่เจอ และบางคำสั่ง
// (เช่น plan()) โยนก่อนเรียก ProjectColumn::locked() จึงต้องโหลดเอง
require_once __DIR__ . '/ProjectColumn.php';

class ProjectTask
{
    /** ชนิดงานที่รับได้ (ตรงกับ ENUM ของ project_tasks.kind) */
    public const KINDS = ['epic', 'story', 'task', 'bug', 'sub'];

    /** ชื่อไทยของชนิดงาน (ใช้ในบรรทัดประวัติ) */
    public const KIND_LABELS = ['epic' => 'งานใหญ่', 'story' => 'เรื่องผู้ใช้', 'task' => 'งาน', 'bug' => 'บั๊ก', 'sub' => 'งานย่อย'];

    /** ชุดแต้มที่รับได้ตามสเกลของโปรเจค */
    public const POINT_SCALES = ['fib' => [1, 2, 3, 5, 8, 13, 21], 'ten' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]];

    /** จำนวนงานสูงสุดที่วางแผนเข้า/ออกรอบงานได้ในคำขอเดียว */
    public const MAX_PLAN_TASKS = 500;

    /**
     * ดึงข้อมูลงานทั้งหมดในโครงการ จัดกลุ่มและเรียงตามตำแหน่งการลากวาง (สำหรับผู้ใช้ร่วมทีมทุกคน รวมถึงแขกสาธารณะ)
     *
     * แถวที่ได้มี assignee_name = ชื่อที่แสดงของผู้ใช้ที่ผูกไว้ หรือ username ถ้าไม่มีชื่อที่แสดง
     * หรือข้อความ assignee เดิมถ้ายังไม่ได้ผูกบัญชี
     */
    public static function getByProject(int $projectId): array
    {
        return DB::run(
            'SELECT pt.*,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username, pt.guest_name) AS creator_name,
                    COALESCE(NULLIF(au.display_name, \'\'), au.username, pt.assignee) AS assignee_name
             FROM project_tasks pt
             LEFT JOIN users u ON pt.user_id = u.id
             LEFT JOIN users au ON pt.assignee_user_id = au.id
             WHERE pt.project_id = ?
             ORDER BY pt.position ASC, pt.id ASC',
            [$projectId]
        )->fetchAll();
    }

    /**
     * ดึงงานตามรหัสไอดี
     */
    public static function getById(int $id): ?array
    {
        return DB::run(
            'SELECT * FROM project_tasks WHERE id = ?',
            [$id]
        )->fetch() ?: null;
    }

    /**
     * สร้างงานใหม่ในโครงการในคอลัมน์ที่เลือกไว้แล้ว (รองรับผู้ใช้ทั่วไป และ Guest)
     *
     * ตำแหน่งคือต่อท้ายคอลัมน์นั้น ส่วนสถานะเดิมถูกคำนวณจากคอลัมน์ด้วย
     * นิพจน์เดียวกับ moveTo() (เป็นฟังก์ชันของคอลัมน์ ไม่ขึ้นกับสถานะเดิม)
     *
     * เลขงานและตัวนับถูกอ่าน/เขียนใน lock ของโปรเจคเดียวกับที่ตรวจงานแม่
     * จึงไม่มีคำขออื่นแทรกเลขซ้ำหรือเห็นงานแม่ที่เพิ่งเปลี่ยนชนิดไปแล้ว
     *
     * @param int $userId ผู้สร้าง (0 = guest)
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $column คอลัมน์ที่ตรวจแล้ว (ต้องมี id)
     * @param array<string, mixed> $data ข้อมูลงาน (kind, parent_id, story_points, sprint_id ไม่บังคับ)
     * @return array{id: int, number: int} รหัสงานใหม่และเลขงานในโปรเจค
     */
    public static function create(int $userId, int $projectId, array $column, array $data): array
    {
        $columnId = (int)$column['id'];
        /** @var array{id: int, number: int} $created */
        $created = ProjectColumn::locked($projectId, static function () use ($userId, $projectId, $columnId, $data): array {
            // อ่านคอลัมน์ซ้ำหลังได้ lock: ถ้าคำขอลบชนะไปแล้ว ต้องตอบ 404 ไม่ใช่
            // ปล่อยให้ FK ON DELETE SET NULL ทำให้งานลอยอย่างไร้คอลัมน์
            if (!ProjectColumn::findInProject($projectId, $columnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $project = DB::run(
                'SELECT next_task_number, point_scale FROM projects WHERE id = ?',
                [$projectId]
            )->fetch();
            if (!$project) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลโปรเจค');
            }

            // ตรวจชนิด/งานแม่/แต้ม/รอบงานก่อน insert: งานแม่หายหรือชนิดผิดต้องไม่ทิ้งงานครึ่ง ๆ กลาง ๆ
            $kind = array_key_exists('kind', $data) ? self::checkedKind($data['kind']) : 'task';
            $parentId = array_key_exists('parent_id', $data) ? self::parentIdFromInput($data['parent_id']) : null;
            $parent = self::checkedParent($projectId, 0, $kind, $parentId);
            $points = self::checkedPoints($data['story_points'] ?? null, (string)$project['point_scale'], $kind);
            $sprintId = self::sprintForCreate($projectId, $kind, $parent, $data);
            $number = (int)$project['next_task_number'];

            $maxPos = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks
                 WHERE project_id = ? AND column_id = ?',
                [$projectId, $columnId]
            )->fetchColumn();

            $insertUserId = ($userId > 0) ? $userId : null;
            $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;
            $assigneeUserId = !empty($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null;
            $assignee = ($assigneeUserId !== null) ? null : (!empty($data['assignee']) ? $data['assignee'] : null);

            $insert = DB::run(
                'INSERT INTO project_tasks (project_id, number, user_id, guest_name, title, status, priority, due_date, category, assignee_user_id, assignee, kind, parent_id, story_points, sprint_id, column_id, position, checklist)
                 SELECT ?, ?, ?, ?, ?, ' . self::shadowStatusSql() . ', ?, ?, ?, ?, ?, ?, ?, ?, ?, c.id, ?, ?
                 FROM project_columns c
                 WHERE c.id = ? AND c.project_id = ?',
                [
                    $projectId,
                    $number,
                    $insertUserId,
                    $guestName,
                    $data['title'],
                    $data['priority'] ?? 'Medium',
                    !empty($data['due_date']) ? $data['due_date'] : null,
                    !empty($data['category']) ? $data['category'] : null,
                    $assigneeUserId,
                    $assignee,
                    $kind,
                    $parentId,
                    $points,
                    $sprintId,
                    $maxPos + 1,
                    !empty($data['checklist']) ? json_encode($data['checklist'], JSON_UNESCAPED_UNICODE) : null,
                    $columnId,
                    $projectId
                ]
            );
            // คอลัมน์หายไปจริง (เช่นถูกลบหลังอ่านครั้งแรก) → INSERT…SELECT ใส่ 0 แถว
            if ($insert->rowCount() < 1) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }
            $taskId = (int)DB::conn()->lastInsertId();

            // ตัวนับเพิ่มอย่างเดียว: เลขที่เคยออกแล้วจึงไม่ถูกนำกลับมาใช้ แม้งานจะถูกลบ
            DB::run(
                'UPDATE projects SET next_task_number = next_task_number + 1, updated_at = updated_at WHERE id = ?',
                [$projectId]
            );

            return ['id' => $taskId, 'number' => $number];
        });
        return $created;
    }

    /**
     * อัปเดตงาน
     *
     * รองรับทั้ง assignee_user_id ใหม่และ assignee ข้อความเดิม
     * assignee_user_id ที่ว่าง/ศูนย์/null หมายถึงยังไม่ระบุ และจะล้าง assignee ข้อความด้วย
     */
    public static function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        // สถานะและตำแหน่งเขียนผ่าน moveTo()/reorder() เท่านั้น
        $allowed = ['title', 'description', 'priority', 'due_date', 'category', 'assignee_user_id', 'assignee', 'checklist'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = ?";
                if (($field === 'due_date' || $field === 'assignee_user_id' || $field === 'assignee') && empty($data[$field])) {
                    $params[] = null;
                } elseif ($field === 'checklist') {
                    $params[] = is_array($data[$field]) ? json_encode($data[$field], JSON_UNESCAPED_UNICODE) : $data[$field];
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) return false;

        $params[] = $id;

        return DB::run(
            'UPDATE project_tasks SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        )->rowCount() > 0;
    }

    /**
     * ตรวจและเขียนชนิดงาน งานแม่ แต้ม และรอบงานของงานในล็อกของโปรเจค
     *
     * อ่านงานและงานแม่ซ้ำหลังได้ lock (และอ่านงานลูกเมื่อส่งชนิดหรืองานแม่มา)
     * เพราะคำขออื่นอาจเปลี่ยนหรือลบไปแล้ว
     * ตรวจเฉพาะค่าที่ client ส่งมา แต่ตรวจผลรวมกับค่าเดิม (เช่น เปลี่ยนเป็นงานใหญ่ต้องล้างแต้ม)
     * งานย่อยใช้รอบงานของงานแม่เสมอ ค่าที่ client ส่งให้ sub จึงถูกเมิน
     * งานใหญ่ไม่มีรอบงาน และงานที่ย้ายรอบจะพางานย่อยของมันไปด้วย
     * โยน ProjectColumnException(422) เมื่อผลรวมผิดกฎ และ 404 เมื่องานหายไป
     *
     * @param int $taskId รหัสงาน
     * @param int $projectId รหัสโปรเจคของงาน
     * @param array<string, mixed> $input เฉพาะคีย์ที่ client ส่งมา: kind, parent_id, story_points, sprint_id
     * @return array{changed: bool, changes: list<string>} ค่าที่เปลี่ยนและข้อความสำหรับบรรทัดประวัติ
     */
    public static function updateStructure(int $taskId, int $projectId, array $input): array
    {
        /** @var array{changed: bool, changes: list<string>} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($taskId, $projectId, $input): array {
            $task = DB::run(
                'SELECT kind, parent_id, story_points, sprint_id FROM project_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetch();
            if (!$task) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลงาน');
            }
            $project = DB::run(
                'SELECT point_scale, key_prefix FROM projects WHERE id = ?',
                [$projectId]
            )->fetch();
            if (!$project) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลโปรเจค');
            }

            $oldKind = (string)$task['kind'];
            $oldParent = $task['parent_id'] === null ? null : (int)$task['parent_id'];
            $oldPoints = $task['story_points'] === null ? null : (int)$task['story_points'];
            $oldSprint = $task['sprint_id'] === null ? null : (int)$task['sprint_id'];
            $kindSent = array_key_exists('kind', $input);
            $parentSent = array_key_exists('parent_id', $input);
            $sprintSent = array_key_exists('sprint_id', $input);

            $kind = $kindSent ? self::checkedKind($input['kind']) : $oldKind;
            $parentId = $parentSent ? self::parentIdFromInput($input['parent_id']) : $oldParent;

            // งานแม่ต้องถูกอ่านซ้ำใน lock: คำขออื่นอาจเปลี่ยนชนิดหรือลบงานแม่ไปแล้ว
            $parent = null;
            if ($kindSent || $parentSent) {
                $parent = self::checkedParent($projectId, $taskId, $kind, $parentId);
            }

            // งานใหญ่ไม่มีแต้ม: เปลี่ยนเป็นงานใหญ่ล้างแต้มเดิม แต่แต้มที่ client ส่งมาเองยังผิดกฎ
            // แต้มที่ไม่ได้ส่งมาไม่ถูกตรวจซ้ำ: สลับสเกลแล้วแก้ชนิดงานต้องทำได้โดยแต้มเดิมยังอยู่
            $pointsSent = array_key_exists('story_points', $input);
            $points = $oldPoints;
            if ($kind === 'epic') {
                if ($pointsSent && $input['story_points'] !== null && $input['story_points'] !== '') {
                    throw new ProjectColumnException(422, 'งานใหญ่ไม่มีแต้มของตัวเอง รวมจากงานลูก');
                }
                $points = null;
            } elseif ($pointsSent) {
                $points = self::checkedPoints($input['story_points'], (string)$project['point_scale'], $kind);
            }

            // รอบงาน: งานใหญ่ไม่มีรอบ งานย่อยใช้รอบของงานแม่ ที่เหลือรับรหัสที่ตรวจแล้ว
            $sprint = $oldSprint;
            if ($kind === 'epic') {
                if ($sprintSent && !self::isBlankId($input['sprint_id'])) {
                    throw new ProjectColumnException(422, 'งานใหญ่ไม่อยู่ในรอบงาน');
                }
                $sprint = null;
            } elseif ($kind === 'sub') {
                // งานย่อย: ค่าที่ client ส่งมาตรง ๆ ถูกเมิน ใช้รอบของงานแม่เท่านั้น
                if (($kindSent || $parentSent) && $parentId !== null) {
                    $taskParent = $parent ?? self::checkedParent($projectId, $taskId, $kind, $parentId);
                    $sprint = ($taskParent !== null && $taskParent['sprint_id'] !== null)
                        ? (int)$taskParent['sprint_id']
                        : null;
                }
            } elseif ($sprintSent) {
                $sprint = self::isBlankId($input['sprint_id'])
                    ? null
                    : self::checkedSprint($projectId, $input['sprint_id']);
            }

            $kindDiffers = $kind !== $oldKind;
            // อ่านงานลูกซ้ำใน lock เดียวกับการตัดสิน: คำขออื่นอาจย้ายหรือลบลูกไปแล้ว
            $childKinds = [];
            if ($kindSent || $parentSent) {
                $childKinds = array_map(
                    static fn(mixed $childKind): string => (string)$childKind,
                    DB::run('SELECT kind FROM project_tasks WHERE parent_id = ?', [$taskId])->fetchAll(PDO::FETCH_COLUMN)
                );
            }
            if ($kindDiffers) {
                // งานลูกที่ค้างอยู่ต้องยังอยู่ใต้ชนิดใหม่ได้ ไม่งั้นให้ย้ายลูกออกก่อน
                $hasSub = in_array('sub', $childKinds, true);
                $hasOther = array_intersect($childKinds, ['story', 'task', 'bug']) !== [];
                if ($kind === 'epic' ? $hasSub : ($hasOther || ($kind === 'sub' && $childKinds !== []))) {
                    throw new ProjectColumnException(422, 'ย้ายงานลูกออกก่อนเปลี่ยนชนิดงาน');
                }
            }

            $changes = [];
            if ($kindDiffers) {
                $changes[] = "ชนิด เปลี่ยนเป็น '" . self::KIND_LABELS[$kind] . "'";
            }
            if ($points !== $oldPoints) {
                $changes[] = "แต้ม เปลี่ยนเป็น '" . ($points === null ? 'ว่าง' : (string)$points) . "'";
            }
            if ($parentId !== $oldParent) {
                if ($parentId !== null && $parent === null) {
                    // ไม่ควรเกิด: parentId เปลี่ยนได้เฉพาะเมื่อตรวจงานแม่แล้ว
                    throw new ProjectColumnException(422, 'ไม่พบงานแม่ในโปรเจคนี้');
                }
                $label = 'ไม่มี';
                if ($parentId !== null) {
                    $prefix = (string)($project['key_prefix'] ?? '');
                    $label = ($prefix !== '' ? $prefix : 'PRJ') . '-' . (int)$parent['number'];
                }
                $changes[] = "งานแม่ เปลี่ยนเป็น '" . $label . "'";
            }
            if ($sprint !== $oldSprint) {
                $changes[] = "รอบงาน เปลี่ยนเป็น '" . self::sprintLabel($projectId, $sprint) . "'";
            }

            if ($changes !== []) {
                DB::run(
                    'UPDATE project_tasks SET kind = ?, parent_id = ?, story_points = ?, sprint_id = ? WHERE id = ? AND project_id = ?',
                    [$kind, $parentId, $points, $sprint, $taskId, $projectId]
                );
            }
            // งานย่อยต้องตามงานแม่ไปรอบเดียวกันเสมอ
            if ($sprint !== $oldSprint) {
                self::syncSubSprints($projectId, [$taskId], $sprint);
            }
            return ['changed' => $changes !== [], 'changes' => $changes];
        });
        return $result;
    }

    /**
     * ตรวจชนิดงานว่าอยู่ในห้าชนิดที่ฐานข้อมูลรับ
     *
     * @param mixed $value ค่าที่ client ส่งมา
     * @return string ชนิดงานที่ถูกต้อง
     */
    private static function checkedKind(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::KINDS, true)) {
            throw new ProjectColumnException(422, 'ชนิดงานไม่ถูกต้อง');
        }
        return $value;
    }

    /**
     * แปลงค่า parent_id จาก client เป็นรหัสงาน หรือ null เมื่อสั่งล้างงานแม่
     *
     * @param mixed $value ค่าที่ส่งมา (null/'' = ล้าง, เลขจำนวนเต็ม = รหัสงานแม่)
     * @return int|null รหัสงานแม่ หรือ null
     */
    private static function parentIdFromInput(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
        return 0;
    }

    /**
     * ตรวจงานแม่ตามชนิดของงาน แล้วคืนแถวงานแม่เมื่อมี
     *
     * งานแม่ที่ไม่มี ลบไปแล้ว หรืออยู่คนละโปรเจคให้ข้อความเดียวกันเสมอ
     * เพื่อไม่ให้ client เดาได้ว่ารหัสใดมีอยู่จริง
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $taskId งานที่กำลังตรวจ (0 = ยังไม่มีงาน)
     * @param string $kind ชนิดงานของงานนี้
     * @param int|null $parentId รหัสงานแม่ (null = ไม่มี)
     * @return array<string, mixed>|null แถวงานแม่ (รวม sprint_id) หรือ null
     */
    private static function checkedParent(int $projectId, int $taskId, string $kind, ?int $parentId): ?array
    {
        if ($kind === 'epic' && $parentId !== null) {
            throw new ProjectColumnException(422, 'งานใหญ่อยู่ใต้งานอื่นไม่ได้');
        }
        if ($parentId === null) {
            if ($kind === 'sub') {
                throw new ProjectColumnException(422, 'งานย่อยต้องมีงานแม่');
            }
            return null;
        }
        if ($taskId > 0 && $parentId === $taskId) {
            throw new ProjectColumnException(422, 'งานอยู่ใต้ตัวเองไม่ได้');
        }

        $parent = DB::run(
            'SELECT id, kind, number, sprint_id FROM project_tasks WHERE id = ? AND project_id = ?',
            [$parentId, $projectId]
        )->fetch();
        if (!$parent) {
            throw new ProjectColumnException(422, 'ไม่พบงานแม่ในโปรเจคนี้');
        }
        if ($kind === 'sub') {
            if (!in_array($parent['kind'], ['story', 'task', 'bug'], true)) {
                throw new ProjectColumnException(422, 'งานย่อยต้องอยู่ใต้เรื่องผู้ใช้ งาน หรือบั๊ก');
            }
        } elseif ($parent['kind'] !== 'epic') {
            // kind epic ที่มีงานแม่ถูกกันไปแล้วด้านบน จึงเหลือ story/task/bug
            throw new ProjectColumnException(422, 'งานนี้อยู่ได้เฉพาะใต้งานใหญ่');
        }
        return $parent;
    }

    /**
     * ตรวจแต้มตามชุดของโปรเจคและชนิดงาน
     *
     * @param mixed $value ค่าที่ส่งมา (null/'' = ไม่มีแต้ม)
     * @param string $scale ชุดแต้มของโปรเจค fib/ten
     * @param string $kind ชนิดงานของงานนี้
     * @return int|null แต้มที่ถูกต้อง หรือ null
     */
    private static function checkedPoints(mixed $value, string $scale, string $kind): ?int
    {
        if ($value === null || $value === '') return null;
        if ($kind === 'epic') {
            throw new ProjectColumnException(422, 'งานใหญ่ไม่มีแต้มของตัวเอง รวมจากงานลูก');
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new ProjectColumnException(422, 'แต้มต้องเป็นค่าในชุดแต้มของโปรเจค');
        }
        $points = (int)$value;
        $allowed = self::POINT_SCALES[$scale] ?? self::POINT_SCALES['fib'];
        if (!in_array($points, $allowed, true)) {
            throw new ProjectColumnException(422, 'แต้มต้องเป็นค่าในชุดแต้มของโปรเจค');
        }
        return $points;
    }

    /**
     * หา sprint_id ของงานใหม่ตามชนิด งานแม่ และค่าที่ client ส่งมา
     *
     * งานใหญ่ไม่มีรอบงาน (ส่งรหัสมาเป็น 422) งานย่อยใช้รอบของงานแม่โดยเมินค่าที่ส่งมา
     * ส่วนงานอื่นรับเฉพาะรหัสรอบงาน planned/active ของโปรเจคนี้
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $kind ชนิดงานของงานใหม่
     * @param array<string, mixed>|null $parent แถวงานแม่ (มี sprint_id) หรือ null
     * @param array<string, mixed> $data ข้อมูลงานที่ client ส่งมา
     * @return int|null รหัสรอบงาน หรือ null สำหรับ backlog
     */
    private static function sprintForCreate(int $projectId, string $kind, ?array $parent, array $data): ?int
    {
        $sent = array_key_exists('sprint_id', $data);
        $raw = $sent ? $data['sprint_id'] : null;

        if ($kind === 'epic') {
            if ($sent && !self::isBlankId($raw)) {
                throw new ProjectColumnException(422, 'งานใหญ่ไม่อยู่ในรอบงาน');
            }
            return null;
        }
        if ($kind === 'sub') {
            return ($parent !== null && $parent['sprint_id'] !== null) ? (int)$parent['sprint_id'] : null;
        }
        if (!$sent || self::isBlankId($raw)) return null;
        return self::checkedSprint($projectId, $raw);
    }

    /**
     * ตรวจรหัสรอบงานที่ client ส่งมาว่าเป็น planned/active ของโปรเจคนี้
     *
     * รหัสที่ไม่มี อยู่คนละโปรเจค หรือปิดแล้ว ตอบ 404 "ไม่พบรอบงาน" เหมือนกันเสมอ
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return int รหัสรอบงานที่ตรวจแล้ว
     */
    private static function checkedSprint(int $projectId, mixed $raw): int
    {
        if (is_int($raw)) {
            $id = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $id = (int)$raw;
        } else {
            $id = 0;
        }
        if ($id <= 0) {
            throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
        }
        $state = DB::run(
            'SELECT state FROM project_sprints WHERE id = ? AND project_id = ?',
            [$id, $projectId]
        )->fetchColumn();
        if ($state === false || !in_array((string)$state, ProjectSprint::PLANNABLE_STATES, true)) {
            throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
        }
        return $id;
    }

    /**
     * ค่าว่างของรหัส (null หรือสตริงว่าง) หมายถึงไม่มีรอบงาน/ย้ายเข้า backlog
     *
     * @param mixed $value ค่าที่ client ส่งมา
     * @return bool true เมื่อถือว่าไม่ได้ระบุรหัส
     */
    private static function isBlankId(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * ตั้ง sprint_id ของงานย่อยทุกงานใต้ parent ที่ระบุในคำสั่งเดียว
     *
     * งานย่อยต้องอยู่รอบเดียวกับงานแม่เสมอ จึงเรียกทุกครั้งที่รอบของงานแม่เปลี่ยน
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<int, int> $parentIds รหัสงานแม่
     * @param int|null $sprintId รอบงานปลายทาง (null = backlog)
     */
    private static function syncSubSprints(int $projectId, array $parentIds, ?int $sprintId): void
    {
        if ($parentIds === []) return;
        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        DB::run(
            "UPDATE project_tasks SET sprint_id = ?
             WHERE project_id = ? AND kind = 'sub' AND parent_id IN (" . $placeholders . ')',
            array_merge([$sprintId, $projectId], array_values($parentIds))
        );
    }

    /**
     * ชื่อรอบงานสำหรับบรรทัดประวัติ ('คลังงาน' เมื่ออยู่ backlog)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int|null $sprintId รหัสรอบงาน
     * @return string ชื่อรอบงานหรือคำว่า คลังงาน
     */
    private static function sprintLabel(int $projectId, ?int $sprintId): string
    {
        if ($sprintId === null) return 'คลังงาน';
        $name = DB::run(
            'SELECT name FROM project_sprints WHERE id = ? AND project_id = ?',
            [$sprintId, $projectId]
        )->fetchColumn();
        return $name === false ? 'คลังงาน' : (string)$name;
    }

    /**
     * ย้ายงานที่เลือกเข้า/ออกรอบงานและกำหนดลำดับใน backlog ในล็อกของโปรเจค
     *
     * ตรวจรหัสงานทั้งหมดในคำสั่งเดียว: งานที่ไม่มี อยู่คนละโปรเจค หรือเป็นงานใหญ่/งานย่อย
     * ตอบ 422 ข้อความเดียวกันเพื่อไม่ให้ client เดารหัสที่มีอยู่ได้ แล้วเขียนรอบงานและ
     * backlog_rank ตามลำดับที่ส่งมา จากนั้นตั้งรอบของงานย่อยให้ตามงานแม่
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $sprintId รหัสรอบงานปลายทาง หรือ null สำหรับ backlog
     * @param array<mixed> $ids รหัสงานตามลำดับที่ต้องการ
     * @return array{moved: int, sprint_id: int|null} จำนวนงานและรอบงานปลายทาง
     */
    public static function plan(int $projectId, mixed $sprintId, array $ids): array
    {
        if (count($ids) > self::MAX_PLAN_TASKS) {
            throw new ProjectColumnException(422, 'วางแผนได้ครั้งละไม่เกิน ' . self::MAX_PLAN_TASKS . ' งาน');
        }

        $ordered = [];
        foreach ($ids as $raw) {
            if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                throw new ProjectColumnException(422, 'วางแผนได้เฉพาะเรื่องผู้ใช้ งาน และบั๊ก');
            }
            $taskId = (int)$raw;
            if ($taskId > 0 && !in_array($taskId, $ordered, true)) {
                $ordered[] = $taskId;
            }
        }

        /** @var array{moved: int, sprint_id: int|null} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $sprintId, $ordered): array {
            $target = null;
            if ($sprintId !== null && $sprintId !== '') {
                $target = self::checkedSprint($projectId, $sprintId);
            }

            if ($ordered !== []) {
                $placeholders = implode(',', array_fill(0, count($ordered), '?'));
                $rows = DB::run(
                    'SELECT id, kind FROM project_tasks
                     WHERE project_id = ? AND id IN (' . $placeholders . ')',
                    array_merge([$projectId], $ordered)
                )->fetchAll();

                $kinds = [];
                foreach ($rows as $row) {
                    $kinds[(int)$row['id']] = (string)$row['kind'];
                }
                foreach ($ordered as $taskId) {
                    if (!isset($kinds[$taskId]) || !in_array($kinds[$taskId], ['story', 'task', 'bug'], true)) {
                        throw new ProjectColumnException(422, 'วางแผนได้เฉพาะเรื่องผู้ใช้ งาน และบั๊ก');
                    }
                }

                $values = [];
                $params = [];
                foreach ($ordered as $index => $taskId) {
                    $values[] = 'SELECT ? AS id, ? AS rank';
                    array_push($params, $taskId, $index);
                }
                $params[] = $target;
                $params[] = $projectId;
                DB::run(
                    'UPDATE project_tasks t
                     JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = t.id
                     SET t.sprint_id = ?, t.backlog_rank = v.rank
                     WHERE t.project_id = ?',
                    $params
                );
                self::syncSubSprints($projectId, $ordered, $target);
            }

            return ['moved' => count($ordered), 'sprint_id' => $target];
        });
        return $result;
    }

    /**
     * รวมค่าช่องข้อมูลที่เปลี่ยนเข้ากับค่าเดิมของงานในคำสั่งเดียว (JSON_MERGE_PATCH)
     *
     * คีย์ที่เป็น null ถูกลบ คีย์อื่นถูกใส่หรือแทน ช่องที่ไม่อยู่ในแพตช์ไม่ถูกแตะ
     * สองคนแก้คนละช่องพร้อมกันจึงไม่เขียนทับค่าของกันและกัน
     *
     * @param int $taskId รหัสงาน
     * @param array<string, mixed> $patch แพตช์ที่ตรวจแล้ว คีย์เป็นรหัสช่อง
     */
    public static function patchCustom(int $taskId, array $patch): void
    {
        DB::run(
            "UPDATE project_tasks SET custom = JSON_MERGE_PATCH(COALESCE(custom, '{}'), ?) WHERE id = ?",
            [json_encode((object)$patch, JSON_UNESCAPED_UNICODE), $taskId]
        );
    }

    /**
     * ลบงานพร้อมงานย่อยชนิด sub ของมัน ในล็อกโปรเจคเดียวกับที่อ่านรายการลูก
     *
     * งานย่อยที่ไม่มีงานแม่ไม่ได้ตามกฎ จึงลบตามไปด้วย ส่วนงานลูกของงานใหญ่
     * (story/task/bug) เก็บไว้และ FK จะตั้ง parent_id เป็น NULL ให้เอง
     *
     * @param int $id รหัสงาน
     * @param int $projectId รหัสโปรเจคของงาน
     * @return array{deleted: bool, subtasks: int} ผลการลบและจำนวนงานย่อยที่ลบตาม
     */
    public static function delete(int $id, int $projectId): array
    {
        /** @var array{deleted: bool, subtasks: int} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($id, $projectId): array {
            // อ่านซ้ำใน lock: อีกคำขออาจลบงานนี้ไปก่อนแล้ว (EDGE-P4 ต้องได้ 404)
            if (DB::run('SELECT id FROM project_tasks WHERE id = ? AND project_id = ?', [$id, $projectId])->fetchColumn() === false) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลงาน');
            }

            $subIds = DB::run(
                "SELECT id FROM project_tasks WHERE parent_id = ? AND project_id = ? AND kind = 'sub'",
                [$id, $projectId]
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($subIds !== []) {
                $placeholders = implode(',', array_fill(0, count($subIds), '?'));
                DB::run(
                    'DELETE FROM project_tasks WHERE id IN (' . $placeholders . ') AND project_id = ?',
                    array_merge($subIds, [$projectId])
                );
            }

            DB::run('DELETE FROM project_tasks WHERE id = ? AND project_id = ?', [$id, $projectId]);
            return ['deleted' => true, 'subtasks' => count($subIds)];
        });
        return $result;
    }

    /**
     * ย้ายงานไปต่อท้ายคอลัมน์ที่ระบุ พร้อมเขียนสถานะเงาให้ตรงกลุ่ม
     *
     * @param int $taskId รหัสงาน
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $column คอลัมน์ปลายทางที่ตรวจแล้ว (ต้องมี id)
     */
    public static function moveTo(int $taskId, int $projectId, array $column): void
    {
        $columnId = (int)$column['id'];
        ProjectColumn::locked($projectId, static function () use ($taskId, $projectId, $columnId): void {
            // คอลัมน์ปลายทางต้องยังอยู่ในโปรเจคนี้ตอนได้ lock
            if (!ProjectColumn::findInProject($projectId, $columnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $maxPos = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks
                 WHERE project_id = ? AND column_id = ? AND id <> ?',
                [$projectId, $columnId, $taskId]
            )->fetchColumn();

            DB::run(
                'UPDATE project_tasks t
                 JOIN project_columns c ON c.id = ? AND c.project_id = t.project_id
                 SET t.column_id = c.id, t.position = ?, t.status = ' . self::shadowStatusSql() . '
                 WHERE t.id = ? AND t.project_id = ?',
                [$columnId, $maxPos + 1, $taskId, $projectId]
            );
        });
    }

    /**
     * เรียงลำดับตำแหน่งและคอลัมน์ของงานใหม่จากการลากวาง ในคำสั่ง UPDATE เดียว
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<int, array<string, mixed>> $items รายการ [{id, column_id, position}]
     */
    public static function reorder(int $projectId, array $items): void
    {
        if ($items === []) return;

        ProjectColumn::locked($projectId, static function () use ($projectId, $items): void {
            // อ่านคอลัมน์ของโปรเจคซ้ำหลังได้ lock: รายการที่ client ถือมาอาจชี้
            // คอลัมน์ที่เพิ่งถูกลบไปแล้ว
            $validColumns = [];
            foreach (ProjectColumn::listForProject($projectId) as $column) {
                $validColumns[(int)$column['id']] = true;
            }
            foreach ($items as $item) {
                if (!isset($validColumns[(int)$item['column_id']])) {
                    throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
                }
            }

            $values = [];
            $params = [];
            foreach ($items as $item) {
                $values[] = 'SELECT ? AS id, ? AS column_id, ? AS position';
                array_push($params, (int)$item['id'], (int)$item['column_id'], (int)$item['position']);
            }
            $params[] = $projectId;

            DB::run(
                'UPDATE project_tasks t
                 JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = t.id
                 JOIN project_columns c ON c.id = v.column_id AND c.project_id = t.project_id
                 SET t.column_id = v.column_id, t.position = v.position, t.status = ' . self::shadowStatusSql() . '
                 WHERE t.project_id = ?',
                $params
            );
        });
    }

    /**
     * ย้ายงานทุกงานของคอลัมน์หนึ่งไปต่อท้ายอีกคอลัมน์ โดยคงลำดับเดิม
     * และไม่แตะเวลาแก้ไขล่าสุดของผู้ใช้ (ใช้ตอนลบคอลัมน์)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $fromColumnId คอลัมน์ต้นทาง
     * @param int $toColumnId คอลัมน์ปลายทาง
     */
    public static function moveColumnTasks(int $projectId, int $fromColumnId, int $toColumnId): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $fromColumnId, $toColumnId): void {
            // ทั้งต้นทางและปลายทางต้องเป็นคอลัมน์ของโปรเจคนี้ตอนได้ lock
            if (!ProjectColumn::findInProject($projectId, $fromColumnId)
                || !ProjectColumn::findInProject($projectId, $toColumnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $taskIds = DB::run(
                'SELECT id FROM project_tasks WHERE project_id = ? AND column_id = ? ORDER BY position, id',
                [$projectId, $fromColumnId]
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($taskIds === []) return;

            $base = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks WHERE project_id = ? AND column_id = ?',
                [$projectId, $toColumnId]
            )->fetchColumn();

            $values = [];
            $params = [];
            foreach ($taskIds as $index => $taskId) {
                $values[] = 'SELECT ? AS id, ? AS position';
                array_push($params, (int)$taskId, $base + 1 + $index);
            }
            $params[] = $toColumnId;
            $params[] = $projectId;

            DB::run(
                'UPDATE project_tasks t
                 JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = t.id
                 JOIN project_columns c ON c.id = ? AND c.project_id = t.project_id
                 SET t.column_id = c.id, t.position = v.position, t.status = ' . self::shadowStatusSql() . ', t.updated_at = t.updated_at
                 WHERE t.project_id = ?',
                $params
            );
        });
    }

    /**
     * คำนวณสถานะเงาใหม่ให้งานทุกงานของโปรเจค (ใช้หลังเพิ่ม/ย้าย/เรียง/ลบคอลัมน์
     * เพราะคอลัมน์ doing สุดท้ายอาจเปลี่ยน) ไม่แตะเวลาแก้ไขล่าสุดของผู้ใช้
     *
     * @param int $projectId รหัสโปรเจค
     */
    public static function syncProjectShadow(int $projectId): void
    {
        DB::run(
            'UPDATE project_tasks t
             JOIN project_columns c ON c.id = t.column_id
             SET t.status = ' . self::shadowStatusSql() . ', t.updated_at = t.updated_at
             WHERE t.project_id = ?',
            [$projectId]
        );
    }

    /**
     * นับจำนวนงานในคอลัมน์ (ใช้ตัดสินว่าต้องเลือกคอลัมน์ปลายทางตอนลบหรือไม่)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @return int จำนวนงาน
     */
    public static function countInColumn(int $projectId, int $columnId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM project_tasks WHERE project_id = ? AND column_id = ?',
            [$projectId, $columnId]
        )->fetchColumn();
    }

    /**
     * นิพจน์ SQL เดียวที่เขียนสถานะเงา (c = project_columns)
     *
     * done → Done, todo → To Do, doing → Review เฉพาะคอลัมน์ doing สุดท้าย
     * (position มากสุดแล้ว id มากสุด) ของโปรเจค นอกนั้นเป็น In Progress
     *
     * @return string นิพจน์ CASE
     */
    private static function shadowStatusSql(): string
    {
        return "CASE c.grp WHEN 'done' THEN 'Done' WHEN 'todo' THEN 'To Do' ELSE IF(c.id = (SELECT d.id FROM project_columns d WHERE d.project_id = c.project_id AND d.grp = 'doing' ORDER BY d.position DESC, d.id DESC LIMIT 1), 'Review', 'In Progress') END";
    }
}
