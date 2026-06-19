<?php
// =====================================================
// controllers/ProjectController.php
// =====================================================

require_once ROOT . '/models/Project.php';
require_once ROOT . '/models/ProjectTask.php';
require_once ROOT . '/models/ProjectActivity.php';
require_once ROOT . '/models/ProjectMember.php';
require_once ROOT . '/models/ProjectChat.php';
require_once ROOT . '/core/TelegramService.php';

class ProjectController
{
    /**
     * โหลดหน้าวางแผนโปรเจค (Project Planner Page)
     * พร้อมระบบตรวจสอบและรันสคริปต์สร้างตารางอัตโนมัติหากเข้าใช้งานครั้งแรก
     */
    public function index(): void
    {
        $userId = Auth::userId();

        // 1. ระบบติดตั้งตารางข้อมูลอัตโนมัติเมื่อยังไม่มีตารางอยู่ในระบบ
        try {
            DB::run('SELECT 1 FROM `projects` LIMIT 1');
        } catch (PDOException $e) {
            $sqlFile = ROOT . '/sql/migrate_projects.sql';
            if (file_exists($sqlFile)) {
                try {
                    $queries = file_get_contents($sqlFile);
                    DB::conn()->exec($queries);
                } catch (PDOException $ex) {
                    Response::abort(500, 'ไม่สามารถติดตั้งตารางโปรเจคอัตโนมัติได้: ' . $ex->getMessage());
                }
            } else {
                Response::abort(500, 'ไม่พบไฟล์สคริปต์สำหรับติดตั้งโครงสร้างตารางข้อมูล: ' . $sqlFile);
            }
        }

        // 2. ระบบติดตั้งตารางการทำงานร่วมกันและแชทสดอัตโนมัติ
        try {
            DB::run('SELECT 1 FROM `project_members` LIMIT 1');
        } catch (PDOException $e) {
            $sqlCollabFile = ROOT . '/sql/migrate_project_collab.sql';
            if (file_exists($sqlCollabFile)) {
                try {
                    $queries = file_get_contents($sqlCollabFile);
                    DB::conn()->exec($queries);
                } catch (PDOException $ex) {
                    Response::abort(500, 'ไม่สามารถติดตั้งตารางข้อมูลสำหรับทีมงานและแชทอัตโนมัติได้: ' . $ex->getMessage());
                }
            }
        }

        // 3. ระบบอัปเกรดตารางสำหรับระบบลิงก์สาธารณะและ Guest
        try {
            DB::run('SELECT `guest_name` FROM `project_activities` LIMIT 1');
        } catch (PDOException $e) {
            $sqlShareFile = ROOT . '/sql/migrate_project_share.sql';
            if (file_exists($sqlShareFile)) {
                try {
                    $queries = file_get_contents($sqlShareFile);
                    DB::conn()->exec($queries);
                } catch (PDOException $ex) {
                    // ป้องกันข้อผิดพลาดกรณีฟิลด์มีอยู่แล้ว
                }
            }
        }

        $pageTitle    = 'วางแผนโปรเจค (Project Planner)';
        $pageStyle    = 'projects';
        $pageScript   = 'projects';
        $loadChartJs  = true; // โหลด Chart.js เพื่อใช้วาดกราฟความคืบหน้าของโครงการ

        $projectIdOverride = $_SESSION['active_project_id_override'] ?? 0;
        unset($_SESSION['active_project_id_override']);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/projects/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // =====================================================
    // --- APIs: Projects CRUD ---
    // =====================================================

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

        $data = [
            'name'        => $name,
            'description' => Request::input('description', ''),
            'status'      => $status,
            'priority'    => $priority,
            'due_date'    => Request::input('due_date', '')
        ];

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

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

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

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        if (Project::delete($projectId, $userId)) {
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถลบโปรเจคได้'], 500);
        }
    }

    // =====================================================
    // --- APIs: Kanban Board & Tasks ---
    // =====================================================

    /**
     * ดึงข้อมูลงานคัมบัง ประวัติ และผลสรุปความก้าวหน้า AI ของโครงการร่วมกัน
     * GET /api/projects/{id}/tasks
     */
    /**
     * ดึงข้อมูลงานคัมบัง ประวัติ และผลสรุปความก้าวหน้า AI ของโครงการร่วมกัน
     * GET /api/projects/{id}/tasks
     */
    public function apiTasksList(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $tasks      = ProjectTask::getByProject($projectId);
        $activities = ProjectActivity::getRecent($projectId, 8);
        $aiInsight  = Project::getAiInsight($projectId, $userId);

        Response::json([
            'project'    => $project,
            'tasks'      => $tasks,
            'activities' => $activities,
            'ai'         => $aiInsight
        ]);
    }

    /**
     * POST /api/projects/{id}/tasks
     */
    public function apiTaskCreate(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        if ($project['user_role'] === 'Viewer') {
            Response::json(['error' => 'คุณมีสิทธิ์ดูเท่านั้น ไม่สามารถเพิ่มงานได้'], 403);
        }

        $title = trim(Request::input('title', ''));
        if (!$title) {
            Response::json(['error' => 'กรุณาระบุชื่องาน'], 422);
        }

        $data = [
            'title'     => $title,
            'status'    => Request::input('status', 'To Do'),
            'priority'  => Request::input('priority', 'Medium'),
            'due_date'  => Request::input('due_date', ''),
            'category'  => Request::input('category', ''),
            'assignee'  => Request::input('assignee', ''),
            'checklist' => Request::input('checklist', null)
        ];

        $taskId = ProjectTask::create($userId, $projectId, $data);
        ProjectActivity::log($projectId, $userId, 'เพิ่มงานย่อยใหม่: "' . $title . '"');

        Response::json(['ok' => true, 'id' => $taskId], 201);
    }

    /**
     * PUT /api/projects/tasks/{tid}
     */
    public function apiTaskUpdate(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        if (!$task) {
            Response::json(['error' => 'ไม่พบข้อมูลงานย่อย'], 404);
        }

        $project = Project::getById($task['project_id'], $userId);
        if (!$project) {
            Response::json(['error' => 'คุณไม่มีสิทธิ์เข้าถึงโครงการนี้'], 403);
        }

        if ($project['user_role'] === 'Viewer') {
            Response::json(['error' => 'คุณมีสิทธิ์ดูเท่านั้น ไม่สามารถแก้ไขงานได้'], 403);
        }

        $data = [];
        $allowed = ['title', 'status', 'priority', 'due_date', 'category', 'assignee', 'checklist'];
        $changes = [];

        foreach ($allowed as $field) {
            $val = Request::input($field);
            if ($val !== null) {
                $data[$field] = $val;
                if ($field === 'checklist') {
                    // ไม่ต้องเทียบข้อความความแตกต่างสำหรับการอัปเดตสถานะเช็คลิสต์ด่วน
                } elseif ($task[$field] != $val) {
                    $changes[] = "$field เปลี่ยนเป็น '$val'";
                }
            }
        }

        if (empty($data)) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        if (ProjectTask::update($taskId, $data)) {
            // ดึงกิจกรรม
            $actText = 'อัปเดตงานย่อย "' . ($data['title'] ?? $task['title']) . '"';
            if (!empty($changes)) {
                $actText .= ' (' . implode(', ', $changes) . ')';
            }
            ProjectActivity::log($task['project_id'], $userId, $actText);

            // Send notification if task is marked as Done
            if (isset($data['status']) && $data['status'] === 'Done' && $task['status'] !== 'Done') {
                $msg = TelegramService::formatMessage(
                    "✅ งานในโปรเจคเสร็จสมบูรณ์",
                    [
                        'ชื่องาน' => htmlspecialchars($task['title']),
                        'รหัสโปรเจค' => $task['project_id']
                    ],
                    'เสร็จสิ้น'
                );
                TelegramService::sendNotification($userId, 'task', $msg);
            }

            Response::json(['ok' => true]);
        } else {
            Response::json(['ok' => true, 'message' => 'ไม่มีการแก้ไขข้อมูล']);
        }
    }

    /**
     * DELETE /api/projects/tasks/{tid}
     */
    public function apiTaskDelete(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        if (!$task) {
            Response::json(['error' => 'ไม่พบข้อมูลงานย่อย'], 404);
        }

        $project = Project::getById($task['project_id'], $userId);
        if (!$project) {
            Response::json(['error' => 'คุณไม่มีสิทธิ์เข้าถึงโครงการนี้'], 403);
        }

        if ($project['user_role'] === 'Viewer') {
            Response::json(['error' => 'คุณมีสิทธิ์ดูเท่านั้น ไม่สามารถลบงานได้'], 403);
        }

        if (ProjectTask::delete($taskId)) {
            ProjectActivity::log($task['project_id'], $userId, 'ลบงานย่อย: "' . $task['title'] . '"');
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถลบงานได้'], 500);
        }
    }

    /**
     * อัปเดตตำแหน่งงานคัมบังหลังจากลากย้ายบอร์ดคัมบังเรียบร้อยแล้ว
     * POST /api/projects/tasks/reorder
     */
    public function apiTaskReorder(): void
    {
        $userId    = Auth::userId();
        $payload   = Request::json();
        $projectId = (int)($payload['project_id'] ?? 0);
        $items     = $payload['items'] ?? [];

        if (!$projectId || empty($items)) {
            Response::json(['error' => 'ข้อมูลโปรเจคหรือโครงสร้างงานไม่ถูกต้อง'], 400);
        }

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        if ($project['user_role'] === 'Viewer') {
            Response::json(['error' => 'คุณมีสิทธิ์ดูเท่านั้น ไม่สามารถจัดเรียงงานได้'], 403);
        }

        ProjectTask::reorder($projectId, $items);
        ProjectActivity::log($projectId, $userId, 'จัดตำแหน่งลำดับบอร์ดคัมบังใหม่');

        Response::json(['ok' => true]);
    }

    // =====================================================
    // --- APIs: Collaboration & Project Members ---
    // =====================================================

    /**
     * GET /api/projects/{id}/members
     */
    public function apiMemberList(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $members = ProjectMember::getMembers($projectId);
        Response::json(['members' => $members]);
    }

    /**
     * POST /api/projects/{id}/members
     */
    public function apiMemberAdd(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        // ต้องเป็นเจ้าของโครงการ (Owner) เท่านั้นจึงจะเพิ่มผู้ร่วมทีมได้
        if (!$project['is_owner']) {
            Response::json(['error' => 'คุณไม่มีสิทธิ์ในการเชิญผู้อื่นเข้าร่วมโครงการนี้'], 403);
        }

        $emailOrUsername = trim(Request::input('email_or_username', ''));
        $role            = Request::input('role', 'Editor');

        if (!$emailOrUsername) {
            Response::json(['error' => 'กรุณาระบุชื่อผู้ใช้หรืออีเมลที่ต้องการเชิญ'], 422);
        }

        // ค้นหาผู้ใช้จากอีเมลหรือชื่อผู้ใช้
        $targetUser = User::findByEmail($emailOrUsername);
        if (!$targetUser) {
            $targetUser = User::findByUsername($emailOrUsername);
        }

        if (!$targetUser) {
            Response::json(['error' => 'ไม่พบผู้ใช้งานนี้ในระบบ กรุณาตรวจสอบอีกครั้ง'], 404);
        }

        $targetUserId = (int)$targetUser['id'];

        // ตรวจสอบว่าผู้ใช้มีสิทธิ์เข้าถึงอยู่แล้วหรือไม่
        if (ProjectMember::hasAccess($projectId, $targetUserId)) {
            Response::json(['error' => 'ผู้ใช้งานนี้เป็นสมาชิกในโครงการนี้อยู่แล้ว'], 422);
        }

        if (ProjectMember::addMember($projectId, $targetUserId, $role)) {
            ProjectActivity::log($projectId, $userId, 'เชิญสมาชิกใหม่ "' . $targetUser['display_name'] . '" เข้าร่วมโครงการด้วยสิทธิ์ ' . $role);
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถเพิ่มสมาชิกได้'], 500);
        }
    }

    /**
     * DELETE /api/projects/{id}/members/{mid}
     */
    public function apiMemberRemove(string $id, string $mid): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;
        $memberId  = (int)$mid;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        // ต้องเป็นเจ้าของโครงการ หรือตัวสมาชิกเองที่ขอกดออกจากกลุ่ม (Leave)
        if (!$project['is_owner'] && $userId !== $memberId) {
            Response::json(['error' => 'คุณไม่มีสิทธิ์ในการนำสมาชิกคนอื่นออกจากโครงการ'], 403);
        }

        // ห้ามเอาเจ้าของโครงการออก
        if ($memberId === (int)$project['user_id']) {
            Response::json(['error' => 'ไม่สามารถลบเจ้าของโครงการออกได้'], 403);
        }

        $memberUser = User::findById($memberId);
        if (!$memberUser) {
            Response::json(['error' => 'ไม่พบข้อมูลสมาชิก'], 404);
        }

        if (ProjectMember::removeMember($projectId, $memberId)) {
            $actText = ($userId === $memberId) 
                ? 'ออกจากการเข้าร่วมโครงการ' 
                : 'ลบสมาชิก "' . $memberUser['display_name'] . '" ออกจากโครงการ';
            ProjectActivity::log($projectId, $userId, $actText);
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถนำสมาชิกออกได้'], 500);
        }
    }

    // =====================================================
    // --- APIs: Live Chat in Projects ---
    // =====================================================

    /**
     * GET /api/projects/{id}/chat
     */
    public function apiChatList(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $messages = ProjectChat::getMessages($projectId);
        Response::json(['messages' => $messages]);
    }

    /**
     * POST /api/projects/{id}/chat
     */
    public function apiChatSend(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $message = trim(Request::input('message', ''));
        if ($message === '') {
            Response::json(['error' => 'กรุณากรอกข้อความ'], 422);
        }

        if (ProjectChat::sendMessage($projectId, $userId, $message)) {
            Response::json(['ok' => true], 201);
        } else {
            Response::json(['error' => 'ไม่สามารถส่งข้อความได้'], 500);
        }
    }

    // =====================================================
    // --- Public Share Links & Guest Collaboration ---
    // =====================================================

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

    /**
     * เปิดใช้งานลิงก์สาธารณะสำหรับโปรเจค
     * POST /api/projects/{id}/share
     */
    public function apiShareEnable(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        if (!$project['is_owner']) {
            Response::json(['error' => 'เฉพาะเจ้าของโครงการเท่านั้นที่สามารถเปิดลิงก์สาธารณะได้'], 403);
        }

        $shareRole = Request::input('share_role', 'Viewer');
        
        // ถ้าเคยมี Token อยู่แล้วให้ใช้ของเดิม หรือสุ่มใหม่
        $token = $project['share_token'] ?: bin2hex(random_bytes(16));

        DB::run(
            'UPDATE projects SET share_token = ?, share_role = ? WHERE id = ?',
            [$token, $shareRole, $projectId]
        );

        $shareUrl = APP_URL . '/project/shared/' . $token;

        Response::json([
            'ok'          => true,
            'share_token' => $token,
            'share_role'  => $shareRole,
            'share_url'   => $shareUrl
        ]);
    }

    /**
     * ปิดการใช้งานลิงก์สาธารณะ
     * DELETE /api/projects/{id}/share
     */
    public function apiShareDisable(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        if (!$project['is_owner']) {
            Response::json(['error' => 'เฉพาะเจ้าของโครงการเท่านั้นที่สามารถปิดลิงก์สาธารณะได้'], 403);
        }

        DB::run(
            'UPDATE projects SET share_token = NULL WHERE id = ?',
            [$projectId]
        );

        Response::json(['ok' => true]);
    }

    /**
     * อัปเดตปรับเปลี่ยนชื่อผู้เยี่ยมชม (Guest Name)
     * POST /api/projects/guest-name
     */
    public function apiSetGuestName(): void
    {
        $name = trim(Request::input('name', ''));
        if ($name === '') {
            Response::json(['error' => 'กรุณากรอกชื่อของคุณ'], 422);
        }

        // เพิ่มคำระบุสร้อยท้ายเพื่อให้ระบุตัวตนได้ชัดเจนว่าเป็น Guest
        $_SESSION['guest_name'] = $name . ' (ผู้เยี่ยมชม)';
        
        Response::json([
            'ok'         => true,
            'guest_name' => $_SESSION['guest_name']
        ]);
    }
}
