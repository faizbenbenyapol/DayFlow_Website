<?php
// =====================================================
// controllers/ProjectAttachmentController.php — ไฟล์แนบของงาน (P0b slice 5, D-4)
//
// ทางเดียวที่คนอื่นเปิดไฟล์ส่วนตัวของสมาชิกได้ จึงตรวจครบทุกครั้ง:
// งานมองเห็นได้ (ไม่เจอ = 404 เดียวกัน) → ไม่ใช่แขกลิงก์แชร์ (D-5) → ไฟล์ผูกกับงานนี้จริง → บทบาท
// =====================================================

// FileModel อยู่ใน models/File.php ชื่อไม่ตรงกับคลาส autoloader จึงหาไม่เจอ
require_once ROOT . '/models/File.php';

class ProjectAttachmentController
{
    use ProjectAccess;

    /**
     * โหลดงานและโปรเจคที่ผู้เรียกมองเห็น และกันแขกจากลิงก์แชร์
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [งาน, โปรเจค]
     */
    private function loadTask(string $tid): array
    {
        $task = ProjectTask::getById((int)$tid);
        $project = $task ? Project::getById((int)$task['project_id'], Auth::userId()) : null;
        if (!$task || !$project) {
            Response::json(['error' => 'ไม่พบข้อมูลงาน'], 404);
        }
        if (Auth::userId() <= 0) {
            Response::json(['error' => 'ลิงก์แชร์ไม่แสดงไฟล์แนบ'], 403);
        }
        return [$task, $project];
    }

    /** หยุดด้วย 422 เมื่องานมีไฟล์แนบครบจำนวนแล้ว (ตรวจก่อนอัปโหลดให้รู้เร็ว ตัวตัดสินจริงคือ linkIfRoom ใน lock) */
    private static function assertRoom(int $taskId): void
    {
        if (ProjectAttachment::countForTask($taskId) >= ProjectAttachment::MAX_PER_TASK) {
            self::full();
        }
    }

    /** ตอบ 422 งานมีไฟล์แนบครบแล้ว */
    private static function full(): never
    {
        Response::json(['error' => 'แนบได้สูงสุด ' . ProjectAttachment::MAX_PER_TASK . ' ไฟล์ต่องาน'], 422);
    }

    /**
     * GET /api/projects/tasks/{tid}/files — รายการไฟล์แนบ (ทุกคนในโปรเจค ยกเว้นแขก)
     */
    public function apiList(string $tid): void
    {
        [$task] = $this->loadTask($tid);
        Response::json(['items' => ProjectAttachment::listForTask((int)$task['id'])]);
    }

    /**
     * POST /api/projects/tasks/{tid}/files — อัปโหลดจากเครื่อง ลงคลังของคนแนบในโฟลเดอร์ของโปรเจค แล้วผูกกับงาน
     */
    public function apiUpload(string $tid): void
    {
        [$task, $project] = $this->loadTask($tid);
        $this->requireRole($project, 'member', 'แนบไฟล์');
        self::assertRoom((int)$task['id']);
        $upload = FileStore::acceptUpload($_FILES['file'] ?? null);

        $userId = Auth::userId();
        $store = FileStore::forUser($userId);
        try {
            $folderId = ProjectAttachment::folderFor($store, $userId, (string)$project['name']);
            $fileId = $store->upload($upload['tmp'], $upload['name'], $upload['mime'], $upload['size'], $folderId);
        } catch (DriveError $e) {
            $store->failDrive($e);
        } catch (RuntimeException) {
            Response::json(['error' => 'บันทึกไฟล์ไม่สำเร็จ'], 500);
        }
        // ระหว่างอัปโหลดอีกคนอาจแนบจนครบ ไฟล์ยังอยู่ในคลังของคนแนบ แค่ไม่ได้ผูกกับงาน
        if (!ProjectAttachment::linkIfRoom((int)$project['id'], (int)$task['id'], $fileId, $userId)) {
            self::full();
        }
        ProjectActivity::log((int)$project['id'], $userId, 'แนบไฟล์ "' . $upload['name'] . '"', (int)$task['id'], true);
        Response::json(['ok' => true, 'file_id' => $fileId], 201);
    }

    /**
     * POST /api/projects/tasks/{tid}/files/link {file_id} — ผูกไฟล์ที่มีอยู่แล้วในคลังของตัวเอง
     * ไฟล์ของคนอื่นหรือที่ไม่มีตอบ 404 เดียวกัน ไม่บอกว่ามีอยู่
     */
    public function apiLink(string $tid): void
    {
        [$task, $project] = $this->loadTask($tid);
        $this->requireRole($project, 'member', 'แนบไฟล์');

        $userId = Auth::userId();
        $file = FileModel::getById((int)Request::input('file_id', 0), $userId);
        if (!$file || $file['type'] !== 'file') {
            Response::json(['error' => 'ไม่พบไฟล์'], 404);
        }
        self::assertRoom((int)$task['id']);
        if (!ProjectAttachment::linkIfRoom((int)$project['id'], (int)$task['id'], (int)$file['id'], $userId)) {
            self::full();
        }
        ProjectActivity::log((int)$project['id'], $userId, 'แนบไฟล์ "' . $file['name'] . '"', (int)$task['id'], true);
        Response::json(['ok' => true, 'file_id' => (int)$file['id']], 201);
    }

    /**
     * GET /api/projects/tasks/{tid}/files/{fid} — ดาวน์โหลดไฟล์แนบ (ทุกคนในโปรเจค ยกเว้นแขก)
     * ไฟล์ที่ไม่ได้ผูกกับงานนี้ได้ 404 แม้จะเป็นไฟล์จริงของคนในโปรเจค
     */
    public function apiDownload(string $tid, string $fid): never
    {
        [$task] = $this->loadTask($tid);
        $file = ProjectAttachment::findOnTask((int)$task['id'], (int)$fid);
        if (!$file || (!$file['file_path'] && !$file['drive_id'])) {
            Response::json(['error' => 'ไม่พบไฟล์'], 404);
        }
        FileStore::send($file, [
            'Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'),
            'Content-Disposition: ' . contentDisposition((string)$file['name']),
            'X-Content-Type-Options: nosniff',
            'Cache-Control: no-store',
        ]);
    }

    /**
     * DELETE /api/projects/tasks/{tid}/files/{fid} — ถอดไฟล์ออกจากงาน (ไฟล์ในคลังยังอยู่)
     * คนแนบถอดของตัวเองได้ ผู้ดูแลขึ้นไปถอดของใครก็ได้
     */
    public function apiUnlink(string $tid, string $fid): void
    {
        [$task, $project] = $this->loadTask($tid);
        $link = ProjectAttachment::findOnTask((int)$task['id'], (int)$fid);
        if (!$link) {
            Response::json(['error' => 'ไม่พบไฟล์'], 404);
        }
        $this->requireRole($project, (int)$link['added_by'] === Auth::userId() ? 'member' : 'admin', 'ถอดไฟล์แนบ');
        ProjectAttachment::unlink((int)$task['id'], (int)$fid);
        ProjectActivity::log((int)$project['id'], Auth::userId(), 'ถอดไฟล์แนบ "' . $link['name'] . '"', (int)$task['id'], true);
        Response::json(['ok' => true]);
    }
}
