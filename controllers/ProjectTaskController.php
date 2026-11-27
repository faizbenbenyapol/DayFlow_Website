<?php
// =====================================================
// controllers/ProjectTaskController.php — the tasks on a project board
// =====================================================

class ProjectTaskController
{
    use ProjectAccess;

    /** ชื่อช่องภาษาไทยในบรรทัดประวัติ (แท็บประวัติแสดงให้ผู้ใช้เห็นตรง ๆ) */
    private const FIELD_LABELS = [
        'title' => 'ชื่องาน', 'priority' => 'ความสำคัญ', 'due_date' => 'กำหนดส่ง', 'start_date' => 'วันเริ่ม',
        'category' => 'หมวด', 'assignee' => 'ผู้รับผิดชอบ', 'severity' => 'ความรุนแรง',
        'repro_steps' => 'ขั้นตอนทำซ้ำ', 'expected' => 'ผลที่คาด', 'actual' => 'ผลจริง', 'environment' => 'สภาพแวดล้อม',
    ];

    /** ค่าความสำคัญภาษาไทย */
    private const PRIORITY_LABELS = ['Low' => 'ต่ำ', 'Medium' => 'ปานกลาง', 'High' => 'สูง', 'Critical' => 'วิกฤต'];

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
     * ตรวจความยาวข้อความของงานหนึ่งช่องตามความยาวคอลัมน์ในฐานข้อมูล
     *
     * title ต้องไม่ว่างและไม่เกิน 255 ตัวอักษร ส่วน category กับ assignee ไม่เกิน 50 ตัวอักษร
     * ค่าที่ไม่ผ่านจะปิดคำขอด้วย Response::json(..., 422) ทันที เพราะ Response::json ไม่คืนค่า
     *
     * @param string $field ช่องที่ตรวจ: title, category หรือ assignee
     * @param mixed $value ค่าดิบที่ client ส่งมา
     * @return string ข้อความหลังตัดช่องว่างหัวท้ายแล้ว
     */
    private static function checkedTaskText(string $field, mixed $value): string
    {
        $text = trim((string)$value);
        if ($field === 'title') {
            if ($text === '') {
                Response::json(['error' => 'กรุณาระบุชื่องาน'], 422);
            }
            if (mb_strlen($text) > 255) {
                Response::json(['error' => 'ชื่องานยาวได้ไม่เกิน 255 ตัวอักษร'], 422);
            }
            return $text;
        }

        if (mb_strlen($text) > 50) {
            Response::json([
                'error' => $field === 'category' ? 'หมวดยาวได้ไม่เกิน 50 ตัวอักษร' : 'ชื่อผู้รับผิดชอบยาวได้ไม่เกิน 50 ตัวอักษร',
            ], 422);
        }
        return $text;
    }

    /**
     * ดึงข้อมูลงานคัมบัง รอบงาน ประวัติ และผลสรุปความก้าวหน้า AI ของโครงการร่วมกัน
     * GET /api/projects/{id}/tasks
     *
     * ทุกคนที่เห็นโปรเจค (รวมแขกลิงก์แชร์) เห็นรายการรอบงานด้วย
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
        // แขกลิงก์แชร์ไม่เห็นบรรทัดลับ (ไฟล์แนบ ช่องข้อมูลเพิ่มเติม) ตาม D-5
        $activities = ProjectActivity::getRecent($projectId, 8, $userId > 0);
        $aiInsight  = Project::getAiInsight($projectId, $userId);

        // ส่วนของระบบของแต่ละงาน (รหัสเท่านั้น) อ่านครั้งเดียวแล้วแปะให้ทุกงาน
        $componentIds = ProjectComponent::idsForProject($projectId);
        $tasks = array_map(static function (array $task) use ($componentIds): array {
            $task['component_ids'] = $componentIds[(int)$task['id']] ?? [];
            return $task;
        }, $tasks);

        $payload = [
            'project'    => $project,
            'tasks'      => $tasks,
            'columns'    => ProjectColumn::listForProject($projectId),
            'sprints'    => ProjectSprint::listForProject($projectId),
            // เวอร์ชันพร้อม changelog: แขกลิงก์แชร์เห็นได้ตาม D-5
            'releases'   => ProjectRelease::listForProject($projectId),
            // ขอบงานที่ต้องรอกัน: มีเพียงรหัสงาน แขกลิงก์แชร์จึงเห็นได้ (D-5)
            'blocks'     => ProjectTaskBlock::listForProject($projectId),
            // ชื่อส่วนของระบบ: แขกลิงก์แชร์เห็นได้ (D-5) รหัสของแต่ละงานอยู่ใน component_ids
            'components' => ProjectComponent::listForProject($projectId),
            'activities' => $activities,
            'ai'         => $aiInsight
        ];
        // ลิงก์ภายนอกและรายละเอียดบั๊กเป็นข้อมูลภายใน: แขกลิงก์แชร์ไม่เห็น (D-5)
        if ($userId > 0) {
            $payload['fields'] = ProjectField::listForProject($projectId);
            $payload['links'] = ProjectTaskLink::listForProject($projectId);
        } else {
            $hidden = ['repro_steps', 'expected', 'actual', 'environment'];
            $payload['tasks'] = array_map(static function (array $task) use ($hidden): array {
                foreach ($hidden as $field) {
                    unset($task[$field]);
                }
                $task['custom'] = null;
                return $task;
            }, $tasks);
        }
        Response::json($payload);
    }

    /**
     * POST /api/projects/{id}/tasks
     *
     * สมาชิกขึ้นไปเพิ่มงานได้ ผู้ดูอย่างเดียวอ่านได้อย่างเดียว
     * ชนิดงาน งานแม่ แต้ม และรอบงานที่ส่งมาถูกตรวจใน model ภายใต้ lock ของโปรเจค
     * คืนรหัสงานและเลขงานในโปรเจค (number)
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
        // ข้อความที่ยาวเกินคอลัมน์จะทำให้ฐานข้อมูลโยน error เป็น 500: ปฏิเสธเป็น 422 ก่อน
        $title = self::checkedTaskText('title', $title);
        self::checkedTaskText('category', Request::input('category', ''));
        self::checkedTaskText('assignee', Request::input('assignee', ''));

        // column_id มาก่อน ถ้าไม่ส่งจึงแปลสถานะเดิม ถ้าไม่ส่งทั้งคู่ให้ลงคอลัมน์แรกของ todo
        $column = self::resolveColumn(
            $projectId,
            Request::input('column_id'),
            Request::input('status')
        );

        $json = Request::json();

        // แขกลิงก์แชร์เขียนช่องข้อมูลบั๊กไม่ได้ แม้ลิงก์จะแก้ไขงานได้ (D-5)
        if ($userId <= 0) {
            foreach (ProjectTask::BUG_FIELDS as $field) {
                if (array_key_exists($field, $json)) {
                    Response::json(['error' => 'ลิงก์แชร์แก้ข้อมูลบั๊กไม่ได้'], 403);
                }
            }
        }

        $data = [
            'title'     => $title,
            'priority'  => Request::input('priority', 'Medium'),
            'start_date' => Request::input('start_date', ''),
            'due_date'  => Request::input('due_date', ''),
            'category'  => Request::input('category', ''),
            'assignee'  => Request::input('assignee', ''),
            'checklist' => Request::list('checklist')
        ];
        // ข้อมูลบั๊กส่งค่าดิบให้ model ตรวจ (ค่าว่างกลายเป็น NULL)
        foreach (ProjectTask::BUG_FIELDS as $field) {
            if (array_key_exists($field, $json)) {
                $data[$field] = $json[$field];
            }
        }

        if (array_key_exists('assignee_user_id', $json)) {
            $data = self::applyAssigneeUserId($projectId, $data, $json['assignee_user_id']);
        }
        // ส่งค่าที่ไม่ได้ระบุไปให้ model ตรวจใน lock: kind ว่าง = task, parent/แต้ม/รอบงาน null = ไม่มี
        foreach (['kind', 'parent_id', 'story_points', 'sprint_id', 'release_id'] as $field) {
            if (array_key_exists($field, $json)) {
                $data[$field] = $json[$field];
            }
        }

        try {
            $created = ProjectTask::create($userId, $projectId, $column, $data);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }
        $taskId = $created['id'];
        ProjectActivity::log($projectId, $userId, 'เพิ่มงานใหม่: "' . $title . '"', $taskId);
        if (!empty($data['assignee_user_id'])) {
            self::notifyAssigned($project, $taskId, $title, (int)$data['assignee_user_id'], $userId);
        }

        Response::json(['ok' => true, 'id' => $taskId, 'number' => $created['number']], 201);
    }

    /**
     * PUT /api/projects/tasks/{tid}
     *
     * สมาชิกขึ้นไปแก้งานได้ ผู้ดูอย่างเดียวและแขกถูกปฏิเสธ
     * ชนิดงาน งานแม่ แต้ม และรอบงานถูกตรวจและเขียนใน lock เดียวกันของโปรเจค
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
        $allowed = ['title', 'description', 'priority', 'due_date', 'start_date', 'category', 'assignee', 'checklist'];
        $changes = [];

        foreach ($allowed as $field) {
            $val = $field === 'checklist' ? Request::list($field) : Request::input($field);
            if ($val !== null) {
                // ชื่องาน หมวด และผู้รับผิดชอบต้องไม่ยาวเกินคอลัมน์: ตรวจก่อนเขียนฐานข้อมูล (ชื่องานเก็บแบบตัดช่องว่าง)
                if ($field === 'title') {
                    $val = self::checkedTaskText($field, $val);
                } elseif ($field === 'category' || $field === 'assignee') {
                    self::checkedTaskText($field, $val);
                }
                $data[$field] = $val;
                if ($field === 'checklist') {
                    // ไม่ต้องเทียบข้อความความแตกต่างสำหรับการอัปเดตสถานะเช็คลิสต์ด่วน
                } elseif ($field === 'description') {
                    // คำอธิบายยาวได้หลายย่อหน้า: บันทึกแค่ว่าแก้ ไม่ใส่ข้อความทั้งก้อนลงประวัติ
                    if (mb_strlen((string)$val) > 10000) {
                        Response::json(['error' => 'คำอธิบายยาวได้ไม่เกิน 10,000 ตัวอักษร'], 422);
                    }
                    if ((string)($task['description'] ?? '') !== (string)$val) {
                        $changes[] = 'แก้คำอธิบาย';
                    }
                } elseif ($task[$field] != $val) {
                    $shown = $field === 'priority' ? (self::PRIORITY_LABELS[$val] ?? $val) : ($val === '' ? 'ว่าง' : $val);
                    $changes[] = self::FIELD_LABELS[$field] . " เปลี่ยนเป็น '" . $shown . "'";
                }
            }
        }

        $json = Request::json();

        // แขกลิงก์แชร์เขียนช่องของซอฟต์แวร์ไม่ได้ แม้ลิงก์จะแก้ไขงานได้ (D-5)
        if ($userId <= 0) {
            foreach (ProjectTask::BUG_FIELDS as $field) {
                if (array_key_exists($field, $json)) {
                    Response::json(['error' => 'ลิงก์แชร์แก้ข้อมูลบั๊กไม่ได้'], 403);
                }
            }
            if (array_key_exists('component_ids', $json)) {
                Response::json(['error' => 'ลิงก์แชร์แก้ส่วนของระบบไม่ได้'], 403);
            }
        }

        // ข้อมูลบั๊ก: ส่งค่าดิบให้ model ตรวจ ค่าว่างคือการล้างค่า
        $bugChanged = false;
        foreach (ProjectTask::BUG_FIELDS as $field) {
            if (!array_key_exists($field, $json)) continue;
            $data[$field] = $json[$field];
            $raw = $json[$field];
            $newText = is_string($raw) ? trim($raw) : '';
            $oldText = (string)($task[$field] ?? '');
            if ($oldText !== $newText) $bugChanged = true;
        }
        if ($bugChanged) $changes[] = 'แก้ข้อมูลบั๊ก';

        if (array_key_exists('assignee_user_id', $json)) {
            $data = self::applyAssigneeUserId($task['project_id'], $data, $json['assignee_user_id']);

            $oldUserId = isset($task['assignee_user_id']) ? (int)$task['assignee_user_id'] : null;
            $newUserId = isset($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null;
            $newName = self::assigneeName($newUserId, null) ?? 'ไม่ระบุ';
            if ($oldUserId !== $newUserId) {
                $changes[] = 'ผู้รับผิดชอบ เปลี่ยนเป็น \'' . $newName . '\'';
            }
        }

        // ช่องข้อมูลเพิ่มเติม: ตรวจตามชนิด แล้วเก็บเฉพาะช่องที่ค่าเปลี่ยนจริง
        $customPatch = [];
        $customChanges = [];
        if (array_key_exists('custom', $json)) {
            // แขกแชร์แก้ไขไม่เห็น/ไม่แตะช่องข้อมูลเพิ่มเติม (D-5)
            if ($userId <= 0) {
                Response::json(['error' => 'ลิงก์แชร์แก้ช่องข้อมูลเพิ่มเติมไม่ได้'], 403);
            }

            if (!is_array($json['custom'])) {
                Response::json(['error' => 'ข้อมูลช่องเพิ่มเติมไม่ถูกต้อง'], 422);
            }
            // ตรวจเฉพาะค่าที่ต่างจากเดิม: ค่าที่ไม่ได้แตะ (เช่นคนที่ออกจากโปรเจคไปแล้ว) ต้องไม่ทำให้บันทึกงานไม่ได้
            $old = self::customValues($task);
            $touched = array_filter($json['custom'], static fn(mixed $value, mixed $key): bool => ($old[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
            $fields = ProjectField::listForProject((int)$task['project_id']);
            try {
                $cleaned = ProjectField::cleanValues((int)$task['project_id'], $fields, $touched);
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
            $customPatch = self::customDiff($fields, $old, $cleaned, $customChanges);
        }

        // ชนิดงาน งานแม่ แต้ม และรอบงานอ่านจาก JSON ตรง ๆ: null/'' ของค่าที่ล้างได้คือสั่งล้างค่า
        $structure = [];
        foreach (['kind', 'parent_id', 'story_points', 'sprint_id', 'release_id'] as $field) {
            if (array_key_exists($field, $json)) {
                $structure[$field] = $json[$field];
            }
        }

        if (empty($data) && $moveTo === null && $structure === [] && !array_key_exists('custom', $json)
            && !array_key_exists('component_ids', $json)) {
            Response::json(['error' => 'ไม่มีข้อมูลสำหรับแก้ไข'], 400);
        }

        // ชนิด/งานแม่/แต้ม: model ตรวจและเขียนในล็อกโปรเจคเดียวกัน จึงไม่เห็นงานแม่หรือลูกที่เก่าไปแล้ว
        $structureChanges = [];
        $taskChanged = false;
        if ($structure !== []) {
            try {
                $updated = ProjectTask::updateStructure($taskId, (int)$task['project_id'], $structure);
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
            $structureChanges = $updated['changes'];
            $taskChanged = $updated['changed'];
        }

        // ส่วนของระบบ: แทนทั้งชุด รหัสของโปรเจคอื่นหรือที่ถูกลบแล้วได้ 404 เดียวกัน
        $componentChanged = false;
        if (array_key_exists('component_ids', $json)) {
            if (!is_array($json['component_ids'])) {
                Response::json(['error' => 'ข้อมูลส่วนของระบบไม่ถูกต้อง'], 422);
            }
            try {
                $set = ProjectComponent::setForTask((int)$task['project_id'], $taskId, array_values($json['component_ids']));
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
            if ($set['changed']) {
                $componentChanged = true;
                $taskChanged = true;
                $changes[] = "ส่วนของระบบ เปลี่ยนเป็น '" . ($set['names'] !== [] ? implode(', ', $set['names']) : 'ว่าง') . "'";
            }
        }
        if (!empty($data)) {
            try {
                if (ProjectTask::update($taskId, $data)) {
                    $taskChanged = true;
                }
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
        }
        if ($structureChanges !== []) {
            $changes = array_merge($changes, $structureChanges);
        }
        // แยก "งานเปลี่ยน" กับ "ค่าช่องเปลี่ยน" เพราะสองเรื่องนี้ลงฟีดกิจกรรมคนละบรรทัด
        if ($customPatch) {
            ProjectTask::patchCustom($taskId, $customPatch);
        }
        if ($moveTo !== null) {
            try {
                ProjectTask::moveTo($taskId, (int)$task['project_id'], $moveTo);
            } catch (ProjectColumnException $e) {
                self::fail($e);
            }
            $taskChanged = true;
            $changes[] = 'คอลัมน์ เปลี่ยนเป็น \'' . $moveTo['name'] . '\'';
        }

        $changed = $taskChanged || $customPatch;
        if ($changed) {
            $actText = 'อัปเดตงาน "' . ($data['title'] ?? $task['title']) . '"';
            // กันบรรทัดสาธารณะว่างเมื่อเปลี่ยนเฉพาะช่อง
            if ($taskChanged && ($changes || !$customChanges)) {
                ProjectActivity::log($task['project_id'], $userId, $actText . ($changes ? ' (' . implode(', ', $changes) . ')' : ''), $taskId);
            }
            // ค่าช่องข้อมูลเพิ่มเติมแยกเป็นบรรทัดลับของตัวเอง แขกลิงก์แชร์จึงไม่เห็นชื่อและค่า (D-5)
            if ($customChanges) {
                ProjectActivity::log($task['project_id'], $userId, $actText . ' (' . implode(', ', $customChanges) . ')', $taskId, true);
            }

            // ผู้รับผิดชอบเปลี่ยนเป็นคนใหม่ → แจ้งคนนั้น (send() ข้ามเองถ้ามอบให้ตัวเอง)
            $newAssignee = isset($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : 0;
            if ($newAssignee > 0 && $newAssignee !== (int)($task['assignee_user_id'] ?? 0)) {
                self::notifyAssigned($project, $taskId, (string)($data['title'] ?? $task['title']), $newAssignee, $userId);
            }

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
     * GET /api/projects/tasks/{tid}/history — ประวัติการเปลี่ยนของงาน (แท็บประวัติในแผงรายละเอียด)
     *
     * ทุกคนที่เห็นโปรเจคดูได้ งานที่ไม่มี งานที่ลบแล้ว และงานของโปรเจคที่มองไม่เห็นได้ 404 เดียวกัน (EDGE-P4)
     */
    public function apiTaskHistory(string $tid): void
    {
        $taskId = (int)$tid;
        $task = ProjectTask::getById($taskId);
        $project = $task ? Project::getById((int)$task['project_id'], Auth::userId()) : null;
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        Response::json(['items' => ProjectActivity::forTask($taskId, 50, Auth::userId() > 0)]);
    }

    /**
     * DELETE /api/projects/tasks/{tid}
     *
     * สมาชิกขึ้นไปลบงานได้ ผู้ดูอย่างเดียวและแขกถูกปฏิเสธ
     * งานย่อย (sub) ของงานนี้ถูกลบตามไปด้วย และคืนจำนวนที่ลบตาม (deleted_subtasks)
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

        try {
            $deleted = ProjectTask::delete($taskId, (int)$task['project_id']);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }
        ProjectActivity::log($task['project_id'], $userId, 'ลบงาน: "' . $task['title'] . '"');
        Response::json(['ok' => true, 'deleted_subtasks' => $deleted['subtasks']]);
    }

    /**
     * POST /api/projects/tasks/{tid}/blocks
     *
     * ผูกงานนี้กับงานอื่น: relation = waits คือ "งานนี้ต้องรออีกงาน"
     * ส่วน blocks คือ "งานนี้ขวางอีกงาน" สมาชิกขึ้นไปผูกได้ ผู้ดูอย่างเดียว 403
     * และงานที่มองไม่เห็นตอบ 404 เดียวกับงานที่ไม่มี (EDGE-P4)
     * คืนขอบทั้งหมดของโปรเจคให้ client วาดบอร์ดใหม่โดยไม่ต้องโหลดซ้ำ
     */
    public function apiBlockCreate(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        $this->requireRole($project, 'member', 'แก้ไขงาน');

        $json = Request::json();
        $relation = $json['relation'] ?? null;
        if ($relation !== 'waits' && $relation !== 'blocks') {
            Response::json(['error' => 'ความสัมพันธ์ไม่ถูกต้อง'], 422);
        }
        $otherId = $json['other_id'] ?? null;
        if (!is_int($otherId) || $otherId <= 0) {
            Response::json(['error' => 'ข้อมูลงานไม่ถูกต้อง'], 422);
        }

        $projectId = (int)$task['project_id'];
        try {
            // waits: งานนี้รออีกงาน → อีกงานเป็น blocker; blocks: กลับทิศ
            if ($relation === 'waits') {
                ProjectTaskBlock::link($projectId, $otherId, $taskId, $userId);
            } else {
                ProjectTaskBlock::link($projectId, $taskId, $otherId, $userId);
            }
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        Response::json(['ok' => true, 'blocks' => ProjectTaskBlock::listForProject($projectId)], 201);
    }

    /**
     * DELETE /api/projects/tasks/{tid}/blocks/{oid}
     *
     * เอาความสัมพันธ์ระหว่างงานนี้ออก ไม่ว่าฝั่งไหนเป็น blocker ก็ตาม
     * สมาชิกขึ้นไปทำได้ ผู้ดูอย่างเดียว 403 และงานที่มองไม่เห็นตอบ 404 เดียวกัน
     * คืนขอบทั้งหมดของโปรเจคหลังลบ
     */
    public function apiBlockDelete(string $tid, string $oid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        $this->requireRole($project, 'member', 'แก้ไขงาน');

        $projectId = (int)$task['project_id'];
        try {
            ProjectTaskBlock::unlink($projectId, $taskId, (int)$oid, $userId);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        Response::json(['ok' => true, 'blocks' => ProjectTaskBlock::listForProject($projectId)]);
    }

    /**
     * POST /api/projects/tasks/{tid}/links
     *
     * เพิ่มลิงก์ภายนอกให้งาน สมาชิกขึ้นไปทำได้ ลิงก์แชร์ทำไม่ได้ (403)
     * URL รับเฉพาะ http/https (model ตรวจ) งานที่มองไม่เห็นตอบ 404 เดียวกับงานที่ไม่มี
     *
     * @param string $tid รหัสงานจาก URL
     */
    public function apiLinkCreate(string $tid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        if ($userId <= 0) {
            Response::json(['error' => 'ลิงก์แชร์แก้ลิงก์ภายนอกไม่ได้'], 403);
        }
        $this->requireRole($project, 'member', 'แก้ไขงาน');

        $json = Request::json();
        $projectId = (int)$task['project_id'];
        try {
            $created = ProjectTaskLink::create($projectId, $taskId, $json['url'] ?? null, $json['label'] ?? null);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        $shown = $created['label'] ?? $created['url'];
        ProjectActivity::log($projectId, $userId, 'เพิ่มลิงก์ "' . $shown . '"', $taskId);
        Response::json(['ok' => true, 'links' => ProjectTaskLink::listForProject($projectId)], 201);
    }

    /**
     * DELETE /api/projects/tasks/{tid}/links/{lid}
     *
     * เอาลิงก์ของงานออก สมาชิกขึ้นไปทำได้ ลิงก์แชร์ทำไม่ได้ (403)
     * ลิงก์ของงานอื่น ลิงก์ที่ถูกลบ หรืออยู่คนละโปรเจค ตอบ 404 เดียวกัน
     *
     * @param string $tid รหัสงานจาก URL
     * @param string $lid รหัสลิงก์จาก URL
     */
    public function apiLinkDelete(string $tid, string $lid): void
    {
        $userId = Auth::userId();
        $taskId = (int)$tid;

        $task = ProjectTask::getById($taskId);
        $project = null;
        if ($task) {
            $project = Project::getById((int)$task['project_id'], $userId);
        }
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        if ($userId <= 0) {
            Response::json(['error' => 'ลิงก์แชร์แก้ลิงก์ภายนอกไม่ได้'], 403);
        }
        $this->requireRole($project, 'member', 'แก้ไขงาน');

        $projectId = (int)$task['project_id'];
        try {
            $deleted = ProjectTaskLink::delete($projectId, $taskId, (int)$lid);
        } catch (ProjectColumnException $e) {
            self::fail($e);
        }

        $shown = $deleted['label'] ?? $deleted['url'];
        ProjectActivity::log($projectId, $userId, 'เอาลิงก์ "' . $shown . '" ออก', $taskId);
        Response::json(['ok' => true, 'links' => ProjectTaskLink::listForProject($projectId)]);
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
     * ค่าช่องข้อมูลเดิมของงาน (JSON ที่อ่านไม่ได้ถือว่าว่าง)
     *
     * @param array<string, mixed> $task แถวงาน
     * @return array<mixed> {รหัสช่อง: ค่า}
     */
    private static function customValues(array $task): array
    {
        $values = json_decode((string)($task['custom'] ?? ''), true);
        return is_array($values) ? $values : [];
    }

    /**
     * เทียบค่าช่องข้อมูลที่ตรวจแล้วกับค่าเดิมของงาน คืนเฉพาะช่องที่เปลี่ยน
     *
     * แผงรายละเอียดส่งทุกช่องมาทุกครั้งที่บันทึก ถ้าไม่กรอง ประวัติจะมีบรรทัด "เปลี่ยน" ทั้งที่ไม่ได้แก้
     *
     * @param list<array<string, mixed>> $fields ช่องของโปรเจค
     * @param array<mixed> $old ค่าเดิมของงานจาก customValues()
     * @param array<string, mixed> $cleaned ผลของ ProjectField::cleanValues()
     * @param list<string> $changes รายการข้อความประวัติ (เพิ่มบรรทัดของช่องที่เปลี่ยน)
     * @return array<string, mixed> แพตช์สำหรับ ProjectTask::patchCustom()
     */
    private static function customDiff(array $fields, array $old, array $cleaned, array &$changes): array
    {
        $byId = array_column($fields, null, 'id');

        $patch = [];
        foreach ($cleaned as $key => $value) {
            if (($old[$key] ?? null) === $value) continue;
            $patch[$key] = $value;
            $field = $byId[(int)$key];
            $shown = match (true) {
                $value === null => 'ว่าง',
                $value === true => 'ใช่',
                $field['kind'] === 'person' => self::assigneeName((int)$value, null) ?? 'ไม่ระบุ',
                default => (string)$value,
            };
            // action เป็น VARCHAR(255); เก็บแค่ตัวอย่างค่า
            $shown = mb_strimwidth($shown, 0, 60, '…');
            $changes[] = $field['name'] . " เปลี่ยนเป็น '" . $shown . "'";
        }
        return $patch;
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
     * แจ้งผู้รับผิดชอบคนใหม่ว่าได้งาน (กระดิ่ง Telegram Push) ไม่แจ้งเมื่อมอบให้ตัวเอง
     *
     * @param array<string, mixed> $project โปรเจคของงาน (ใช้ชื่อและ id ทำลิงก์)
     * @param int $taskId งานที่ได้รับมอบหมาย
     * @param string $title ชื่องาน
     * @param int $assigneeId ผู้รับผิดชอบคนใหม่
     * @param int $actorId คนที่มอบหมาย
     */
    private static function notifyAssigned(array $project, int $taskId, string $title, int $assigneeId, int $actorId): void
    {
        Notification::send(
            $assigneeId,
            $actorId,
            'assign',
            (self::assigneeName($actorId, null) ?? 'มีคน') . ' มอบหมาย "' . $title . '" ให้คุณ',
            (string)$project['name'],
            '/projects?project=' . (int)$project['id'] . '&task=' . $taskId
        );
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
