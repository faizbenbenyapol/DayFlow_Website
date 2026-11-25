<?php
// =====================================================
// tests/integration/notifications_test.php
//
// กระดิ่งแจ้งเตือน (P0b slice 2): ใครได้แจ้งเตือน ใครไม่ได้ และแต่ละคนอ่าน/แก้ได้แค่ของตัวเอง
// REQ-P10: ไม่แจ้งเรื่องที่ตัวเองเป็นคนทำ
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';
require_once ROOT . '/models/Notification.php';

/**
 * บัญชีชุดเดียวของไฟล์นี้: เจ้าของ ผู้ดูแล สมาชิก
 *
 * @return array{owner: TestClient, admin: TestClient, member: TestClient, ownerId: int, adminId: int, memberId: int, adminU: string, memberU: string}
 */
function notifUsers(): array
{
    static $users = null;
    if ($users !== null) return $users;

    $make = static function (string $role): array {
        $username = 'ntf_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        $id = (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();
        return [$client, $id, $username];
    };
    [$owner, $ownerId] = $make('owner');
    [$admin, $adminId, $adminU] = $make('admin');
    [$member, $memberId, $memberU] = $make('member');

    return $users = compact('owner', 'admin', 'member', 'ownerId', 'adminId', 'memberId', 'adminU', 'memberU');
}

/**
 * โปรเจคใหม่ที่มีผู้ดูแลและสมาชิกของชุดนี้
 */
function notifProject(): int
{
    $u = notifUsers();
    $id = (int)($u['owner']->json($u['owner']->post('/api/projects', ['name' => 'แจ้งเตือน ' . bin2hex(random_bytes(3))]))['id'] ?? 0);
    $u['owner']->post("/api/projects/{$id}/members", ['email_or_username' => $u['adminU'], 'role' => 'Admin']);
    $u['owner']->post("/api/projects/{$id}/members", ['email_or_username' => $u['memberU'], 'role' => 'Editor']);
    return $id;
}

/** จำนวนการแจ้งเตือนของผู้ใช้ตามชนิด */
function notifCount(int $userId, string $kind): int
{
    return (int)DB::run('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = ?', [$userId, $kind])->fetchColumn();
}

test('assigning a task notifies the assignee, never the person who did it', function (TestClient $_c): void {
    $u = notifUsers();
    $projectId = notifProject();
    $before = notifCount($u['memberId'], 'assign');
    $ownerBefore = notifCount($u['ownerId'], 'assign');

    $task = $u['owner']->json($u['owner']->post("/api/projects/{$projectId}/tasks", ['title' => 'งานมอบหมาย', 'assignee_user_id' => $u['memberId']]));
    assertSame($before + 1, notifCount($u['memberId'], 'assign'), 'creating a task with an assignee notifies them');

    $row = DB::run('SELECT title, url, actor_id FROM notifications WHERE user_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$u['memberId'], 'assign'])->fetch();
    assertStringContains('งานมอบหมาย', $row['title']);
    assertSame('/projects?project=' . $projectId . '&task=' . (int)$task['id'], $row['url']);
    assertSame($u['ownerId'], (int)$row['actor_id']);

    // มอบให้ตัวเองไม่แจ้ง
    $u['owner']->request('PUT', '/api/projects/tasks/' . (int)$task['id'], ['assignee_user_id' => $u['ownerId']]);
    assertSame($ownerBefore, notifCount($u['ownerId'], 'assign'), 'assigning yourself does not notify you');

    // เปลี่ยนเป็นผู้ดูแล → ผู้ดูแลได้ แก้ชื่ออย่างเดียวไม่แจ้งซ้ำ
    $adminBefore = notifCount($u['adminId'], 'assign');
    $u['owner']->request('PUT', '/api/projects/tasks/' . (int)$task['id'], ['assignee_user_id' => $u['adminId']]);
    $u['owner']->request('PUT', '/api/projects/tasks/' . (int)$task['id'], ['title' => 'งานมอบหมาย แก้ชื่อ']);
    assertSame($adminBefore + 1, notifCount($u['adminId'], 'assign'), 'a new assignee is notified once, a title edit does not repeat it');
});

test('each person reads and clears only their own notifications', function (TestClient $_c): void {
    $u = notifUsers();
    $projectId = notifProject();
    $u['owner']->post("/api/projects/{$projectId}/tasks", ['title' => 'ของสมาชิก', 'assignee_user_id' => $u['memberId']]);
    $memberRow = (int)DB::run('SELECT id FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['memberId']])->fetchColumn();

    $list = $u['member']->json($u['member']->get('/api/notifications'));
    assertTrue($list['unread'] >= 1, 'the member has something unread');
    assertSame($memberRow, (int)$list['items'][0]['id'], 'newest first');
    assertFalse($list['items'][0]['read']);

    $ownerList = $u['owner']->json($u['owner']->get('/api/notifications'));
    assertNotContains($memberRow, array_map('intval', array_column($ownerList['items'], 'id')), 'nobody sees another person\'s rows');

    // คนอื่นส่ง id ของสมาชิกมาทำเครื่องหมายอ่าน → ไม่มีผล ตอบเหมือนปกติ
    $r = $u['owner']->post('/api/notifications/read', ['ids' => [$memberRow]]);
    assertSame(200, $r['status']);
    assertSame(null, DB::run('SELECT read_at FROM notifications WHERE id = ?', [$memberRow])->fetchColumn(), 'someone else cannot mark it read');

    $u['member']->post('/api/notifications/read', ['ids' => [$memberRow]]);
    assertTrue(DB::run('SELECT read_at FROM notifications WHERE id = ?', [$memberRow])->fetchColumn() !== null, 'the owner of the row can');

    $u['member']->post('/api/notifications/read', ['all' => true]);
    assertSame(0, (int)$u['member']->json($u['member']->get('/api/notifications/unread'))['unread']);
    assertSame(422, $u['member']->post('/api/notifications/read', [])['status'], 'an empty request is refused');
});

test('the owner hears when someone leaves or is removed, but not about their own removals', function (TestClient $_c): void {
    $u = notifUsers();

    $projectId = notifProject();
    $before = notifCount($u['ownerId'], 'member_left');
    assertSame(200, $u['admin']->request('DELETE', "/api/projects/{$projectId}/members/{$u['memberId']}", [])['status'], 'admin removal goes through');
    assertSame($before + 1, notifCount($u['ownerId'], 'member_left'), 'an admin removing a member notifies the owner');

    $projectId = notifProject();
    assertSame(200, $u['member']->request('DELETE', "/api/projects/{$projectId}/members/{$u['memberId']}", [])['status'], 'member removal goes through');
    assertSame($before + 2, notifCount($u['ownerId'], 'member_left'), 'a member leaving notifies the owner');

    $projectId = notifProject();
    assertSame(200, $u['owner']->request('DELETE', "/api/projects/{$projectId}/members/{$u['memberId']}", [])['status'], 'owner removal goes through');
    assertSame($before + 2, notifCount($u['ownerId'], 'member_left'), 'the owner removing someone is not told about it');
});

test('old notifications are purged and push shows only fresh unread ones', function (TestClient $_c): void {
    $u = notifUsers();
    $id = $u['adminId'];
    DB::run("INSERT INTO notifications (user_id, kind, title, created_at) VALUES (?, 'test', 'เก่า', NOW() - INTERVAL 31 DAY)", [$id]);
    $old = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO notifications (user_id, kind, title, created_at) VALUES (?, 'test', 'ครบ 29 วัน', NOW() - INTERVAL 29 DAY)", [$id]);
    $kept = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO notifications (user_id, kind, title) VALUES (?, 'test', 'ใหม่')", [$id]);
    $fresh = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO notifications (user_id, kind, title, read_at) VALUES (?, 'test', 'อ่านแล้ว', NOW())", [$id]);
    $read = (int)DB::conn()->lastInsertId();

    $tags = array_column(Notification::pushItems($id), 'tag');
    assertContains('notif-' . $fresh, $tags, 'a fresh unread one is pushed');
    assertNotContains('notif-' . $kept, $tags, 'an old one is not pushed again');
    assertNotContains('notif-' . $read, $tags, 'a read one is not pushed');

    Notification::purgeOld();
    assertSame(0, (int)DB::run('SELECT COUNT(*) FROM notifications WHERE id = ?', [$old])->fetchColumn(), 'older than 30 days is gone');
    assertSame(1, (int)DB::run('SELECT COUNT(*) FROM notifications WHERE id = ?', [$kept])->fetchColumn(), '29 days old stays');
});
