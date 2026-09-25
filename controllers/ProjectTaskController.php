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

        $this->requireEditor($project, 'แก้ไขงาน');

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
}
