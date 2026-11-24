<?php
// =====================================================
// controllers/ProjectTeamController.php — project members and the project chat
// =====================================================

class ProjectTeamController
{
    use ProjectAccess;

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
     *
     * ผู้ดูแลหรือเจ้าของเชิญสมาชิกได้ บทบาทต้องอยู่ใน MEMBER_ROLES
     * และการเชิญเป็นผู้ดูแล (Admin) เป็นของเจ้าของเท่านั้น
     */
    public function apiMemberAdd(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);
        $this->requireRole($project, 'admin', 'เชิญผู้อื่นเข้าร่วมโครงการ');

        $emailOrUsername = trim(Request::input('email_or_username', ''));
        $role            = (string)Request::input('role', 'Editor');
        if (!in_array($role, ProjectMember::MEMBER_ROLES, true)) {
            Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);
        }
        if ($role === 'Admin' && empty($project['is_owner'])) {
            Response::json(['error' => 'เฉพาะเจ้าของโครงการเท่านั้นที่ตั้งผู้ดูแลได้'], 403);
        }

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
            ProjectActivity::log($projectId, $userId, 'เชิญสมาชิกใหม่ "' . $targetUser['display_name'] . '" เข้าร่วมโครงการด้วยสิทธิ์ ' . ProjectMember::roleLabel($role));
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถเพิ่มสมาชิกได้'], 500);
        }
    }

    /**
     * หาแถวสมาชิกของโปรเจคนี้ หรือหยุดด้วย 404 เดียวกันทุกกรณี
     *
     * คืนบทบาทปัจจุบันเมื่อ $memberId เป็นสมาชิกของโปรเจคนี้และไม่ใช่เจ้าของ
     * เจ้าของ บัญชีที่ไม่มีอยู่ และคนของโปรเจคอื่นได้ 404 ข้อความเดียวกัน
     *
     * @param array<string, mixed> $project โปรเจคที่ผู้เรียกเห็นได้
     * @param int $memberId รหัสผู้ใช้จาก URL
     * @return string บทบาทปัจจุบันของสมาชิก
     */
    private static function memberRoleOr404(array $project, int $memberId): string
    {
        $role = ProjectMember::getRole((int)$project['id'], $memberId);
        if ($role === null || $role === 'Owner') {
            Response::json(['error' => 'ไม่พบสมาชิก'], 404);
        }
        return $role;
    }

    /**
     * PUT /api/projects/{id}/members/{mid}
     *
     * เจ้าของตั้งได้ทั้ง Admin/Editor/Viewer ผู้ดูแลเปลี่ยนได้แค่สมาชิก/ดูอย่างเดียว
     * เป็นสมาชิก/ดูอย่างเดียว และเปลี่ยนผู้ดูแลคนอื่นไม่ได้
     */
    public function apiMemberRole(string $id, string $mid): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;
        $memberId  = (int)$mid;

        $project = $this->loadProject($projectId);
        $this->requireRole($project, 'admin', 'เปลี่ยนสิทธิ์สมาชิก');

        $currentRole = self::memberRoleOr404($project, $memberId);

        $role = (string)Request::input('role', '');
        if (!in_array($role, ProjectMember::MEMBER_ROLES, true)) {
            Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);
        }

        if (empty($project['is_owner'])) {
            $canChange = in_array($currentRole, ['Editor', 'Viewer'], true)
                && in_array($role, ['Editor', 'Viewer'], true);
            if (!$canChange) {
                Response::json(['error' => 'ผู้ดูแลเปลี่ยนได้เฉพาะสมาชิกหรือผู้ดูอย่างเดียว'], 403);
            }
        }

        if (!ProjectMember::updateRole($projectId, $memberId, $role)) {
            Response::json(['error' => 'ไม่สามารถเปลี่ยนสิทธิ์ได้'], 500);
        }

        $memberUser = User::findById($memberId);
        $name = $memberUser['display_name'] ?? ('#' . $memberId);
        ProjectActivity::log(
            $projectId,
            $userId,
            'เปลี่ยนสิทธิ์ "' . $name . '" จาก ' . ProjectMember::roleLabel($currentRole) . ' เป็น ' . ProjectMember::roleLabel($role)
        );
        Response::json(['ok' => true]);
    }

    /**
     * DELETE /api/projects/{id}/members/{mid}
     *
     * เจ้าของเอาสมาชิกคนไหนก็ได้ ผู้ดูแลเอาสมาชิก/ดูอย่างเดียวได้แต่ผู้ดูแลด้วยกันไม่ได้
     * สมาชิกทุกคนออกเองได้ (ยกเว้นเจ้าของ) และเจ้าของถูกเอาออกไม่ได้
     */
    public function apiMemberRemove(string $id, string $mid): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;
        $memberId  = (int)$mid;

        $project = $this->loadProject($projectId);
        $isSelf   = $userId === $memberId;
        $isOwner  = !empty($project['is_owner']);

        // ออกเองได้ทุกบทบาท แต่การเอาคนอื่นออกต้องเป็นผู้ดูแลขึ้นไป
        if (!$isSelf) {
            $this->requireRole($project, 'admin', 'นำสมาชิกออกจากโครงการ');
        }

        // ห้ามเอาเจ้าของโครงการออก
        if ($memberId === (int)$project['user_id']) {
            Response::json(['error' => 'ไม่สามารถลบเจ้าของโครงการออกได้'], 403);
        }

        $currentRole = self::memberRoleOr404($project, $memberId);

        // ผู้ดูแลเอาผู้ดูแลคนอื่นออกไม่ได้
        if (!$isSelf && !$isOwner && $currentRole === 'Admin') {
            Response::json(['error' => 'ผู้ดูแลไม่สามารถนำผู้ดูแลคนอื่นออกจากโครงการได้'], 403);
        }

        $memberUser = User::findById($memberId);
        if (!$memberUser) {
            Response::json(['error' => 'ไม่พบสมาชิก'], 404);
        }

        if (ProjectMember::removeMember($projectId, $memberId)) {
            $actText = $isSelf
                ? 'ออกจากการเข้าร่วมโครงการ'
                : 'ลบสมาชิก "' . $memberUser['display_name'] . '" ออกจากโครงการ';
            ProjectActivity::log($projectId, $userId, $actText);
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่สามารถนำสมาชิกออกได้'], 500);
        }
    }

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
     *
     * สมาชิกขึ้นไปส่งข้อความได้ ผู้ดูอย่างเดียวและแขกอ่านได้อย่างเดียว
     */
    public function apiChatSend(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = $this->loadProject($projectId);
        $this->requireRole($project, 'member', 'ส่งข้อความ');

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
}
