<?php
// =====================================================
// controllers/ProjectReleaseController.php — เวอร์ชัน (release) และ changelog ของโปรเจค
//
// อ่านได้ทุกคนที่เห็นโปรเจค (รวมแขกลิงก์แชร์ ตาม D-5) จัดการเวอร์ชันเป็นของผู้ดูแลขึ้นไป
// id ที่ไม่มี ลบแล้ว อยู่คนละโปรเจค หรือมองไม่เห็น ตอบ 404 "ไม่พบเวอร์ชัน" เดียวกัน
// =====================================================

class ProjectReleaseController
{
    use ProjectAccess;

    /** ฟิลด์ที่แก้ได้ผ่าน PUT /api/projects/releases/{rid} */
    private const UPDATABLE = ['name', 'release_date', 'state', 'changelog'];

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
     * โหลดเวอร์ชันและโปรเจคเจ้าของ พร้อมปิดด้วย 404 เมื่อมองไม่เห็น
     *
     * @param string $rid รหัสเวอร์ชันจาก URL
     * @return array{release: array<string, mixed>, project: array<string, mixed>}
     */
    private function loadRelease(string $rid): array
    {
        $release = ProjectRelease::find((int)$rid);
        $project = $release ? Project::getById((int)$release['project_id'], Auth::userId()) : null;
        if (!$release || !$project) {
            Response::json(['error' => 'ไม่พบเวอร์ชัน'], 404);
        }
        return ['release' => $release, 'project' => $project];
    }

    /**
     * GET /api/projects/{id}/releases
     *
     * คืนเวอร์ชันทั้งหมดของโปรเจคพร้อมจำนวนงาน (แขกลิงก์แชร์อ่านได้)
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiList(string $id): void
    {
        $project = $this->loadProject((int)$id);
        Response::json(['releases' => ProjectRelease::listForProject((int)$project['id'])]);
    }

    /**
     * POST /api/projects/{id}/releases
     *
     * ผู้ดูแลขึ้นไปสร้างเวอร์ชัน body {name, release_date?}
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiCreate(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'admin', 'จัดการเวอร์ชัน');
        $projectId = (int)$project['id'];

        try {
            $created = ProjectRelease::create($projectId, Request::json());
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'สร้างเวอร์ชัน "' . $created['name'] . '"');
        Response::json(['ok' => true, 'id' => $created['id'], 'name' => $created['name']], 201);
    }

    /**
     * PUT /api/projects/releases/{rid}
     *
     * ผู้ดูแลขึ้นไปแก้ชื่อ วันออก สถานะ หรือ changelog (กฎของแต่ละช่องอยู่ใน model)
     *
     * @param string $rid รหัสเวอร์ชันจาก URL
     */
    public function apiUpdate(string $rid): void
    {
        $loaded = $this->loadRelease($rid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการเวอร์ชัน');

        $payload = array_intersect_key(Request::json(), array_flip(self::UPDATABLE));
        if ($payload === []) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        try {
            ProjectRelease::update((int)$loaded['project']['id'], (int)$loaded['release']['id'], $payload);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }
        Response::json(['ok' => true]);
    }

    /**
     * DELETE /api/projects/releases/{rid}
     *
     * ผู้ดูแลขึ้นไปลบเวอร์ชัน งานที่ผูกไว้ยังอยู่แต่ไม่ผูกเวอร์ชันแล้ว
     *
     * @param string $rid รหัสเวอร์ชันจาก URL
     */
    public function apiDelete(string $rid): void
    {
        $loaded = $this->loadRelease($rid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการเวอร์ชัน');
        $projectId = (int)$loaded['project']['id'];

        try {
            $deleted = ProjectRelease::delete($projectId, (int)$loaded['release']['id']);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'ลบเวอร์ชัน "' . $deleted['name'] . '"');
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/projects/releases/{rid}/release
     *
     * ผู้ดูแลขึ้นไปออกเวอร์ชัน body {"carry": รหัสเวอร์ชัน | null} บอกที่ย้ายงานค้าง
     * คืน changelog ที่สร้างและจำนวนงานค้างที่ย้าย
     *
     * @param string $rid รหัสเวอร์ชันจาก URL
     */
    public function apiRelease(string $rid): void
    {
        $loaded = $this->loadRelease($rid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการเวอร์ชัน');
        $projectId = (int)$loaded['project']['id'];
        $releaseId = (int)$loaded['release']['id'];

        try {
            $done = ProjectRelease::release($projectId, $releaseId, Request::json()['carry'] ?? null);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log(
            $projectId,
            Auth::userId(),
            'ออกเวอร์ชัน "' . $done['name'] . '"' . ($done['carried'] > 0 ? ' (ย้ายงานค้าง ' . $done['carried'] . ' งาน)' : '')
        );
        $release = ProjectRelease::findForProject($projectId, $releaseId);
        Response::json(['ok' => true, 'carried' => $done['carried'], 'changelog' => (string)($release['changelog'] ?? '')]);
    }
}
