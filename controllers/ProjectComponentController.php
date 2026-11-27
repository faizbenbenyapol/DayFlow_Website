<?php
// =====================================================
// controllers/ProjectComponentController.php — ส่วนของระบบของโปรเจคซอฟต์แวร์
//
// อ่านรายการได้จาก payload ของงาน (components) จัดการเพิ่ม แก้ชื่อ และลบเป็น
// ผู้ดูแลขึ้นไปเท่านั้น รหัสที่ไม่มี ลบแล้ว อยู่คนละโปรเจค หรือมองไม่เห็น
// ตอบ 404 "ไม่พบส่วนของระบบ" เดียวกัน
// =====================================================

class ProjectComponentController
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
     * โหลดส่วนของระบบและโปรเจคเจ้าของ พร้อมปิดด้วย 404 เมื่อมองไม่เห็น
     *
     * @param string $cid รหัสส่วนของระบบจาก URL
     * @return array{component: array<string, mixed>, project: array<string, mixed>}
     */
    private function loadComponent(string $cid): array
    {
        $component = ProjectComponent::find((int)$cid);
        $project = $component ? Project::getById((int)$component['project_id'], Auth::userId()) : null;
        if (!$component || !$project) {
            Response::json(['error' => 'ไม่พบส่วนของระบบ'], 404);
        }
        return ['component' => $component, 'project' => $project];
    }

    /**
     * POST /api/projects/{id}/components
     *
     * ผู้ดูแลขึ้นไปเพิ่มส่วนของระบบ body {name}
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiCreate(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'admin', 'จัดการส่วนของระบบ');
        $projectId = (int)$project['id'];

        try {
            $created = ProjectComponent::create($projectId, Request::json()['name'] ?? null);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'สร้างส่วนของระบบ "' . $created['name'] . '"');
        Response::json(['ok' => true, 'id' => $created['id'], 'name' => $created['name']], 201);
    }

    /**
     * PUT /api/projects/components/{cid}
     *
     * ผู้ดูแลขึ้นไปเปลี่ยนชื่อส่วนของระบบ body {name}
     *
     * @param string $cid รหัสส่วนของระบบจาก URL
     */
    public function apiUpdate(string $cid): void
    {
        $loaded = $this->loadComponent($cid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการส่วนของระบบ');
        $projectId = (int)$loaded['project']['id'];

        try {
            $renamed = ProjectComponent::rename($projectId, (int)$loaded['component']['id'], Request::json()['name'] ?? null);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'เปลี่ยนชื่อส่วนของระบบ "' . $renamed['old'] . '" เป็น "' . $renamed['name'] . '"');
        Response::json(['ok' => true, 'name' => $renamed['name']]);
    }

    /**
     * DELETE /api/projects/components/{cid}
     *
     * ผู้ดูแลขึ้นไปลบส่วนของระบบ งานที่ติดอยู่หลุดจากส่วนนี้ตาม FK
     *
     * @param string $cid รหัสส่วนของระบบจาก URL
     */
    public function apiDelete(string $cid): void
    {
        $loaded = $this->loadComponent($cid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการส่วนของระบบ');
        $projectId = (int)$loaded['project']['id'];

        try {
            $name = ProjectComponent::delete($projectId, (int)$loaded['component']['id']);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'ลบส่วนของระบบ "' . $name . '"');
        Response::json(['ok' => true]);
    }
}
