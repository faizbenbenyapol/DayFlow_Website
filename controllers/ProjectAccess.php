<?php
// =====================================================
// controllers/ProjectAccess.php — ใครเห็นและแก้โปรเจคได้
// =====================================================

/**
 * ใช้ร่วมกันทุก controller ของโปรเจค: โหลดโปรเจคที่ผู้เรียกมองเห็น แล้วตรวจบทบาท
 * ด้วย requireRole() ที่เดียว
 */
trait ProjectAccess
{
    /**
     * โหลดโปรเจคที่ผู้เรียกมองเห็น หรือหยุดด้วย 404
     *
     * Project::getById() กรองให้แล้วว่าเป็นเจ้าของ สมาชิก หรือแขกที่ถือลิงก์แชร์
     * และคืน `user_role` กับ `is_owner` ไว้ให้ requireRole()
     *
     * @return array<string, mixed> แถวโปรเจค
     */
    private function loadProject(int $projectId): array
    {
        $project = Project::getById($projectId, Auth::userId());
        if (!$project) {
            Response::json(['error' => 'ไม่พบข้อมูลโปรเจค'], 404);
        }
        return $project;
    }

    /**
     * หยุดเมื่อผู้เรียกมีสิทธิ์ต่ำกว่าระดับที่กำหนด
     *
     * ลำดับ: เจ้าของ 4, Admin 3, Editor 2, Viewer 1, ค่าที่ไม่รู้จัก 0 (ปิดไว้ก่อน)
     * $min รับ 'member' (2), 'admin' (3) หรือ 'owner' (4)
     *
     * @param array<string, mixed> $project โปรเจคที่ผู้เรียกเห็นได้แล้ว
     * @param string $min ระดับขั้นต่ำ
     * @param string $action ข้อความต่อท้าย error
     */
    private function requireRole(array $project, string $min, string $action): void
    {
        $rank = !empty($project['is_owner']) ? 4 : match ($project['user_role'] ?? '') {
            'Admin' => 3,
            'Editor' => 2,
            'Viewer' => 1,
            default => 0,
        };
        $needed = ['member' => 2, 'admin' => 3, 'owner' => 4][$min] ?? 4;

        if ($rank < $needed) {
            Response::json(['error' => 'คุณไม่มีสิทธิ์' . $action], 403);
        }
    }
}
