<?php
// =====================================================
// controllers/ProjectShareController.php — the public link to a project and its guests
// =====================================================

class ProjectShareController
{
    use ProjectAccess;

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

        $this->requireOwner($project, 'เปิดลิงก์สาธารณะ');

        $shareRole = Request::input('share_role', 'Viewer');
        if (!in_array($shareRole, self::GRANTABLE_ROLES, true)) {
            Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);
        }
        
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

        $this->requireOwner($project, 'ปิดลิงก์สาธารณะ');

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
        // Only a visitor who came in through a project share link has a guest
        // name to set; a signed-in member is shown by their own display name.
        if (empty($_SESSION['active_project_share_token'])) {
            Response::json(['error' => 'ใช้ได้เฉพาะผู้เยี่ยมชมผ่านลิงก์แชร์'], 403);
        }

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
