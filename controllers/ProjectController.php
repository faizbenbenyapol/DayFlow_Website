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

    /**
     * ตรวจและปรับค่าฟิลด์โปรเจคที่ผู้ใช้แก้ (ชื่อ สถานะ ความสำคัญ วันครบกำหนด
     * ตัวย่อเลขงาน และชุดแต้ม)
     *
     * ค่าที่ไม่ผ่านปิดคำขอด้วย 422 ทันที เพราะ Response::json ไม่คืนค่า
     *
     * @param array<string, mixed> $data ฟิลด์ที่จะบันทึก
     * @param bool $create true เมื่อสร้างโปรเจคใหม่ (ชื่อบังคับ)
     * @return array<string, mixed> ฟิลด์ที่ตรวจแล้ว
     */
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
        if (array_key_exists('key_prefix', $data)) {
            $prefix = strtoupper(trim((string)$data['key_prefix']));
            if (preg_match('/^[A-Z]{2,6}$/', $prefix) !== 1) Response::json(['error' => 'ตัวย่อเลขงานต้องเป็นตัวอักษรอังกฤษ 2–6 ตัว'], 422);
            $data['key_prefix'] = $prefix;
        }
        if (array_key_exists('point_scale', $data) && !in_array($data['point_scale'], ['fib', 'ten'], true)) {
            Response::json(['error' => 'ชุดแต้มไม่ถูกต้อง'], 422);
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
        $type = (string)Request::input('type', 'general');
        if (!in_array($type, Project::TYPES, true)) {
            Response::json(['error' => 'ประเภทโปรเจคไม่ถูกต้อง'], 422);
        }

        $data = $this->validateProjectData([
            'name'        => $name,
            'description' => Request::input('description', ''),
            'type'        => $type,
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
     * แปลงประเภทโปรเจคเป็นชื่อไทยสำหรับบันทึกกิจกรรม
     *
     * รับค่าประเภทจาก Project::TYPES และคืนชื่อไทย ไม่มีผลข้างเคียง
     *
     * @param string $type ประเภทโปรเจค
     * @return string ชื่อไทย
     */
    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'general'   => 'ทั่วไป',
            'software'  => 'ซอฟต์แวร์',
            'marketing' => 'การตลาด',
            'finance'   => 'บัญชีและการเงิน',
            default     => $type,
        };
    }

    /**
     * PUT /api/projects/{id}
     *
     * ฟิลด์ทั่วไปแก้ได้ตั้งแต่ผู้ดูแลขึ้นไป ส่วนการเปลี่ยนประเภทเป็นของเจ้าของ
     * และการเปลี่ยนประเภทไม่แตะคอลัมน์หรืองาน (ข้อมูลเฉพาะประเภทซ่อนไว้ ไม่ลบ)
     * ตัวย่อเลขงาน ชุดแต้ม และสวิตช์ใช้รอบงานเป็นฟิลด์ทั่วไปของผู้ดูแล
     * การปิดสวิตช์ไม่ลบรอบงานหรืองานใด ๆ
     */
    public function apiUpdate(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);

        $typeInput = Request::input('type');
        if ($typeInput !== null && !in_array($typeInput, Project::TYPES, true)) {
            Response::json(['error' => 'ประเภทโปรเจคไม่ถูกต้อง'], 422);
        }
        $typeChange = $typeInput !== null && (string)$typeInput !== (string)$project['type'];
        $this->requireRole($project, $typeChange ? 'owner' : 'admin', 'แก้ไขรายละเอียดโครงการ');

        $data = [];
        $changes = [];

        if ($typeInput !== null) {
            $data['type'] = $typeInput;
            if ($typeChange) {
                $changes[] = 'ประเภท เปลี่ยนเป็น "' . self::typeLabel((string)$typeInput) . '"';
            }
        }

        $allowed = ['name', 'description', 'status', 'priority', 'due_date'];
        foreach ($allowed as $field) {
            $val = Request::input($field);
            if ($val !== null) {
                $data[$field] = $val;
                if ($project[$field] != $val) {
                    $changes[] = "$field เปลี่ยนจาก '" . ($project[$field] ?: 'ไม่มี') . "' เป็น '$val'";
                }
            }
        }

        // ตัวย่อเลขงานและชุดแต้มเป็นฟิลด์ทั่วไปของผู้ดูแล ค่าถูกตรวจ/ปรับใน validateProjectData
        $settings = ['key_prefix' => 'ตัวย่อเลขงาน', 'point_scale' => 'ชุดแต้ม'];
        foreach (array_keys($settings) as $field) {
            $val = Request::input($field);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }

        // สวิตช์ใช้รอบงานเก็บตรงในตาราง เพราะ Project::update รับเฉพาะฟิลด์ทั่วไป
        // การปิดสวิตช์ไม่ลบรอบงานหรืองานใด ๆ
        $useSprints = null;
        $rawUseSprints = Request::input('use_sprints');
        if ($rawUseSprints !== null) {
            if (is_bool($rawUseSprints)) {
                $rawUseSprints = $rawUseSprints ? 1 : 0;
            }
            if (!in_array((string)$rawUseSprints, ['0', '1'], true)) {
                Response::json(['error' => 'ค่าการใช้รอบงานไม่ถูกต้อง'], 422);
            }
            $useSprints = (int)$rawUseSprints;
        }

        $data = $this->validateProjectData($data);
        if (empty($data) && $useSprints === null) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        foreach ($settings as $field => $label) {
            if (array_key_exists($field, $data) && (string)($project[$field] ?? '') !== (string)$data[$field]) {
                $changes[] = $label . " เปลี่ยนจาก '" . (($project[$field] ?? '') ?: 'ไม่มี') . "' เป็น '" . $data[$field] . "'";
            }
        }

        $updated = Project::update($projectId, $data);
        if ($useSprints !== null && (int)($project['use_sprints'] ?? 1) !== $useSprints) {
            DB::run('UPDATE projects SET use_sprints = ? WHERE id = ?', [$useSprints, $projectId]);
            $changes[] = 'ใช้รอบงาน เปลี่ยนเป็น ' . ($useSprints === 1 ? 'เปิด' : 'ปิด');
            $updated = true;
        }

        if ($updated) {
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
     *
     * การลบโครงการเป็นของเจ้าของเท่านั้น
     */
    public function apiDelete(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);
        $this->requireRole($project, 'owner', 'ลบโครงการ');

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
