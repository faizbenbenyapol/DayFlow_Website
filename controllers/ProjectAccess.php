<?php
// =====================================================
// controllers/ProjectAccess.php — who may see and change a project
// =====================================================

/**
 * Shared by every project controller. Project::getById() scopes a project to
 * its owner, its members and a guest holding the share token, and returns
 * `user_role` and `is_owner` for the checks here.
 */
trait ProjectAccess
{
    /**
     * Loads a project the caller may see, or stops with a 404.
     *
     * Project::getById() already scopes to the owner, the members, and a guest
     * holding the share token, and it returns `user_role` and `is_owner` for
     * the checks below.
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
     * Stops unless the caller may change project content.
     *
     * Viewer is the read-only role, and it is what a public share link hands a
     * guest. This used to be written out at each task endpoint and missing from
     * the others, which let a Viewer post into the project chat.
     */
    private function requireEditor(array $project, string $action): void
    {
        // Fails closed: a role this code does not know (a row written before
        // roles were validated) is read-only rather than editor.
        $canEdit = !empty($project['is_owner'])
            || in_array($project['user_role'] ?? '', ['Owner', 'Editor'], true);
        if (!$canEdit) {
            Response::json(['error' => 'คุณมีสิทธิ์ดูเท่านั้น ไม่สามารถ' . $action . 'ได้'], 403);
        }
    }

    /**
     * Stops unless the caller owns the project.
     *
     * Renaming, deleting, managing members and share links are the owner's
     * alone. The model's WHERE clause already refused to touch another
     * person's row, but the endpoints reported that as success or as a 500
     * rather than as a refusal.
     */
    private function requireOwner(array $project, string $action): void
    {
        if (empty($project['is_owner'])) {
            Response::json(['error' => 'เฉพาะเจ้าของโครงการเท่านั้นที่' . $action . 'ได้'], 403);
        }
    }
}
