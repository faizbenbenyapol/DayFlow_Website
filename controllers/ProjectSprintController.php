<?php
// =====================================================
// controllers/ProjectSprintController.php — รอบงานและ backlog ของโปรเจค
//
// ทุก endpoint แยกสิทธิ์ตาม ProjectAccess: อ่านได้ทุกคนที่เห็นโปรเจค
// (รวมแขกลิงก์แชร์) จัดการรอบงานเป็นของผู้ดูแลขึ้นไป และวางแผนงานเป็นของสมาชิกขึ้นไป
// id ที่ไม่มี ลบแล้ว อยู่คนละโปรเจค หรือมองไม่เห็น ตอบ 404 "ไม่พบรอบงาน" เดียวกัน
// =====================================================

class ProjectSprintController
{
    use ProjectAccess;

    /** ฟิลด์ที่แก้ได้ผ่าน PUT /api/projects/sprints/{sid} */
    private const UPDATABLE = ['name', 'goal', 'start_date', 'end_date'];

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
     * โหลดรอบงานและโปรเจคเจ้าของ พร้อมปิดด้วย 404 เมื่อมองไม่เห็น
     *
     * รอบงานที่ไม่มี ลบแล้ว อยู่คนละโปรเจค หรือโปรเจคที่ผู้เรียกมองไม่เห็น
     * ให้ข้อความเดียวกัน เพื่อไม่ให้เดารหัสที่มีอยู่ได้
     *
     * @param string $sid รหัสรอบงานจาก URL
     * @return array{sprint: array<string, mixed>, project: array<string, mixed>}
     */
    private function loadSprint(string $sid): array
    {
        $sprint = ProjectSprint::find((int)$sid);
        $project = $sprint ? Project::getById((int)$sprint['project_id'], Auth::userId()) : null;
        if (!$sprint || !$project) {
            Response::json(['error' => 'ไม่พบรอบงาน'], 404);
        }
        return ['sprint' => $sprint, 'project' => $project];
    }

    /**
     * GET /api/projects/{id}/sprints
     *
     * คืนรอบงานทั้งหมดของโปรเจค (แขกลิงก์แชร์อ่านได้)
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiList(string $id): void
    {
        $project = $this->loadProject((int)$id);
        Response::json(['sprints' => ProjectSprint::listForProject((int)$project['id'])]);
    }

    /**
     * POST /api/projects/{id}/sprints
     *
     * ผู้ดูแลขึ้นไปสร้างรอบงาน ชื่อว่างได้ชื่อ "รอบ N" อัตโนมัติ
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiCreate(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'admin', 'จัดการรอบงาน');
        $projectId = (int)$project['id'];

        try {
            $created = ProjectSprint::create($projectId, Request::json());
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'สร้างรอบงาน "' . $created['name'] . '"');
        Response::json(['ok' => true, 'id' => $created['id'], 'name' => $created['name']], 201);
    }

    /**
     * PUT /api/projects/sprints/{sid}
     *
     * ผู้ดูแลขึ้นไปแก้ชื่อ เป้าหมาย และวันของรอบงาน (รอบที่ปิดแล้วแก้ไม่ได้)
     *
     * @param string $sid รหัสรอบงานจาก URL
     */
    public function apiUpdate(string $sid): void
    {
        $loaded = $this->loadSprint($sid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการรอบงาน');

        $payload = Request::json();
        $hasAny = false;
        foreach (self::UPDATABLE as $field) {
            if (array_key_exists($field, $payload)) {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        try {
            ProjectSprint::update((int)$loaded['sprint']['id'], $payload);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        Response::json(['ok' => true]);
    }

    /**
     * DELETE /api/projects/sprints/{sid}
     *
     * ผู้ดูแลขึ้นไปลบรอบงานที่ยังไม่เริ่ม งานในรอบกลับไปอยู่ backlog
     *
     * @param string $sid รหัสรอบงานจาก URL
     */
    public function apiDelete(string $sid): void
    {
        $loaded = $this->loadSprint($sid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการรอบงาน');
        $projectId = (int)$loaded['project']['id'];

        try {
            $deleted = ProjectSprint::delete($projectId, (int)$loaded['sprint']['id']);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'ลบรอบงาน "' . $deleted['name'] . '"');
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/projects/sprints/{sid}/start
     *
     * ผู้ดูแลขึ้นไปเริ่มรอบงานที่วางแผนไว้ (ต้องมีวันเริ่ม/วันจบ และไม่มีรอบอื่น active)
     *
     * @param string $sid รหัสรอบงานจาก URL
     */
    public function apiStart(string $sid): void
    {
        $loaded = $this->loadSprint($sid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการรอบงาน');
        $projectId = (int)$loaded['project']['id'];

        try {
            $started = ProjectSprint::start($projectId, (int)$loaded['sprint']['id']);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        ProjectActivity::log($projectId, Auth::userId(), 'เริ่มรอบงาน "' . $started['name'] . '"');
        Response::json(['ok' => true]);
    }

    /**
     * POST /api/projects/sprints/{sid}/close
     *
     * ผู้ดูแลขึ้นไปปิดรอบงานที่กำลังทำ พร้อมเลือกที่ย้ายงานค้างด้วย body {"carry": ...}
     *
     * @param string $sid รหัสรอบงานจาก URL
     */
    public function apiClose(string $sid): void
    {
        $loaded = $this->loadSprint($sid);
        $this->requireRole($loaded['project'], 'admin', 'จัดการรอบงาน');
        $projectId = (int)$loaded['project']['id'];
        $sprintId = (int)$loaded['sprint']['id'];

        $payload = Request::json();
        $carry = array_key_exists('carry', $payload) ? $payload['carry'] : 'backlog';

        try {
            $closed = ProjectSprint::close($projectId, $sprintId, $carry);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        $targetId = $closed['target_sprint_id'];
        $target = 'คลังงาน';
        if ($targetId !== null) {
            $targetSprint = ProjectSprint::findForProject($projectId, $targetId);
            $target = '"' . (string)($targetSprint['name'] ?? '') . '"';
        }
        $closedSprint = ProjectSprint::findForProject($projectId, $sprintId);
        $completed = (int)($closedSprint['completed_points'] ?? 0);

        ProjectActivity::log(
            $projectId,
            Auth::userId(),
            'ปิดรอบงาน "' . $loaded['sprint']['name'] . '" (เสร็จ ' . $completed . ' แต้ม ย้ายงานค้าง ' . $closed['moved'] . ' งานไป ' . $target . ')'
        );
        Response::json([
            'ok' => true,
            'moved' => $closed['moved'],
            'target_sprint_id' => $targetId,
        ]);
    }

    /**
     * POST /api/projects/{id}/plan
     *
     * สมาชิกขึ้นไปย้ายงานระหว่าง backlog กับรอบงานตามลำดับที่ส่งมา
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiPlan(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $this->requireRole($project, 'member', 'วางแผนงาน');
        $projectId = (int)$project['id'];

        $payload = Request::json();
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) {
            Response::json(['error' => 'รายการงานไม่ถูกต้อง'], 422);
        }
        $sprintId = array_key_exists('sprint_id', $payload) ? $payload['sprint_id'] : null;

        try {
            $planned = ProjectTask::plan($projectId, $sprintId, array_values($ids));
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        Response::json([
            'ok' => true,
            'sprint_id' => $planned['sprint_id'],
            'moved' => $planned['moved'],
        ]);
    }

    /**
     * GET /api/projects/{id}/reports
     *
     * สมาชิกทุกบทบาท (รวมผู้ดู) อ่าน burndown ของรอบงานและ velocity ของโปรเจค
     * `?sprint=` เลือกรอบที่ระบุ รอบที่ไม่มี ลบแล้ว อยู่คนละโปรเจค หรือค่าไม่ใช่ตัวเลข
     * ตอบ 404 ข้อความเดียวกันทั้งหมด ไม่บอกว่ารหัสใดมีอยู่
     * ไม่ระบุรอบจึงใช้รอบ active หรือรอบที่ปิดล่าสุด หรือ null เมื่อยังไม่มี
     *
     * @param string $id รหัสโปรเจคจาก URL
     */
    public function apiReports(string $id): void
    {
        $project = $this->loadProject((int)$id);
        $projectId = (int)$project['id'];

        $rawSprint = Request::query('sprint');
        if ($rawSprint === null) {
            $sprint = self::reportSprint($projectId);
        } else {
            $sprint = is_string($rawSprint) && ctype_digit($rawSprint)
                ? ProjectSprint::findForProject($projectId, (int)$rawSprint)
                : null;
            if (!$sprint) {
                Response::json(['error' => 'ไม่พบรอบงาน'], 404);
            }
        }

        Response::json([
            'burndown' => $sprint === null ? null : ProjectSprint::burndown($projectId, (int)$sprint['id']),
            'velocity' => ProjectSprint::velocity($projectId),
        ]);
    }

    /**
     * เลือกรอบงานเริ่มต้นของรายงาน: รอบ active ก่อน ไม่มีจึงใช้รอบที่ปิดล่าสุด
     *
     * @param int $projectId รหัสโปรเจค
     * @return array<string, mixed>|null แถวรอบงาน หรือ null เมื่อไม่มีทั้งสองแบบ
     */
    private static function reportSprint(int $projectId): ?array
    {
        $closed = null;
        foreach (ProjectSprint::listForProject($projectId) as $sprint) {
            if ((string)$sprint['state'] === 'active') {
                return $sprint;
            }
            if ($closed === null && (string)$sprint['state'] === 'closed') {
                $closed = $sprint;
            }
        }
        return $closed;
    }
}
