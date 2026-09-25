<?php
// =====================================================
// controllers/ProjectController.php — the projects page and the projects themselves
// =====================================================

// Tasks, members and chat, and the public link have their own controllers
// (ProjectTask-, ProjectTeam-, ProjectShareController); all share the
// permission checks in the ProjectAccess trait.

class ProjectController
{
    use ProjectAccess;

    private const PROJECT_STATUSES = ['Planning', 'In Progress', 'Review', 'Completed'];
    private const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    private function validateProjectData(array $data, bool $create = false): array
    {
        if ($create || array_key_exists('name', $data)) {
            $data['name'] = trim((string)($data['name'] ?? ''));
            if ($data['name'] === '' || mb_strlen($data['name']) > 255) Response::json(['error' => 'ชื่อโปรเจคไม่ถูกต้อง'], 422);
        }
        if (array_key_exists('description', $data)) $data['description'] = mb_substr((string)$data['description'], 0, 5000);
        if (array_key_exists('status', $data) && !in_array($data['status'], self::PROJECT_STATUSES, true)) Response::json(['error' => 'สถานะโปรเจคไม่ถูกต้อง'], 422);
        if (array_key_exists('priority', $data) && !in_array($data['priority'], self::PRIORITIES, true)) Response::json(['error' => 'ความสำคัญโปรเจคไม่ถูกต้อง'], 422);
        if (array_key_exists('due_date', $data) && $data['due_date'] !== '') {
            $date = DateTime::createFromFormat('Y-m-d', (string)$data['due_date']);
            if (!$date || $date->format('Y-m-d') !== $data['due_date']) Response::json(['error' => 'วันครบกำหนดไม่ถูกต้อง'], 422);
        }
        return $data;
    }

    /**
     * โหลดหน้าวางแผนโปรเจค (Project Planner Page)
     */
    public function index(): void
    {
        $userId = Auth::userId();

        $pageTitle   = 'วางแผนโปรเจค (Project Planner)';
        $pageStyle    = 'projects';
        $pageScript   = 'projects';
        $loadChartJs  = true; // โหลด Chart.js เพื่อใช้วาดกราฟความคืบหน้าของโครงการ

        $projectIdOverride = $_SESSION['active_project_id_override'] ?? 0;
        unset($_SESSION['active_project_id_override']);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/projects/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    /**
     * GET /api/projects
     */
    public function apiList(): void
    {
        $userId   = Auth::userId();
        $projects = Project::getAll($userId);
        Response::json(['projects' => $projects]);
    }

    /**
     * POST /api/projects
     */
    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $name   = trim(Request::input('name', ''));

        if (!$name) {
            Response::json(['error' => 'กรุณาระบุชื่อโปรเจค'], 422);
        }

        $status  = Request::input('status', 'Planning');
        $priority = Request::input('priority', 'Medium');
        $data = $this->validateProjectData([
            'name'        => $name,
            'description' => Request::input('description', ''),
            'status'      => $status,
            'priority'    => $priority,
            'due_date'    => Request::input('due_date', '')
        ], true);

        $projectId = Project::create($userId, $data);
        ProjectActivity::log($projectId, $userId, 'สร้างโปรเจคใหม่: "' . $name . '"');

        $msg = TelegramService::formatMessage(
            "📁 โปรเจคใหม่ถูกสร้างขึ้น",
            [
                'ชื่อโปรเจค' => htmlspecialchars($name),
                'สถานะ' => $status,
                'ความสำคัญ' => $priority
            ]
        );
        TelegramService::sendNotification($userId, 'project', $msg);

        Response::json(['ok' => true, 'id' => $projectId], 201);
    }

    /**
     * PUT /api/projects/{id}
     */
    public function apiUpdate(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);
        $this->requireOwner($project, 'แก้ไขรายละเอียดโครงการ');

        $data = [];
        $allowed = ['name', 'description', 'status', 'priority', 'due_date'];
        $changes = [];

        foreach ($allowed as $field) {
            $val = Request::input($field);
            if ($val !== null) {
                $data[$field] = $val;
                if ($project[$field] != $val) {
                    $changes[] = "$field เปลี่ยนจาก '" . ($project[$field] ?: 'ไม่มี') . "' เป็น '$val'";
                }
            }
        }

        $data = $this->validateProjectData($data);
        if (empty($data)) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        if (Project::update($projectId, $userId, $data)) {
            if (!empty($changes)) {
                ProjectActivity::log($projectId, $userId, 'อัปเดตข้อมูลโปรเจค: ' . implode(', ', $changes));
            }
            Response::json(['ok' => true]);
        } else {
            Response::json(['ok' => true, 'message' => 'ไม่มีการเปลี่ยนแปลงข้อมูล']);
        }
    }

    /**
     * DELETE /api/projects/{id}
     */
    public function apiDelete(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);
        $this->requireOwner($project, 'ลบโครงการ');

        if (Project::delete($projectId, $userId)) {
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถลบโปรเจคได้'], 500);
        }
    }

    /**
     * รับผู้เข้าชมลิงก์สาธารณะโดยไม่ต้องมีบัญชี
     * GET /project/shared/{token}
     */
    public function sharedProjectView(string $token): void
    {
        // 1. ตรวจสอบโทเค็นลิงก์แชร์โครงการ
        $project = DB::run('SELECT * FROM projects WHERE share_token = ?', [$token])->fetch();
        if (!$project) {
            Response::abort(404, 'ไม่พบโครงการที่ค้นหา หรือลิงก์สาธารณะนี้ถูกปิดใช้งานแล้ว');
        }

        // 2. บันทึก Share Token ลงเซสชัน
        $_SESSION['active_project_share_token'] = $token;

        // 3. กำหนดชื่อผู้เยี่ยมชมเริ่มต้นหากยังไม่ได้ล็อกอิน
        if (empty($_SESSION['user_id'])) {
            if (empty($_SESSION['guest_name'])) {
                $_SESSION['guest_name'] = 'ผู้เยี่ยมชม #' . rand(1000, 9999);
            }
        }

        // 4. สั่ง Override เพื่อให้หน้าโครงการเปิดโครงการนี้ขึ้นมาทันทีเมื่อโหลดหน้าเว็บ
        $_SESSION['active_project_id_override'] = (int)$project['id'];

        Response::redirect('/projects');
    }
}
