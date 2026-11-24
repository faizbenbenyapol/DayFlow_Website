<?php
// =====================================================
// tests/integration/project_attachments_test.php
//
// ไฟล์แนบของงาน (P0b slice 5, D-4): ทางเดียวที่คนอื่นเปิดไฟล์ส่วนตัวของสมาชิกได้
// จึงทดสอบสิทธิ์ทุกทาง: ใครแนบ อ่าน ดาวน์โหลด ถอดได้ และไฟล์ไหนที่ห้ามหลุดออกไป
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';
require_once ROOT . '/tests/fake-google/helpers.php';

/**
 * โปรเจคพร้อมเจ้าของ ผู้ดูแล สมาชิก ผู้ดู คนนอก และแขกจากลิงก์แชร์ มีงานสองงาน
 *
 * @return array<string, mixed>
 */
function attachFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'att_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username, (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn()];
    };
    [$owner, , $ownerId] = $make('owner');
    [$admin, $adminU] = $make('admin');
    [$member, $memberU, $memberId] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider] = $make('outsider');

    $name = 'แนบไฟล์ ' . TEST_RUN_ID;
    $projectId = (int)($owner->json($owner->post('/api/projects', ['name' => $name]))['id'] ?? 0);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $adminU, 'role' => 'Admin']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $memberU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $viewerU, 'role' => 'Viewer']);
    $taskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานมีไฟล์']))['id'] ?? 0);
    $otherTaskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานอื่น']))['id'] ?? 0);

    $share = $owner->json($owner->post("/api/projects/{$projectId}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    return $f = compact('owner', 'admin', 'member', 'viewer', 'outsider', 'guest', 'ownerId', 'memberId',
        'name', 'projectId', 'taskId', 'otherTaskId');
}

/** อัปโหลดไฟล์เข้าคลังส่วนตัวของผู้ใช้ (ไม่ผูกกับงาน) คืน id */
function libraryFile(TestClient $client, string $filename, string $contents): int
{
    return (int)($client->json($client->upload('/api/files/upload', 'file', $filename, $contents))['id'] ?? 0);
}

test('members attach from their computer; viewers, outsiders and share-link guests cannot', function (TestClient $_c): void {
    $f = attachFixture();
    $path = '/api/projects/tasks/' . $f['taskId'] . '/files';

    $res = $f['member']->upload($path, 'file', 'สรุปประชุม.txt', 'บันทึกประชุมทีม');
    assertSame(201, $res['status'], 'a member attaches a file');
    $fileId = (int)($f['member']->json($res)['file_id'] ?? 0);

    // ไฟล์อยู่ในคลังของคนแนบ ในโฟลเดอร์ของโปรเจค (D-4) ไม่ใช่ของเจ้าของโปรเจค
    $row = DB::run('SELECT f.user_id, p.name AS folder FROM files f JOIN files p ON p.id = f.parent_id WHERE f.id = ?', [$fileId])->fetch();
    assertSame($f['memberId'], (int)$row['user_id'], 'the file belongs to whoever attached it');
    assertSame('ไฟล์แนบ · ' . $f['name'], $row['folder'], 'it sits in a folder named after the project');

    // แนบครั้งที่สองใช้โฟลเดอร์เดิม ไม่สร้างซ้ำ
    $f['member']->upload($path, 'file', 'ภาพร่าง.txt', 'ร่างแรก');
    $folders = (int)DB::run("SELECT COUNT(*) FROM files WHERE user_id = ? AND type = 'folder' AND name = ?", [$f['memberId'], 'ไฟล์แนบ · ' . $f['name']])->fetchColumn();
    assertSame(1, $folders, 'a second attachment reuses the same folder');

    assertSame(403, $f['viewer']->upload($path, 'file', 'x.txt', 'x')['status'], 'a viewer cannot attach');
    $foreign = $f['outsider']->upload($path, 'file', 'x.txt', 'x');
    $missing = $f['outsider']->upload('/api/projects/tasks/999999999/files', 'file', 'x.txt', 'x');
    assertSame(404, $foreign['status'], 'an outsider gets 404');
    assertSame($missing['body'], $foreign['body'], 'the same 404 as a missing task');

    $items = $f['viewer']->json($f['viewer']->get($path))['items'] ?? [];
    assertSame(2, count($items), 'a viewer sees both attachments');
    assertSame(403, $f['guest']->get($path)['status'], 'a share-link guest cannot list attachments (D-5)');
});

test('downloads only work for files linked to that task', function (TestClient $_c): void {
    $f = attachFixture();
    $base = '/api/projects/tasks/' . $f['taskId'] . '/files';
    $fileId = (int)($f['member']->json($f['member']->upload($base, 'file', 'ดาวน์โหลด.txt', 'เนื้อไฟล์แนบ'))['file_id'] ?? 0);

    $res = $f['viewer']->get($base . '/' . $fileId);
    assertSame(200, $res['status'], 'a viewer downloads an attachment');
    assertSame('เนื้อไฟล์แนบ', $res['body'], 'and gets the real bytes');

    // ไฟล์จริงของสมาชิกที่ไม่ได้แนบ ห้ามเปิดผ่านงานใด ๆ ได้
    $private = libraryFile($f['member'], 'ส่วนตัว.txt', 'ความลับ');
    $notLinked = $f['viewer']->get($base . '/' . $private);
    assertSame(404, $notLinked['status'], 'a private file that is not attached stays hidden');
    assertSame(404, $f['viewer']->get('/api/projects/tasks/' . $f['otherTaskId'] . '/files/' . $fileId)['status'],
        'an attachment of one task cannot be opened through another task');

    $foreign = $f['outsider']->get($base . '/' . $fileId);
    assertSame(404, $foreign['status'], 'an outsider cannot download');
    assertSame(403, $f['guest']->get($base . '/' . $fileId)['status'], 'a share-link guest cannot download');
});

test('linking an existing file only works for your own files', function (TestClient $_c): void {
    $f = attachFixture();
    $path = '/api/projects/tasks/' . $f['otherTaskId'] . '/files/link';

    $own = libraryFile($f['member'], 'ของฉัน.txt', 'ของสมาชิก');
    assertSame(201, $f['member']->post($path, ['file_id' => $own])['status'], 'a member links their own file');
    assertSame(201, $f['member']->post($path, ['file_id' => $own])['status'], 'linking twice is harmless');
    assertSame(1, (int)DB::run('SELECT COUNT(*) FROM project_task_files WHERE task_id = ? AND file_id = ?', [$f['otherTaskId'], $own])->fetchColumn(),
        'and keeps one row');

    $ownersFile = libraryFile($f['owner'], 'ของเจ้าของ.txt', 'ของคนอื่น');
    $someoneElse = $f['member']->post($path, ['file_id' => $ownersFile]);
    $nothing = $f['member']->post($path, ['file_id' => 999999999]);
    assertSame(404, $someoneElse['status'], "linking someone else's file is refused");
    assertSame($nothing['body'], $someoneElse['body'], 'with the same 404 as a file that does not exist');

    $folderId = (int)DB::run("SELECT id FROM files WHERE user_id = ? AND type = 'folder' LIMIT 1", [$f['memberId']])->fetchColumn();
    assertSame(404, $f['member']->post($path, ['file_id' => $folderId])['status'], 'a folder cannot be attached');
    assertSame(403, $f['viewer']->post($path, ['file_id' => $own])['status'], 'a viewer cannot link');
});

test('whoever attached a file or an admin can remove it, and the file stays in the library', function (TestClient $_c): void {
    $f = attachFixture();
    $base = '/api/projects/tasks/' . $f['taskId'] . '/files/';
    $mine = (int)($f['member']->json($f['member']->upload(rtrim($base, '/'), 'file', 'ถอดเอง.txt', 'a'))['file_id'] ?? 0);
    $ownersFile = (int)($f['owner']->json($f['owner']->upload(rtrim($base, '/'), 'file', 'ของเจ้าของ.txt', 'b'))['file_id'] ?? 0);

    assertSame(403, $f['member']->request('DELETE', $base . $ownersFile, [])['status'], "a member cannot remove someone else's attachment");
    assertSame(403, $f['viewer']->request('DELETE', $base . $mine, [])['status'], 'a viewer cannot remove');
    assertSame(200, $f['member']->request('DELETE', $base . $mine, [])['status'], 'a member removes their own');
    assertSame(200, $f['admin']->request('DELETE', $base . $ownersFile, [])['status'], 'an admin removes anyone\'s');
    assertSame(404, $f['admin']->request('DELETE', $base . $ownersFile, [])['status'], 'removing twice says not found');

    $left = (int)DB::run('SELECT COUNT(*) FROM files WHERE id IN (?, ?)', [$mine, $ownersFile])->fetchColumn();
    assertSame(2, $left, 'removing an attachment keeps both files in their owners\' libraries');
});

test('a task holds at most 20 attachments', function (TestClient $_c): void {
    $f = attachFixture();
    $taskId = (int)($f['owner']->json($f['owner']->post('/api/projects/' . $f['projectId'] . '/tasks', ['title' => 'งานไฟล์เยอะ']))['id'] ?? 0);

    // เติม 20 ลิงก์ตรงในฐานข้อมูล ให้ไม่ต้องอัปโหลด 20 ครั้ง
    for ($i = 1; $i <= 20; $i++) {
        DB::run("INSERT INTO files (user_id, name, type, mime_type, file_size) VALUES (?, ?, 'file', 'text/plain', 1)", [$f['memberId'], "เติม {$i}.txt"]);
        DB::run('INSERT INTO project_task_files (task_id, file_id, added_by) VALUES (?, ?, ?)', [$taskId, (int)DB::conn()->lastInsertId(), $f['memberId']]);
    }
    $path = '/api/projects/tasks/' . $taskId . '/files';
    assertSame(422, $f['member']->upload($path, 'file', 'ใบที่ 21.txt', 'x')['status'], 'the 21st upload is refused');
    $extra = libraryFile($f['member'], 'ใบที่ 21 จากคลัง.txt', 'x');
    assertSame(422, $f['member']->post($path . '/link', ['file_id' => $extra])['status'], 'the 21st link is refused');
});

test('the Files page counts attachments, and deleting the file removes them', function (TestClient $_c): void {
    $f = attachFixture();
    $fileId = libraryFile($f['member'], 'ใช้หลายงาน.txt', 'x');
    $f['member']->post('/api/projects/tasks/' . $f['taskId'] . '/files/link', ['file_id' => $fileId]);
    $f['member']->post('/api/projects/tasks/' . $f['otherTaskId'] . '/files/link', ['file_id' => $fileId]);

    $files = $f['member']->json($f['member']->get('/api/files'))['files'] ?? [];
    $row = array_values(array_filter($files, static fn(array $x): bool => (int)$x['id'] === $fileId))[0] ?? [];
    assertSame(2, (int)($row['attached_tasks'] ?? -1), 'the Files list says the file is attached to 2 tasks');

    assertSame(200, $f['member']->request('DELETE', '/api/files/' . $fileId, [])['status'], 'the owner deletes the file');
    assertSame(0, (int)DB::run('SELECT COUNT(*) FROM project_task_files WHERE file_id = ?', [$fileId])->fetchColumn(),
        'its attachments disappear with it');
    assertSame(404, $f['viewer']->get('/api/projects/tasks/' . $f['taskId'] . '/files/' . $fileId)['status'],
        'and the old download link says not found');
});

test("a file kept in the attacher's Google Drive downloads for everyone in the project", function (TestClient $_c): void {
    $f = attachFixture();
    $username = 'att_drive_' . TEST_RUN_ID;
    $driver = new TestClient(TEST_BASE_URL);
    $driver->login($username, 'TestPass123!');
    $link = linkDrive($username, 'att-drive@example.com');
    $f['owner']->post('/api/projects/' . $f['projectId'] . '/members', ['email_or_username' => $username, 'role' => 'Editor']);

    $bytes = random_bytes(40000);
    $res = $driver->upload('/api/projects/tasks/' . $f['taskId'] . '/files', 'file', 'แบบร่าง.bin', $bytes);
    assertSame(201, $res['status'], 'a member with Drive attaches a file');
    $row = DB::run('SELECT f.file_path, f.drive_id, p.drive_id AS folder_drive FROM files f JOIN files p ON p.id = f.parent_id WHERE f.id = ?',
        [(int)($driver->json($res)['file_id'] ?? 0)])->fetch();
    assertSame(null, $row['file_path'], 'nothing is kept on the server');
    $driveFiles = fakeDriveFiles();
    assertSame([$row['folder_drive']], $driveFiles[$row['drive_id']]['parents'], 'the file sits in the project folder in Drive');
    assertSame([$link['folder']], $driveFiles[$row['folder_drive']]['parents'], 'and that folder sits in the DayFlow folder');

    // ผู้ดูไม่ได้เชื่อม Drive แต่ดาวน์โหลดได้ เพราะเซิร์ฟเวอร์ใช้สิทธิ์ของคนแนบ
    $download = $f['viewer']->get('/api/projects/tasks/' . $f['taskId'] . '/files/' . (int)($driver->json($res)['file_id'] ?? 0));
    assertSame(200, $download['status'], 'a viewer without Drive downloads it');
    assertSame(md5($bytes), md5($download['body']), 'and gets the same bytes back');
});
