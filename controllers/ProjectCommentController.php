<?php
// =====================================================
// controllers/ProjectCommentController.php — ความเห็นต่องาน (P0b slice 4)
//
// ลำดับการตรวจ: หางานที่มองเห็นได้ (ไม่เจอ = 404 เดียวกันหมด, EDGE-P4)
// → แขกจากลิงก์แชร์ไม่เห็นความเห็น (D-5) → บทบาท
// =====================================================

class ProjectCommentController
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
            Response::json(['error' => 'ลิงก์แชร์ไม่แสดงความเห็น'], 403);
        }
        return [$task, $project];
    }

    /**
     * GET /api/projects/tasks/{tid}/comments?after=<id> — ความเห็นเก่าไปใหม่ (after = ดึงเฉพาะของใหม่)
     */
    public function apiList(string $tid): void
    {
        [$task] = $this->loadTask($tid);
        $after = max(0, (int)Request::input('after', 0));
        Response::json(['items' => ProjectComment::listForTask((int)$task['id'], $after)]);
    }

    /**
     * POST /api/projects/tasks/{tid}/comments {body} — สมาชิกขึ้นไป แจ้งคนที่ถูก @กล่าวถึง
     */
    public function apiCreate(string $tid): void
    {
        [$task, $project] = $this->loadTask($tid);
        $this->requireRole($project, 'member', 'แสดงความเห็น');

        $body = trim(str_replace("\xC2\xA0", ' ', (string)Request::input('body', '')));
        if ($body === '') {
            Response::json(['error' => 'พิมพ์ความเห็นก่อนส่ง'], 422);
        }
        if (mb_strlen($body) > ProjectComment::MAX_LENGTH) {
            Response::json(['error' => 'ความเห็นยาวได้ไม่เกิน 5,000 ตัวอักษร'], 422);
        }

        $userId = Auth::userId();
        $id = ProjectComment::create((int)$task['id'], (int)$project['id'], $userId, $body);
        ProjectComment::notifyMentions(
            (int)$project['id'], $body, $userId, 'ในความเห็น',
            $task['title'] . ' · ' . $project['name'],
            '/projects?project=' . (int)$project['id'] . '&task=' . (int)$task['id']
        );

        Response::json(['ok' => true, 'id' => $id], 201);
    }

    /**
     * DELETE /api/projects/comments/{cid} — คนเขียนลบของตัวเองได้ ผู้ดูแลขึ้นไปลบของใครก็ได้
     */
    public function apiDelete(string $cid): void
    {
        $comment = ProjectComment::find((int)$cid);
        $project = $comment ? Project::getById((int)$comment['project_id'], Auth::userId()) : null;
        if (!$comment || !$project || Auth::userId() <= 0) {
            Response::json(['error' => 'ไม่พบความเห็น'], 404);
        }
        if ((int)$comment['user_id'] !== Auth::userId()) {
            $this->requireRole($project, 'admin', 'ลบความเห็นของคนอื่น');
        }
        ProjectComment::delete((int)$comment['id']);
        Response::json(['ok' => true]);
    }
}
