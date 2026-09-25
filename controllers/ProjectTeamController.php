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
     */
    public function apiMemberAdd(string $id): void
    {
        $userId    = Auth::userId();
        $projectId = (int)$id;

        $project = Project::getById($projectId, $userId);
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }

        $this->requireOwner($project, 'เชิญผู้อื่นเข้าร่วมโครงการ');

        $emailOrUsername = trim(Request::input('email_or_username', ''));
        $role            = Request::input('role', 'Editor');
        if (!in_array($role, self::GRANTABLE_ROLES, true)) {
            Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);
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

        $project = $this->loadProject($projectId);
        $this->requireEditor($project, 'ส่งข้อความ');

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
