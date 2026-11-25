<?php
// =====================================================
// controllers/ProjectFieldController.php — ช่องข้อมูลกำหนดเองของโปรเจค
//
// ทุก endpoint ในไฟล์นี้ต้องมีสิทธิ์ระดับผู้ดูแล
// และ id ของช่อง/โปรเจคที่มองไม่เห็นต้องตอบเหมือน id ที่ไม่มีอยู่
// =====================================================

class ProjectFieldController
{
    use ProjectAccess;

    /**
     * ส่งข้อความของ ProjectColumnException ออกเป็น JSON ด้วยรหัสเดิม
     *
     * @param ProjectColumnException $e ข้อยกเว้นที่ model โยนขณะถือ lock
     */
    private static function fail(ProjectColumnException $e): never
    {
        Response::json(['error' => $e->getMessage()], $e->httpStatus);
    }

    /**
     * โหลดช่องข้อมูลตามรหัสและบังคับสิทธิ์ระดับผู้ดูแลของโปรเจคเจ้าของ
     *
     * ช่องที่ไม่มี ของโปรเจคที่มองไม่เห็น และที่ถูกลบ ตอบ 404 เดียวกัน
     *
     * @param string $fid รหัสช่องข้อมูลจาก URL
     * @return array{project: array<string, mixed>, field: array<string, mixed>}
     */
    private function loadAdminField(string $fid): array
    {
        $field = ProjectField::find((int)$fid);
        if (!$field) {
            Response::json(['error' => 'ไม่พบช่องข้อมูล'], 404);
        }
        $project = Project::getById((int)$field['project_id'], Auth::userId());
        if (!$project) {
            Response::json(['error' => 'ไม่พบช่องข้อมูล'], 404);
        }
        $this->requireRole($project, 'admin', 'จัดการช่องข้อมูล');
        return ['project' => $project, 'field' => $field];
    }

    /**
     * แปลงชื่อช่องข้อมูล: NBSP เป็นช่องว่างแล้วตัดช่องว่างหัวท้าย
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string ชื่อที่พร้อมตรวจความยาว
     */
    private static function normalizeName(mixed $raw): string
    {
        return trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    }

    /**
     * ตรวจชื่อช่องข้อมูล (1-60 ตัวอักษร) และหยุดด้วย 422 เมื่อไม่ผ่าน
     *
     * @param string $name ชื่อที่แปลงแล้ว
     */
    private static function assertName(string $name): void
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > 60) {
            Response::json(['error' => 'ชื่อช่องต้องมีความยาว 1-60 ตัวอักษร'], 422);
        }
    }

    /**
     * POST /api/projects/{id}/fields
     *
     * เพิ่มช่องข้อมูลต่อท้ายโปรเจค แล้วตอบ 201 {ok, id}
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiCreate(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'admin', 'จัดการช่องข้อมูล');
        $projectId = (int)$project['id'];

        $name = self::normalizeName(Request::input('name', ''));
        $kind = (string)Request::input('kind', '');
        self::assertName($name);
        if (!in_array($kind, ProjectField::KINDS, true)) {
            Response::json(['error' => 'ชนิดช่องไม่ถูกต้อง'], 422);
        }

        try {
            $options = $kind === 'select'
                ? ProjectField::normalizeOptions(Request::list('options') ?? Request::input('options', ''))
                : [];
            $fieldId = ProjectField::create($projectId, $name, $kind, $options);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'เพิ่มช่องข้อมูล "' . $name . '"');
        Response::json(['ok' => true, 'id' => $fieldId], 201);
    }

    /**
     * PUT /api/projects/fields/{fid}
     *
     * เปลี่ยนชื่อและ/หรือตัวเลือกของช่องข้อมูล โดยไม่สนใจ kind ที่ส่งมา
     *
     * @param string $fid รหัสช่องข้อมูลจาก URL
     */
    public function apiUpdate(string $fid): void
    {
        $loaded = $this->loadAdminField($fid);
        $project = $loaded['project'];
        $field = $loaded['field'];
        $projectId = (int)$project['id'];
        $fieldId = (int)$field['id'];

        $payload = Request::json();
        $hasName = array_key_exists('name', $payload);
        $hasOptions = array_key_exists('options', $payload);
        if (!$hasName && !$hasOptions) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        $newName = null;
        if ($hasName) {
            $newName = self::normalizeName($payload['name']);
            self::assertName($newName);
        }

        try {
            $newOptions = $hasOptions
                ? ProjectField::normalizeOptions($payload['options'])
                : null;
            ProjectField::update($projectId, $fieldId, $newName, $newOptions);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        $logName = $newName ?? (string)$field['name'];
        ProjectActivity::log($projectId, Auth::userId(), 'แก้ไขช่องข้อมูล "' . $logName . '"');
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/projects/{id}/fields/reorder
     *
     * ตั้งลำดับช่องข้อมูลทั้งโปรเจคตามรายการ id ที่ส่งมา
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiReorder(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'admin', 'จัดการช่องข้อมูล');
        $projectId = (int)$project['id'];

        $payload = Request::json();
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) {
            Response::json(['error' => 'ข้อมูลการจัดลำดับไม่ถูกต้อง'], 422);
        }

        try {
            ProjectField::reorder($projectId, array_values($ids));
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'จัดลำดับช่องข้อมูล');
        Response::json(['ok' => true]);
    }

    /**
     * DELETE /api/projects/fields/{fid}
     *
     * ลบช่องข้อมูลของโปรเจค แล้วตอบ {ok:true}
     *
     * @param string $fid รหัสช่องข้อมูลจาก URL
     */
    public function apiDelete(string $fid): void
    {
        $loaded = $this->loadAdminField($fid);
        $project = $loaded['project'];
        $field = $loaded['field'];
        $projectId = (int)$project['id'];
        $fieldId = (int)$field['id'];

        try {
            ProjectField::delete($projectId, $fieldId);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'ลบช่องข้อมูล "' . (string)$field['name'] . '"');
        Response::json(['ok' => true]);
    }
}
