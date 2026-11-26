<?php
// =====================================================
// models/ProjectSprint.php — รอบงาน (sprint) ของโปรเจค
//
// ทุกคำสั่งที่เปลี่ยนสถานะรอบงานหรือย้ายงานระหว่างรอบ ถือ lock ของโปรเจค
// แล้วอ่านค่าที่ตัดสินใจซ้ำใน transaction เดียวกัน คำขอที่แย่งกันจึงไม่ทำให้
// มีรอบ active สองรอบ หรือย้ายงานค้างไปปลายทางที่ถูกลบไปแล้ว
// =====================================================

class ProjectSprint
{
    /** ความยาวชื่อรอบงานสูงสุด (ตรงกับ VARCHAR(60)) */
    public const MAX_NAME = 60;

    /** ความยาวเป้าหมายรอบงานสูงสุด (ตรงกับ VARCHAR(255)) */
    public const MAX_GOAL = 255;

    /** สถานะของรอบงานที่วางแผนงานเข้าได้ (ปิดแล้ววางไม่ได้) */
    public const PLANNABLE_STATES = ['planned', 'active'];

    /**
     * ดึงรอบงานทั้งหมดของโปรเจค เรียง active ก่อน planned ตามวันเริ่ม/id แล้ว closed ใหม่สุดก่อน
     *
     * @param int $projectId รหัสโปรเจค
     * @return array<int, array<string, mixed>> แถวรอบงาน
     */
    public static function listForProject(int $projectId): array
    {
        return DB::run(
            "SELECT * FROM project_sprints WHERE project_id = ?
             ORDER BY FIELD(state, 'active', 'planned', 'closed'),
                      CASE WHEN state = 'planned' THEN start_date END ASC,
                      CASE WHEN state = 'planned' THEN id END ASC,
                      CASE WHEN state = 'closed' THEN closed_at END DESC,
                      id ASC",
            [$projectId]
        )->fetchAll();
    }

    /**
     * หารอบงานตามรหัส โดยยังไม่ตรวจสิทธิ์ของโปรเจค
     *
     * @param int $id รหัสรอบงาน
     * @return array<string, mixed>|null แถวรอบงาน หรือ null เมื่อไม่พบ
     */
    public static function find(int $id): ?array
    {
        return DB::run('SELECT * FROM project_sprints WHERE id = ?', [$id])->fetch() ?: null;
    }

    /**
     * หารอบงานที่อยู่ในโปรเจคที่ระบุ
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสรอบงาน
     * @return array<string, mixed>|null แถวรอบงาน หรือ null เมื่อไม่ใช่ของโปรเจคนี้
     */
    public static function findForProject(int $projectId, int $id): ?array
    {
        return DB::run(
            'SELECT * FROM project_sprints WHERE id = ? AND project_id = ?',
            [$id, $projectId]
        )->fetch() ?: null;
    }

    /**
     * สร้างรอบงานใหม่ในสถานะ planned
     *
     * ชื่อว่างกลายเป็น "รอบ N" โดย N คือจำนวนรอบงานในโปรเจค + 1 พร้อมตรวจวัน
     * ให้วันจบไม่ก่อนวันเริ่ม ทุกอย่างทำใน lock เดียวกับการนับ N จึงไม่มีคำขอ
     * อื่นสร้างชื่อซ้ำระหว่างตรวจกับเขียน
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $data ข้อมูลรอบงาน (name, goal, start_date, end_date)
     * @return array{id: int, name: string} รหัสและชื่อรอบงานใหม่
     */
    public static function create(int $projectId, array $data): array
    {
        /** @var array{id: int, name: string} $created */
        $created = ProjectColumn::locked($projectId, static function () use ($projectId, $data): array {
            $name = self::normalizeName($data['name'] ?? '');
            if ($name === '') {
                $name = 'รอบ ' . self::nextNumber($projectId);
            } else {
                self::assertName($name);
            }
            $goal = self::normalizeGoal($data['goal'] ?? null);
            $start = self::checkedDate($data['start_date'] ?? null, 'วันเริ่ม');
            $end = self::checkedDate($data['end_date'] ?? null, 'วันจบ');
            self::assertDates($start, $end);

            DB::run(
                "INSERT INTO project_sprints (project_id, name, goal, start_date, end_date, state)
                 VALUES (?, ?, ?, ?, ?, 'planned')",
                [$projectId, $name, $goal, $start, $end]
            );
            return ['id' => (int)DB::conn()->lastInsertId(), 'name' => $name];
        });
        return $created;
    }

    /**
     * แก้ชื่อ เป้าหมาย และวันของรอบงาน (รอบที่ปิดแล้วแก้ไม่ได้)
     *
     * รับเฉพาะคีย์ที่ client ส่งมา ค่าที่ไม่ได้ส่งไม่ถูกแตะ และตรวจวันจบ/วันเริ่ม
     * จากค่าที่จะมีผลจริงหลังแก้
     *
     * @param int $id รหัสรอบงาน
     * @param array<string, mixed> $data ข้อมูลที่จะแก้ (name, goal, start_date, end_date)
     * @return array{name: string} ชื่อล่าสุดของรอบงาน
     */
    public static function update(int $id, array $data): array
    {
        $row = self::find($id);
        if (!$row) {
            throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
        }
        $projectId = (int)$row['project_id'];

        /** @var array{name: string} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id, $data): array {
            // อ่านซ้ำใน lock: คำขออื่นอาจลบหรือปิดรอบงานไปแล้ว
            $sprint = self::findForProject($projectId, $id);
            if (!$sprint) {
                throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
            }
            if ((string)$sprint['state'] === 'closed') {
                throw new ProjectColumnException(422, 'รอบที่ปิดแล้วแก้ไม่ได้');
            }

            $fields = [];
            $params = [];

            $name = (string)$sprint['name'];
            if (array_key_exists('name', $data)) {
                $name = self::normalizeName($data['name']);
                self::assertName($name);
                $fields[] = 'name = ?';
                $params[] = $name;
            }
            if (array_key_exists('goal', $data)) {
                $fields[] = 'goal = ?';
                $params[] = self::normalizeGoal($data['goal']);
            }

            $startSent = array_key_exists('start_date', $data);
            $endSent = array_key_exists('end_date', $data);
            $start = $startSent
                ? self::checkedDate($data['start_date'], 'วันเริ่ม')
                : ($sprint['start_date'] === null ? null : (string)$sprint['start_date']);
            $end = $endSent
                ? self::checkedDate($data['end_date'], 'วันจบ')
                : ($sprint['end_date'] === null ? null : (string)$sprint['end_date']);
            if ($startSent || $endSent) {
                self::assertDates($start, $end);
            }
            if ($startSent) {
                $fields[] = 'start_date = ?';
                $params[] = $start;
            }
            if ($endSent) {
                $fields[] = 'end_date = ?';
                $params[] = $end;
            }

            if ($fields !== []) {
                $params[] = $id;
                $params[] = $projectId;
                DB::run(
                    'UPDATE project_sprints SET ' . implode(', ', $fields) . ' WHERE id = ? AND project_id = ?',
                    $params
                );
            }
            return ['name' => $name];
        });
        return $result;
    }

    /**
     * เริ่มรอบงาน: ต้องมีวันเริ่มและวันจบ และต้องไม่มีรอบอื่น active อยู่
     *
     * committed_points คือผลรวมแต้มของงานในรอบ (ไม่นับงานใหญ่) ที่อ่านใน lock
     * เดียวกัน หากฐานข้อมูลยังเจอการชนของ unique active (คำขออื่นแซงเข้ามา)
     * จะแปลงเป็น 422 เดียวกับที่ตรวจพบก่อนเขียน ไม่ปล่อยเป็น 500
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสรอบงาน
     * @return array{name: string} ชื่อรอบงานที่เริ่ม
     */
    public static function start(int $projectId, int $id): array
    {
        /** @var array{name: string} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id): array {
            $sprint = self::findForProject($projectId, $id);
            if (!$sprint) {
                throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
            }
            if ((string)$sprint['state'] !== 'planned') {
                throw new ProjectColumnException(422, 'เริ่มได้เฉพาะรอบที่ยังไม่เริ่ม');
            }
            if ($sprint['start_date'] === null || $sprint['end_date'] === null) {
                throw new ProjectColumnException(422, 'ตั้งวันเริ่มและวันจบก่อนเริ่มรอบ');
            }

            $active = DB::run(
                "SELECT id FROM project_sprints WHERE project_id = ? AND state = 'active' LIMIT 1",
                [$projectId]
            )->fetchColumn();
            if ($active !== false) {
                throw new ProjectColumnException(422, 'ปิดรอบที่กำลังทำก่อน จึงเริ่มรอบใหม่ได้');
            }

            $committed = (int)DB::run(
                "SELECT COALESCE(SUM(story_points), 0) FROM project_tasks
                 WHERE project_id = ? AND sprint_id = ? AND kind <> 'epic'",
                [$projectId, $id]
            )->fetchColumn();

            try {
                DB::run(
                    "UPDATE project_sprints
                     SET state = 'active', started_at = NOW(), committed_points = ?
                     WHERE id = ? AND project_id = ?",
                    [$committed, $id, $projectId]
                );
            } catch (PDOException $e) {
                // unique(uq_psprints_active) ยังเป็นด่านสุดท้าย: สองคำขอที่หลุดการตรวจมาพร้อมกัน
                // ต้องได้ 422 ไม่ใช่ 500 และต้องมีรอบ active เพียงรอบเดียว
                if ((string)$e->getCode() === '23000') {
                    throw new ProjectColumnException(422, 'ปิดรอบที่กำลังทำก่อน จึงเริ่มรอบใหม่ได้');
                }
                throw $e;
            }
            return ['name' => (string)$sprint['name']];
        });
        return $result;
    }

    /**
     * ปิดรอบงานที่กำลังทำ พร้อมย้ายงานที่ยังไม่เสร็จไปยังปลายทางที่เลือก
     *
     * งานที่เสร็จแล้ว (คอลัมน์กลุ่ม done) อยู่กับรอบที่ปิด ส่วนงานที่ยังไม่เสร็จ
     * และงานย่อยของมันย้ายไป backlog ('backlog'), รอบใหม่ ('new') หรือรอบ planned
     * ที่ระบุ งานใหญ่ไม่ถูกนับและไม่ถูกย้าย
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสรอบงานที่จะปิด
     * @param mixed $carry ปลายทางของงานค้าง: 'backlog', 'new' หรือรหัสรอบ planned
     * @return array{moved: int, target_sprint_id: int|null} จำนวนงานที่ย้ายและรหัสรอบปลายทาง
     */
    public static function close(int $projectId, int $id, mixed $carry): array
    {
        /** @var array{moved: int, target_sprint_id: int|null} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id, $carry): array {
            $sprint = self::findForProject($projectId, $id);
            if (!$sprint) {
                throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
            }
            if ((string)$sprint['state'] !== 'active') {
                throw new ProjectColumnException(422, 'ปิดได้เฉพาะรอบที่กำลังทำ');
            }

            $target = self::carryTarget($projectId, $carry);

            $tasks = DB::run(
                "SELECT t.id, t.kind, t.parent_id, t.story_points,
                        (c.grp = 'done') AS is_done
                 FROM project_tasks t
                 LEFT JOIN project_columns c ON c.id = t.column_id
                 WHERE t.project_id = ? AND t.sprint_id = ?",
                [$projectId, $id]
            )->fetchAll();

            $moving = [];
            $parents = [];
            $completedPoints = 0;
            foreach ($tasks as $task) {
                $kind = (string)$task['kind'];
                if ($kind === 'epic') continue;
                $isDone = (int)($task['is_done'] ?? 0) === 1;
                if ($isDone) {
                    $completedPoints += (int)($task['story_points'] ?? 0);
                } elseif ($kind !== 'sub') {
                    $moving[(int)$task['id']] = true;
                    $parents[(int)$task['id']] = true;
                }
            }
            // งานย่อยต้องไปกับงานแม่เสมอ แม้ตัวมันเองจะถูกย้ายสถานะเป็น done แล้ว
            if ($parents !== []) {
                foreach ($tasks as $task) {
                    if ((string)$task['kind'] !== 'sub') continue;
                    $parentId = $task['parent_id'] === null ? 0 : (int)$task['parent_id'];
                    if (isset($parents[$parentId])) {
                        $moving[(int)$task['id']] = true;
                    }
                }
            }

            $targetId = null;
            if ($target === 'new') {
                // ใช้ชื่อ "รอบ N" ชุดเดียวกับการสร้างรอบงานปกติ
                $created = self::create($projectId, []);
                $targetId = $created['id'];
            } elseif (is_int($target)) {
                $targetId = $target;
            }

            if ($moving !== []) {
                $ids = array_keys($moving);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                DB::run(
                    'UPDATE project_tasks SET sprint_id = ?
                     WHERE project_id = ? AND id IN (' . $placeholders . ')',
                    array_merge([$targetId, $projectId], $ids)
                );
            }

            DB::run(
                "UPDATE project_sprints
                 SET state = 'closed', closed_at = NOW(), completed_points = ?
                 WHERE id = ? AND project_id = ?",
                [$completedPoints, $id, $projectId]
            );

            return ['moved' => count($moving), 'target_sprint_id' => $targetId];
        });
        return $result;
    }

    /**
     * บันทึกยอดรวมของรอบ active สำหรับวันนี้ (ใช้ใน burndown)
     *
     * อ่านผลรวมแต้มของงานในรอบ (ไม่นับงานใหญ่) และแต้มของงานที่อยู่คอลัมน์
     * กลุ่ม done จากงานจริงในคำสั่งเดียว แล้ว upsert แถวของวันนี้
     * "วันนี้" มาจาก date() ของ PHP (เขตเวลาของแอป) ไม่ใช่ CURDATE() ของฐานข้อมูล
     * โปรเจคที่ไม่มีรอบ active จะไม่เขียนอะไรเลย
     *
     * @param int $projectId รหัสโปรเจค
     */
    public static function recordDay(int $projectId): void
    {
        DB::run(
            "INSERT INTO project_sprint_days (sprint_id, `day`, scope_points, done_points)
             SELECT s.id, ?, COALESCE(SUM(t.story_points), 0),
                    COALESCE(SUM(CASE WHEN c.grp = 'done' THEN t.story_points END), 0)
             FROM project_sprints s
             LEFT JOIN project_tasks t
                    ON t.project_id = s.project_id AND t.sprint_id = s.id AND t.kind <> 'epic'
             LEFT JOIN project_columns c ON c.id = t.column_id
             WHERE s.project_id = ? AND s.state = 'active'
             GROUP BY s.id
             ON DUPLICATE KEY UPDATE scope_points = VALUES(scope_points), done_points = VALUES(done_points)",
            [date('Y-m-d'), $projectId]
        );
    }

    /**
     * สร้างข้อมูล burndown ของรอบงานหนึ่ง
     *
     * ช่วงวันเริ่มที่วันแรกของแถวบันทึกหากน้อยกว่า start_date (หรือ DATE(started_at))
     * และจบที่วันสุดท้ายของแถวบันทึกหากมากกว่า end_date (หรือ DATE(closed_at) หรือวันนี้)
     * แล้วตัดไม่ให้เกินวันนี้ แต่ละวันใช้แถวที่บันทึกไว้ล่าสุดซึ่ง day <= วันนั้น
     * วันก่อนแถวแรกใช้ค่าแถวแรก รอบที่ยังไม่มีแถวบันทึก (ยังไม่เริ่ม) คืน days ว่าง
     * อ่านแถวทั้งหมดในคำสั่งเดียวแล้วเติมวันใน PHP
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $sprintId รหัสรอบงาน
     * @return array{sprint: array<string, mixed>, days: list<array{day: string, scope: int, done: int, remaining: int}>}
     */
    public static function burndown(int $projectId, int $sprintId): array
    {
        $sprint = DB::run(
            'SELECT id, name, state, start_date, end_date, started_at, closed_at
             FROM project_sprints WHERE id = ? AND project_id = ?',
            [$sprintId, $projectId]
        )->fetch();
        if (!$sprint) {
            throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
        }

        $rows = DB::run(
            'SELECT `day`, scope_points, done_points
             FROM project_sprint_days WHERE sprint_id = ? ORDER BY `day` ASC',
            [$sprintId]
        )->fetchAll();

        $days = [];
        if ($rows !== []) {
            $firstRowDay = (string)$rows[0]['day'];
            $lastRowDay = (string)$rows[count($rows) - 1]['day'];
            $startBase = $sprint['start_date'] !== null
                ? (string)$sprint['start_date']
                : ($sprint['started_at'] !== null ? substr((string)$sprint['started_at'], 0, 10) : $firstRowDay);
            // เริ่มก่อนวันเริ่มหรือเลยวันจบยังต้องเห็นวันที่มีแถวจริง
            $start = min($startBase, $firstRowDay);
            $endBase = $sprint['end_date'] !== null
                ? (string)$sprint['end_date']
                : ($sprint['closed_at'] !== null ? substr((string)$sprint['closed_at'], 0, 10) : date('Y-m-d'));
            $end = max($endBase, $lastRowDay);
            $today = date('Y-m-d');
            if ($end > $today) {
                $end = $today;
            }

            $cursor = 0;
            $count = count($rows);
            $date = new DateTimeImmutable($start);
            $last = new DateTimeImmutable($end);
            while ($date <= $last) {
                $day = $date->format('Y-m-d');
                // แถวล่าสุดที่ day <= วันนี้ (ก่อนแถวแรกยังใช้แถวแรก)
                while ($cursor + 1 < $count && (string)$rows[$cursor + 1]['day'] <= $day) {
                    $cursor++;
                }
                $scope = (int)$rows[$cursor]['scope_points'];
                $done = (int)$rows[$cursor]['done_points'];
                $days[] = ['day' => $day, 'scope' => $scope, 'done' => $done, 'remaining' => $scope - $done];
                $date = $date->modify('+1 day');
            }
        }

        return [
            'sprint' => [
                'id' => (int)$sprint['id'],
                'name' => (string)$sprint['name'],
                'state' => (string)$sprint['state'],
                'start_date' => $sprint['start_date'] === null ? null : (string)$sprint['start_date'],
                'end_date' => $sprint['end_date'] === null ? null : (string)$sprint['end_date'],
            ],
            'days' => $days,
        ];
    }

    /**
     * ค่า velocity ของโปรเจคจากห้ารอบที่ปิดล่าสุด
     *
     * คืนรอบที่ปิดแล้วเรียงเก่าไปใหม่ (ตาม closed_at แล้ว id) พร้อม committed/completed
     * (ค่าว่างเป็น 0) และค่าเฉลี่ย completed ของสามรอบล่าสุด แบบปัดเศษ
     * average เป็น null เมื่อยังไม่มีรอบที่ปิด
     *
     * @param int $projectId รหัสโปรเจค
     * @return array{sprints: list<array{id: int, name: string, committed: int, completed: int}>, average: int|null}
     */
    public static function velocity(int $projectId): array
    {
        $rows = DB::run(
            "SELECT id, name, committed_points, completed_points
             FROM project_sprints
             WHERE project_id = ? AND state = 'closed'
             ORDER BY closed_at DESC, id DESC
             LIMIT 5",
            [$projectId]
        )->fetchAll();

        $sprints = [];
        $completed = [];
        foreach (array_reverse($rows) as $row) {
            $sprints[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'committed' => (int)($row['committed_points'] ?? 0),
                'completed' => (int)($row['completed_points'] ?? 0),
            ];
            $completed[] = (int)($row['completed_points'] ?? 0);
        }

        $lastThree = array_slice($completed, -3);
        $average = $lastThree === [] ? null : (int)round(array_sum($lastThree) / count($lastThree));

        return ['sprints' => $sprints, 'average' => $average];
    }

    /**
     * ลบรอบงานที่ยังไม่เริ่ม งานในรอบกลับไปอยู่ backlog ผ่าน FK ON DELETE SET NULL
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสรอบงาน
     * @return array{name: string} ชื่อรอบงานที่ลบ
     */
    public static function delete(int $projectId, int $id): array
    {
        /** @var array{name: string} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id): array {
            $sprint = self::findForProject($projectId, $id);
            if (!$sprint) {
                throw new ProjectColumnException(404, 'ไม่พบรอบงาน');
            }
            if ((string)$sprint['state'] !== 'planned') {
                throw new ProjectColumnException(422, 'ลบได้เฉพาะรอบที่ยังไม่เริ่ม');
            }
            DB::run('DELETE FROM project_sprints WHERE id = ? AND project_id = ?', [$id, $projectId]);
            return ['name' => (string)$sprint['name']];
        });
        return $result;
    }

    /**
     * แปลงค่าปลายทางของงานค้างเป็น 'new', รหัสรอบ planned หรือ null สำหรับ backlog
     *
     * รหัสที่ไม่มี อยู่คนละโปรเจค หรือไม่ใช่ planned ถือว่าเลือกไม่ถูกต้องเสมอ
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $carry ค่าที่ client ส่งมา
     * @return int|string|null ปลายทางที่ตรวจแล้ว
     */
    private static function carryTarget(int $projectId, mixed $carry): int|string|null
    {
        if (is_string($carry)) {
            $carry = trim($carry);
        }
        if ($carry === 'backlog') return null;
        if ($carry === 'new') return 'new';

        $id = is_int($carry) ? $carry : (is_string($carry) && ctype_digit($carry) ? (int)$carry : 0);
        if ($id <= 0) {
            throw new ProjectColumnException(422, 'เลือกที่ย้ายงานค้างไม่ถูกต้อง');
        }
        $found = DB::run(
            "SELECT id FROM project_sprints WHERE id = ? AND project_id = ? AND state = 'planned'",
            [$id, $projectId]
        )->fetchColumn();
        if ($found === false) {
            throw new ProjectColumnException(422, 'เลือกที่ย้ายงานค้างไม่ถูกต้อง');
        }
        return (int)$found;
    }

    /**
     * หมายเลขรอบถัดไปของโปรเจค (จำนวนรอบงานปัจจุบัน + 1)
     *
     * @param int $projectId รหัสโปรเจค
     * @return int หมายเลขสำหรับชื่อ "รอบ N"
     */
    private static function nextNumber(int $projectId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM project_sprints WHERE project_id = ?',
            [$projectId]
        )->fetchColumn() + 1;
    }

    /**
     * แปลงชื่อรอบงาน: NBSP เป็นช่องว่างแล้วตัดช่องว่างหัวท้าย
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string ชื่อที่พร้อมตรวจความยาว
     */
    private static function normalizeName(mixed $raw): string
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return '';
        }
        return trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    }

    /**
     * ตรวจชื่อรอบงาน (1-60 ตัวอักษร)
     *
     * @param string $name ชื่อที่แปลงแล้ว
     */
    private static function assertName(string $name): void
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME) {
            throw new ProjectColumnException(422, 'ชื่อรอบงานต้องมีความยาว 1-' . self::MAX_NAME . ' ตัวอักษร');
        }
    }

    /**
     * ตัดช่องว่างของเป้าหมาย และแปลงค่าว่างเป็น null
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string|null เป้าหมายที่ตรวจแล้ว
     */
    private static function normalizeGoal(mixed $raw): ?string
    {
        if ($raw === null) return null;
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) return null;
        $goal = trim((string)$raw);
        if ($goal === '') return null;
        if (mb_strlen($goal) > self::MAX_GOAL) {
            throw new ProjectColumnException(422, 'เป้าหมายยาวได้ไม่เกิน ' . self::MAX_GOAL . ' ตัวอักษร');
        }
        return $goal;
    }

    /**
     * ตรวจวันที่รูปแบบ Y-m-d (ค่าว่าง/null คือไม่มีวันที่)
     *
     * @param mixed $value ค่าที่ client ส่งมา
     * @param string $label ชื่อฟิลด์สำหรับข้อความผิดพลาด
     * @return string|null วันที่ที่ถูกต้อง หรือ null
     */
    private static function checkedDate(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) {
            throw new ProjectColumnException(422, $label . 'ไม่ถูกต้อง');
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new ProjectColumnException(422, $label . 'ไม่ถูกต้อง');
        }
        return $value;
    }

    /**
     * ตรวจว่าวันจบไม่ก่อนวันเริ่มเมื่อมีทั้งสองค่า
     *
     * @param string|null $start วันเริ่ม
     * @param string|null $end วันจบ
     */
    private static function assertDates(?string $start, ?string $end): void
    {
        if ($start !== null && $end !== null && strcmp($end, $start) < 0) {
            throw new ProjectColumnException(422, 'วันจบต้องไม่ก่อนวันเริ่ม');
        }
    }
}
