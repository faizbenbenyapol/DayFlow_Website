<?php
// =====================================================
// controllers/ProjectTaskController.php — the tasks on a project board
// =====================================================

class ProjectTaskController
{
    use ProjectAccess;

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

        $this->requireEditor($project, 'เพิ่มงาน');

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
            'checklist' => Request::list('checklist')
        ];

        $json = Request::json();
        if (array_key_exists('assignee_user_id', $json)) {
            $data = self::applyAssigneeUserId($projectId, $data, $json['assignee_user_id']);
        }

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

        $this->requireEditor($project, 'แก้ไขงาน');

        $data = [];
        $allowed = ['title', 'status', 'priority', 'due_date', 'category', 'assignee', 'checklist'];
        $changes = [];

        foreach ($allowed as $field) {
            $val = $field === 'checklist' ? Request::list($field) : Request::input($field);
            if ($val !== null) {
                $data[$field] = $val;
                if ($field === 'checklist') {
                    // ไม่ต้องเทียบข้อความความแตกต่างสำหรับการอัปเดตสถานะเช็คลิสต์ด่วน
                } elseif ($task[$field] != $val) {
                    $changes[] = "$field เปลี่ยนเป็น '$val'";
                }
            }
        }

        $json = Request::json();
        if (array_key_exists('assignee_user_id', $json)) {
            $data = self::applyAssigneeUserId($task['project_id'], $data, $json['assignee_user_id']);

            $oldUserId = isset($task['assignee_user_id']) ? (int)$task['assignee_user_id'] : null;
            $newUserId = isset($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null;
            $newName = self::assigneeName($newUserId, null) ?? 'ไม่ระบุ';
            if ($oldUserId !== $newUserId) {
                $changes[] = 'ผู้รับผิดชอบ เปลี่ยนเป็น \'' . $newName . '\'';
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

        $this->requireEditor($project, 'ลบงาน');

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

        $this->requireEditor($project, 'จัดเรียงงาน');

        ProjectTask::reorder($projectId, $items);
        ProjectActivity::log($projectId, $userId, 'จัดตำแหน่งลำดับบอร์ดคัมบังใหม่');

        Response::json(['ok' => true]);
    }

    /**
     * แปลง assignee_user_id ที่ส่งมาเป็นข้อมูลพร้อมบันทึก
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $data ข้อมูลงานที่จะส่งต่อไปยัง model
     * @param mixed $rawAssigneeUserId ค่าที่ส่งมาจาก client (null, '', 0 หรือ user id)
     * @return array<string, mixed> ข้อมูลงานที่อัปเดตแล้ว
     */
    private static function applyAssigneeUserId(int $projectId, array $data, mixed $rawAssigneeUserId): array
    {
        $assigneeUserId = (int)$rawAssigneeUserId;
        if ($assigneeUserId <= 0) {
            $data['assignee_user_id'] = null;
            $data['assignee'] = null;
            return $data;
        }

        if (ProjectMember::getRole($projectId, $assigneeUserId) === null) {
            Response::json(['error' => 'ผู้รับผิดชอบที่เลือกไม่ใช่สมาชิกของโปรเจคนี้'], 422);
        }

        $data['assignee_user_id'] = $assigneeUserId;
        $data['assignee'] = null;
        return $data;
    }

    /**
     * หาชื่อที่แสดงสำหรับ activity log
     *
     * @param int|null $userId รหัสผู้ใช้ที่ผูกไว้
     * @param string|null $fallback ข้อความสำรองถ้ายังไม่ได้ผูกบัญชี
     * @return string|null ชื่อที่แสดง หรือ null ถ้าไม่มีข้อมูล
     */
    private static function assigneeName(?int $userId, ?string $fallback): ?string
    {
        if ($userId === null || $userId <= 0) {
            return $fallback;
        }
        $user = User::findById($userId);
        if (!$user) {
            return $fallback;
        }
        return $user['display_name'] !== '' && $user['display_name'] !== null
            ? $user['display_name']
            : $user['username'];
    }
}
