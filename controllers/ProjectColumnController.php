<?php
// =====================================================
// controllers/ProjectColumnController.php — คอลัมน์บอร์ดของโปรเจค
//
// ทุก endpoint ในไฟล์นี้ต้องมีสิทธิ์ระดับผู้ดูแล (เจ้าของก่อน slice ถัดไป)
// และ id ของคอลัมน์/โปรเจคที่มองไม่เห็นต้องตอบเหมือน id ที่ไม่มีอยู่
// =====================================================

class ProjectColumnController
{
    use ProjectAccess;

    /** กลุ่มที่รับได้ของคอลัมน์ */
    private const GROUPS = ['todo', 'doing', 'done'];

    /** จำนวนคอลัมน์สูงสุดต่อโปรเจค */
    private const MAX_COLUMNS = 12;

    /**
     * โหลดโปรเจคตามรหัสและบังคับสิทธิ์ระดับผู้ดูแล
     *
     * @param string $id รหัสโปรเจคจาก URL
     * @return array<string, mixed> โปรเจคที่ผู้เรียกเห็นได้
     */
    private function loadAdminProject(string $id): array
    {
        $project = Project::getById((int)$id, Auth::userId());
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }
        $this->requireRole($project, 'admin', 'จัดการคอลัมน์');
        return $project;
    }

    /**
     * โหลดคอลัมน์ตามรหัสและบังคับสิทธิ์ระดับผู้ดูแลของโปรเจคเจ้าของ
     *
     * คอลัมน์ที่ไม่มี ของโปรเจคที่มองไม่เห็น และที่ถูกลบ ตอบ 404 เดียวกัน
     *
     * @param string $cid รหัสคอลัมน์จาก URL
     * @return array{project: array<string, mixed>, column: array<string, mixed>}
     */
    private function loadAdminColumn(string $cid): array
    {
        $column = ProjectColumn::find((int)$cid);
        if (!$column) {
            Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
        }
        $project = Project::getById((int)$column['project_id'], Auth::userId());
        if (!$project) {
            Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
        }
        $this->requireRole($project, 'admin', 'จัดการคอลัมน์');
        return ['project' => $project, 'column' => $column];
    }

    /**
     * แปลงชื่อคอลัมน์: NBSP เป็นช่องว่างแล้วตัดช่องว่างหัวท้าย
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string ชื่อที่พร้อมตรวจความยาว
     */
    private static function normalizeName(mixed $raw): string
    {
        return trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    }

    /**
     * ตรวจชื่อคอลัมน์ (1-60 ตัวอักษร) และหยุดด้วย 422 เมื่อไม่ผ่าน
     *
     * @param string $name ชื่อที่แปลงแล้ว
     */
    private static function assertName(string $name): void
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > 60) {
            Response::json(['error' => 'ชื่อคอลัมน์ต้องมีความยาว 1-60 ตัวอักษร'], 422);
        }
    }

    /**
     * POST /api/projects/{id}/columns
     *
     * เพิ่มคอลัมน์ต่อท้ายกลุ่มของมัน แล้วตอบ 201 {ok, id}
     */
    public function apiCreate(string $id): void
    {
        $project = $this->loadAdminProject($id);
        $projectId = (int)$project['id'];

        $name = self::normalizeName(Request::input('name', ''));
        $grp = (string)Request::input('grp', '');
        self::assertName($name);
        if (!in_array($grp, self::GROUPS, true)) {
            Response::json(['error' => 'กลุ่มคอลัมน์ไม่ถูกต้อง'], 422);
        }
        if (count(ProjectColumn::listForProject($projectId)) >= self::MAX_COLUMNS) {
            Response::json(['error' => 'เพิ่มคอลัมน์ได้สูงสุด ' . self::MAX_COLUMNS . ' คอลัมน์ต่อโปรเจค'], 422);
        }
        if (ProjectColumn::nameExists($projectId, $name)) {
            Response::json(['error' => 'มีชื่อคอลัมน์นี้อยู่แล้ว'], 422);
        }

        $columnId = ProjectColumn::create($projectId, $name, $grp);
        ProjectActivity::log($projectId, Auth::userId(), 'เพิ่มคอลัมน์ "' . $name . '"');
        Response::json(['ok' => true, 'id' => $columnId], 201);
    }

    /**
     * PUT /api/projects/columns/{cid}
     *
     * เปลี่ยนชื่อและ/หรือย้ายกลุ่ม (ไปต่อท้ายกลุ่มใหม่) และปรับสถานะเงาของงาน
     */
    public function apiUpdate(string $cid): void
    {
        $loaded = $this->loadAdminColumn($cid);
        $project = $loaded['project'];
        $column = $loaded['column'];
        $projectId = (int)$project['id'];
        $columnId = (int)$column['id'];

        $nameInput = Request::input('name');
        $grpInput = Request::input('grp');
        if ($nameInput === null && $grpInput === null) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        $newName = null;
        if ($nameInput !== null) {
            $newName = self::normalizeName($nameInput);
            self::assertName($newName);
            if (ProjectColumn::nameExists($projectId, $newName, $columnId)) {
                Response::json(['error' => 'มีชื่อคอลัมน์นี้อยู่แล้ว'], 422);
            }
        }

        $newGrp = null;
        if ($grpInput !== null) {
            $newGrp = (string)$grpInput;
            if (!in_array($newGrp, self::GROUPS, true)) {
                Response::json(['error' => 'กลุ่มคอลัมน์ไม่ถูกต้อง'], 422);
            }
            if ($newGrp !== $column['grp']) {
                $inGroup = 0;
                foreach (ProjectColumn::listForProject($projectId) as $other) {
                    if ($other['grp'] === $column['grp']) $inGroup++;
                }
                if ($inGroup <= 1) {
                    Response::json(['error' => 'แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์'], 422);
                }
            }
        }

        if ($newName !== null) {
            ProjectColumn::rename($columnId, $newName);
        }
        if ($newGrp !== null && $newGrp !== $column['grp']) {
            ProjectColumn::regroup($projectId, $columnId, $newGrp);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'แก้ไขคอลัมน์ "' . ($newName ?? $column['name']) . '"');
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/projects/{id}/columns/reorder
     *
     * ตั้งลำดับคอลัมน์ในกลุ่ม ต้องส่ง id ครบและตรงกับคอลัมน์ของกลุ่มนั้น
     */
    public function apiReorder(string $id): void
    {
        $project = $this->loadAdminProject($id);
        $projectId = (int)$project['id'];

        $payload = Request::json();
        $grp = (string)($payload['grp'] ?? '');
        $ids = $payload['ids'] ?? [];

        if (!in_array($grp, self::GROUPS, true) || !is_array($ids)) {
            Response::json(['error' => 'ข้อมูลการจัดลำดับไม่ถูกต้อง'], 422);
        }

        $groupIds = [];
        foreach (ProjectColumn::listForProject($projectId) as $column) {
            if ($column['grp'] === $grp) $groupIds[] = (int)$column['id'];
        }
        $given = array_map(static fn(mixed $value): int => (int)$value, array_values($ids));
        sort($groupIds);
        sort($given);
        if ($groupIds !== $given) {
            Response::json(['error' => 'รายการคอลัมน์ไม่ตรงกับกลุ่ม'], 422);
        }

        ProjectColumn::reorderGroup($projectId, $grp, array_values($ids));
        ProjectActivity::log($projectId, Auth::userId(), 'จัดลำดับคอลัมน์ในกลุ่ม ' . $grp);
        Response::json(['ok' => true]);
    }

    /**
     * DELETE /api/projects/columns/{cid}
     *
     * ถ้ามีงานต้องส่ง move_to เพื่อย้ายงานไปต่อท้ายคอลัมน์ปลายทางก่อนลบ
     */
    public function apiDelete(string $cid): void
    {
        $loaded = $this->loadAdminColumn($cid);
        $project = $loaded['project'];
        $column = $loaded['column'];
        $projectId = (int)$project['id'];
        $columnId = (int)$column['id'];

        $inGroup = 0;
        foreach (ProjectColumn::listForProject($projectId) as $other) {
            if ($other['grp'] === $column['grp']) $inGroup++;
        }
        if ($inGroup <= 1) {
            Response::json(['error' => 'แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์'], 422);
        }

        $moveToId = null;
        if (ProjectTask::countInColumn($projectId, $columnId) > 0) {
            $moveInput = Request::input('move_to');
            if ($moveInput === null || (int)$moveInput <= 0) {
                Response::json(['error' => 'เลือกคอลัมน์ที่จะย้ายงานไป'], 422);
            }
            $moveToId = (int)$moveInput;
            $target = $moveToId === $columnId ? null : ProjectColumn::findInProject($projectId, $moveToId);
            if (!$target) {
                Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
            }
        }

        ProjectColumn::delete($projectId, $columnId, $moveToId);
        ProjectActivity::log($projectId, Auth::userId(), 'ลบคอลัมน์ "' . $column['name'] . '"');
        Response::json(['ok' => true]);
    }
}
