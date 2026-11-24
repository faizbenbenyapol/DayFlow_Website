<?php
// =====================================================
// tests/integration/project_comments_test.php
//
// ความเห็นต่องานและ @กล่าวถึง (P0b slice 4): ใครเขียน อ่าน ลบได้ และใครได้แจ้งเตือน
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * โปรเจคหนึ่งโปรเจคพร้อมทุกบทบาท คนนอก และแขกจากลิงก์แชร์ (Viewer)
 *
 * @return array<string, mixed>
 */
function commentFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'cmt_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username, (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn()];
    };
    [$owner, , $ownerId] = $make('owner');
    [$admin, $adminU, $adminId] = $make('admin');
    [$member, $memberU, $memberId] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider, $outsiderU, $outsiderId] = $make('outsider');

    $projectId = (int)($owner->json($owner->post('/api/projects', ['name' => 'ความเห็น ' . TEST_RUN_ID]))['id'] ?? 0);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $adminU, 'role' => 'Admin']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $memberU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $viewerU, 'role' => 'Viewer']);
    $taskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานมีความเห็น']))['id'] ?? 0);

    $share = $owner->json($owner->post("/api/projects/{$projectId}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    return $f = compact('owner', 'admin', 'member', 'viewer', 'outsider', 'guest', 'ownerId', 'adminId', 'memberId', 'outsiderId',
        'adminU', 'memberU', 'outsiderU', 'projectId', 'taskId');
}

/** จำนวนแจ้งเตือนชนิด mention ของผู้ใช้ */
function mentionCount(int $userId): int
{
    return (int)DB::run("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'mention'", [$userId])->fetchColumn();
}

test('members comment; viewers, outsiders and share-link guests cannot', function (TestClient $_c): void {
    $f = commentFixture();
    $path = '/api/projects/tasks/' . $f['taskId'] . '/comments';

    assertSame(201, $f['member']->post($path, ['body' => 'ความเห็นแรก'])['status'], 'a member comments');
    assertSame(403, $f['viewer']->post($path, ['body' => 'ดูอย่างเดียว'])['status'], 'a viewer cannot');
    $foreign = $f['outsider']->post($path, ['body' => 'คนนอก']);
    $missing = $f['outsider']->post('/api/projects/tasks/999999999/comments', ['body' => 'ไม่มีงาน']);
    assertSame(404, $foreign['status'], 'an outsider gets 404');
    assertSame($missing['body'], $foreign['body'], 'the same 404 as a missing task');

    assertSame(200, $f['viewer']->get($path)['status'], 'a viewer can read');
    assertSame(403, $f['guest']->get($path)['status'], 'a share-link guest cannot read comments (D-5)');

    assertSame(422, $f['member']->post($path, ['body' => "  \xC2\xA0 "])['status'], 'only spaces is refused');
    assertSame(422, $f['member']->post($path, ['body' => str_repeat('ก', 5001)])['status'], 'over 5,000 characters is refused');
});

test('polling with after= returns only newer comments, oldest first', function (TestClient $_c): void {
    $f = commentFixture();
    $path = '/api/projects/tasks/' . $f['taskId'] . '/comments';
    $all = $f['owner']->json($f['owner']->get($path))['items'];
    $last = (int)end($all)['id'];

    $f['admin']->post($path, ['body' => 'ใหม่ 1']);
    $f['member']->post($path, ['body' => 'ใหม่ 2']);
    $newer = $f['owner']->json($f['owner']->get($path . '?after=' . $last))['items'];
    assertSame(['ใหม่ 1', 'ใหม่ 2'], array_column($newer, 'body'));
});

test('@mentions notify project people only, never the writer', function (TestClient $_c): void {
    $f = commentFixture();
    $admin = mentionCount($f['adminId']);
    $outsider = mentionCount($f['outsiderId']);
    $member = mentionCount($f['memberId']);

    $f['member']->post('/api/projects/tasks/' . $f['taskId'] . '/comments',
        ['body' => "@{$f['adminU']} ช่วยดูหน่อย cc @{$f['outsiderU']} @{$f['memberU']} @nobody_here"]);
    assertSame($admin + 1, mentionCount($f['adminId']), 'a mentioned admin is notified');
    assertSame($outsider, mentionCount($f['outsiderId']), 'someone outside the project is not');
    assertSame($member, mentionCount($f['memberId']), 'the writer mentioning themself is not');

    $url = DB::run("SELECT url FROM notifications WHERE user_id = ? AND kind = 'mention' ORDER BY id DESC LIMIT 1", [$f['adminId']])->fetchColumn();
    assertSame('/projects?project=' . $f['projectId'] . '&task=' . $f['taskId'], $url, 'the notification opens the task');

    // แชทในโปรเจคก็กล่าวถึงได้
    $f['member']->post('/api/projects/' . $f['projectId'] . '/chat', ['message' => "@{$f['adminU']} ประชุมพรุ่งนี้"]);
    assertSame($admin + 2, mentionCount($f['adminId']), 'a chat mention notifies too');
});

test('writers delete their own comments, admins delete anyone\'s, others cannot', function (TestClient $_c): void {
    $f = commentFixture();
    $path = '/api/projects/tasks/' . $f['taskId'] . '/comments';
    $mine = (int)$f['member']->json($f['member']->post($path, ['body' => 'จะลบเอง']))['id'];
    $other = (int)$f['member']->json($f['member']->post($path, ['body' => 'ผู้ดูแลลบ']))['id'];

    assertSame(403, $f['viewer']->request('DELETE', '/api/projects/comments/' . $mine, [])['status'], 'a viewer cannot delete it');
    $foreign = $f['outsider']->request('DELETE', '/api/projects/comments/' . $mine, []);
    assertSame(404, $foreign['status'], 'an outsider gets 404');
    assertSame(200, $f['member']->request('DELETE', '/api/projects/comments/' . $mine, [])['status'], 'the writer deletes their own');
    assertSame(200, $f['admin']->request('DELETE', '/api/projects/comments/' . $other, [])['status'], 'an admin deletes someone else\'s');
    assertSame(404, $f['admin']->request('DELETE', '/api/projects/comments/' . $other, [])['status'], 'a deleted one is gone');
});
