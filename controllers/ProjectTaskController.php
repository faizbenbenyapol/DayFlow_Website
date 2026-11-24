<?php
// =====================================================
// controllers/ProjectTaskController.php — the tasks on a project board
// =====================================================

class ProjectTaskController
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
            'columns'    => ProjectColumn::listForProject($projectId),
            'activities' => $activities,
            'ai'         => $aiInsight
        ]);
    }

    /**
     * POST /api/projects/{id}/tasks
     *
     * สมาชิกขึ้นไปเพิ่มงานได้ ผู้ดูอย่างเดียวอ่านได้อย่างเดียว
     */
    public function apiTaskCreate(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $this->requireRole($project, 'member', 'เพิ่มงาน');

        $title = trim(Request::input('title', ''));
        if (!$title) {
            Response::json(['error' => 'กรุณาระบุชื่องาน'], 422);
        }

        // column_id มาก่อน ถ้าไม่ส่งจึงแปลสถานะเดิม ถ้าไม่ส่งทั้งคู่ให้ลงคอลัมน์แรกของ todo
        $column = self::resolveColumn(
            $projectId,
            Request::input('column_id'),
            Request::input('status')
        );

        $data = [
            'title'     => $title,
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

        try {
            $taskId = ProjectTask::create($userId, $projectId, $column, $data);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }
        ProjectActivity::log($projectId, $userId, 'เพิ่มงานย่อยใหม่: "' . $title . '"');

        Response::json(['ok' => true, 'id' => $taskId], 201);
    }

    /**
     * PUT /api/projects/tasks/{tid}
     *
     * สมาชิกขึ้นไปแก้งานได้ ผู้ดูอย่างเดียวและแขกถูกปฏิเสธ
     */
    public function apiTaskUpdate(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }

        // EDGE-P4: งานที่ไม่มี งานของคนอื่น และงานที่ถูกลบ ต้องตอบเหมือนกัน
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }

        $this->requireRole($project, 'member', 'แก้ไขงาน');

        // เปลี่ยนคอลัมน์ผ่าน column_id หรือสถานะเดิม (ถ้าส่งมา)
        $columnInput = Request::input('column_id');
        $statusInput = Request::input('status');
        $moveTo = null;
        if ($columnInput !== null || $statusInput !== null) {
            $moveTo = self::resolveColumn((int)$task['project_id'], $columnInput, $statusInput);
        }
        // บอร์ดเดิมส่ง status ทุกครั้งที่บันทึก: คอลัมน์เดิมจึงไม่ใช่การย้าย
        if ($moveTo !== null && (int)$moveTo['id'] === (int)($task['column_id'] ?? 0)) {
            $moveTo = null;
        }

        $data = [];
        $allowed = ['title', 'priority', 'due_date', 'category', 'assignee', 'checklist'];
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

        if (empty($data) && $moveTo === null) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        $changed = false;
        if (!empty($data)) {
            $changed = ProjectTask::update($taskId, $data);
        }
        if ($moveTo !== null) {
            try {
                ProjectTask::moveTo($taskId, (int)$task['project_id'], $moveTo);
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
            $changed = true;
            $changes[] = 'คอลัมน์ เปลี่ยนเป็น \'' . $moveTo['name'] . '\'';
        }

        if ($changed) {
            // ดึงกิจกรรม
            $actText = 'อัปเดตงานย่อย "' . ($data['title'] ?? $task['title']) . '"';
            if (!empty($changes)) {
                $actText .= ' (' . implode(', ', $changes) . ')';
            }
            ProjectActivity::log($task['project_id'], $userId, $actText);

            // แจ้ง Telegram เมื่อย้ายงานเข้าคอลัมน์กลุ่ม done จากกลุ่มอื่น
            if ($moveTo !== null && $moveTo['grp'] === 'done' && $task['status'] !== 'Done') {
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
     *
     * สมาชิกขึ้นไปลบงานได้ ผู้ดูอย่างเดียวและแขกถูกปฏิเสธ
     */
    public function apiTaskDelete(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }

        // EDGE-P4: งานที่ไม่มี งานของคนอื่น และงานที่ถูกลบ ต้องตอบเหมือนกัน
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }

        $this->requireRole($project, 'member', 'ลบงาน');

        if (ProjectTask::delete($taskId)) {
            ProjectActivity::log($task['project_id'], $userId, 'ลบงานย่อย: "' . $task['title'] . '"');
            Response::json(['ok' => true]);
        } else {
            // อีกคำขอลบงานนี้ไปก่อนแล้ว: ตอบแบบ id ที่ถูกลบ (EDGE-P4) ไม่ใช่ 500
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
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

        if (!$projectId || !is_array($items) || $items === []) {
            Response::json(['error' => 'ข้อมูลโปรเจคหรือโครงสร้างงานไม่ถูกต้อง'], 400);
        }

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $this->requireRole($project, 'member', 'จัดเรียงงาน');

        // โหลดคอลัมน์ครั้งเดียวแล้วใช้ทั้งการตรวจ column_id และการแปลสถานะเดิม
        $columns = ProjectColumn::listForProject($projectId);
        $byId = [];
        foreach ($columns as $column) {
            $byId[(int)$column['id']] = $column;
        }

        $normalized = [];
        foreach (array_values($items) as $item) {
            if (!is_array($item)) {
                Response::json(['error' => 'ข้อมูลงานไม่ถูกต้อง'], 422);
            }
            $column = null;
            if (array_key_exists('column_id', $item) && $item['column_id'] !== null && $item['column_id'] !== '') {
                $column = $byId[(int)$item['column_id']] ?? null;
                if (!$column) {
                    Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
                }
            } elseif (isset($item['status'])) {
                $legacy = is_string($item['status']) ? $item['status'] : '';
                $grp = ProjectColumn::LEGACY_STATUS_GROUPS[$legacy] ?? null;
                if ($grp === null) {
                    Response::json(['error' => 'สถานะไม่ถูกต้อง'], 422);
                }
                $column = ProjectColumn::pickGroupColumn($columns, $grp, $legacy === 'Review');
                if (!$column) {
                    Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
                }
            } else {
                Response::json(['error' => 'ข้อมูลงานไม่ถูกต้อง'], 422);
            }

            $normalized[] = [
                'id'        => (int)($item['id'] ?? 0),
                'column_id' => (int)$column['id'],
                'position'  => (int)($item['position'] ?? 0),
            ];
        }

        try {
            ProjectTask::reorder($projectId, $normalized);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }
        ProjectActivity::log($projectId, $userId, 'จัดตำแหน่งลำดับบอร์ดคัมบังใหม่');

        Response::json(['ok' => true]);
    }

    /**
     * แปลง column_id หรือสถานะเดิมให้เป็นคอลัมน์ของโปรเจค
     *
     * ถ้าไม่ส่งทั้งคู่จะใช้คอลัมน์แรกของ todo (ใช้ตอนสร้างงาน)
     * หยุดด้วย 404 "ไม่พบคอลัมน์" เมื่อไม่ใช่คอลัมน์ของโปรเจค
     * และ 422 "สถานะไม่ถูกต้อง" เมื่อสถานะเดิมไม่รู้จัก
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $columnId ค่า column_id ที่ client ส่งมา (null = ไม่ได้ส่ง)
     * @param mixed $legacyStatus ค่า status เดิมที่ client ส่งมา (null = ไม่ได้ส่ง)
     * @return array<string, mixed> คอลัมน์ที่เลือก
     */
    private static function resolveColumn(int $projectId, mixed $columnId, mixed $legacyStatus): array
    {
        if ($columnId !== null) {
            $column = ProjectColumn::findInProject($projectId, (int)$columnId);
            if (!$column) {
                Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
            }
            return $column;
        }

        if ($legacyStatus !== null) {
            $column = ProjectColumn::forLegacyStatus($projectId, (string)$legacyStatus);
            if (!$column) {
                Response::json(['error' => 'สถานะไม่ถูกต้อง'], 422);
            }
            return $column;
        }

        $column = ProjectColumn::forLegacyStatus($projectId, 'To Do');
        if (!$column) {
            Response::json(['error' => 'ไม่พบคอลัมน์'], 404);
        }
        return $column;
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
