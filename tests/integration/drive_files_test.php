<?php
// =====================================================
// tests/integration/drive_files_test.php
//
// เมนูไฟล์กับ Google Drive (เฟส 4 REQ-21, REQ-23, EDGE-11, EDGE-14) ผ่าน API จริงของแอป
// โดย Drive คือ Google ปลอม: ไฟล์ใหม่ไป Drive เมื่อเชื่อมอยู่, ทุกคำสั่งสะท้อนไป Drive,
// ลิงก์แชร์ยังเปิดได้, สิทธิ์หลุดบอกให้เชื่อมใหม่, ยกเลิกการเชื่อมแล้วรายการยังอยู่
// =====================================================

declare(strict_types=1);

require_once ROOT . '/tests/fake-google/helpers.php';
require_once ROOT . '/models/File.php';

/** บัญชีทดสอบต่อ label คืน [client, username] */
function driveAccount(string $label): array
{
    $username = 'drv_' . $label . '_' . TEST_RUN_ID;
    $client = new TestClient(TEST_BASE_URL);
    $client->login($username, 'TestPass123!');
    return [$client, $username];
}

/** แถวของไฟล์ในสารบัญ */
function fileRow(int $id): array
{
    return DB::run('SELECT * FROM files WHERE id = ?', [$id])->fetch();
}

test('an account without Drive keeps its files on the server, as before', function (TestClient $_c): void {
    [$client] = driveAccount('local');
    $up = $client->json($client->upload('/api/files/upload', 'file', 'note.txt', 'on the server'));
    $row = fileRow((int)$up['id']);
    assertSame(null, $row['drive_id']);
    assertTrue(is_file(UPLOAD_DIR . $row['file_path']), 'the bytes are on disk');
    assertSame('on the server', $client->get('/api/files/' . $up['id'] . '/download')['body']);
    assertSame('none', $client->json($client->get('/api/files'))['drive']['state']);
});

test('with Drive connected, new folders and files go into the DayFlow folder in Drive', function (TestClient $_c): void {
    [$client, $username] = driveAccount('upload');
    $link = linkDrive($username, 'upload@example.com');

    $folder = $client->json($client->post('/api/files/folder', ['name' => 'งาน']));
    $folderRow = fileRow((int)$folder['id']);
    $driveFiles = fakeDriveFiles();
    assertSame([$link['folder']], $driveFiles[$folderRow['drive_id']]['parents'], 'the folder sits in DayFlow');

    $bytes = random_bytes(50000);
    $up = $client->json($client->upload('/api/files/upload?parent_id=' . $folder['id'], 'file', 'สรุป.bin', $bytes));
    $row = fileRow((int)$up['id']);
    assertSame(null, $row['file_path'], 'nothing kept on the server');
    assertSame([$folderRow['drive_id']], fakeDriveFiles()[$row['drive_id']]['parents'], 'the file sits in its folder in Drive');
    assertSame(md5($bytes), md5($client->get('/api/files/' . $up['id'] . '/download')['body']), 'downloads come back from Drive unchanged');
    assertSame(true, $client->json($client->get('/api/files'))['drive']['usable']);
});

test('rename, move and delete reach Drive, and delete goes to the Drive trash', function (TestClient $_c): void {
    [$client, $username] = driveAccount('ops');
    $link = linkDrive($username, 'ops@example.com');
    $folder = $client->json($client->post('/api/files/folder', ['name' => 'a']));
    $up = $client->json($client->upload('/api/files/upload?parent_id=' . $folder['id'], 'file', 'x.txt', 'x'));
    $driveId = fileRow((int)$up['id'])['drive_id'];

    assertSame(200, $client->request('PUT', '/api/files/' . $up['id'] . '/rename', ['name' => 'y.txt'])['status']);
    assertSame('y.txt', fakeDriveFiles()[$driveId]['name']);

    assertSame(200, $client->request('PUT', '/api/files/' . $up['id'] . '/move', ['parent_id' => null])['status']);
    assertSame([$link['folder']], fakeDriveFiles()[$driveId]['parents'], 'moved to the top of DayFlow');

    $folderDrive = fileRow((int)$folder['id'])['drive_id'];
    assertSame(200, $client->request('DELETE', '/api/files/' . $folder['id'], [])['status']);
    assertTrue(fakeDriveFiles()[$folderDrive]['trashed'], 'the folder is in the Drive trash, not gone');
});

test('a share link to a file in Drive opens without signing in', function (TestClient $_c): void {
    [$client, $username] = driveAccount('share');
    linkDrive($username, 'share@example.com');
    $up = $client->json($client->upload('/api/files/upload', 'file', 'shared.txt', 'from drive'));
    $share = $client->json($client->post('/api/shares', ['file_id' => $up['id'], 'permission' => 'download']));
    $token = $share['token'] ?? $share['share']['token'] ?? '';

    $stranger = new TestClient(TEST_BASE_URL);
    $download = $stranger->get('/share/' . $token . '/download/' . $up['id']);
    assertSame(200, $download['status'], substr($download['body'], 0, 200));
    assertSame('from drive', $download['body']);
});

test('a grant revoked at Google asks to reconnect; disconnecting keeps the list but not the bytes', function (TestClient $_c): void {
    [$client, $username] = driveAccount('revoke');
    $link = linkDrive($username, 'revoke@example.com');
    $up = $client->json($client->upload('/api/files/upload', 'file', 'kept.txt', 'kept'));

    fakeGoogle('POST', '/_fake/revoke?email=' . rawurlencode('revoke@example.com'));
    DB::run('UPDATE drive_links SET access_expires_at = 0 WHERE user_id = ?', [$link['user_id']]);   // บังคับให้ต้องขอ token ใหม่
    $download = $client->get('/api/files/' . $up['id'] . '/download');
    assertSame(409, $download['status']);
    assertStringContains('เชื่อมใหม่', $download['body']);
    assertSame('revoked', $client->json($client->get('/api/files'))['drive']['state'], 'the files menu can say so');

    DriveLink::disconnect($link['user_id']);
    $names = array_column($client->json($client->get('/api/files'))['files'], 'name');
    assertContains('kept.txt', $names, 'the file is still listed');
    $closed = $client->get('/api/files/' . $up['id'] . '/download');
    assertSame(409, $closed['status']);
    assertStringContains('ยกเลิกการเชื่อม', $closed['body']);

    $local = $client->json($client->upload('/api/files/upload', 'file', 'after.txt', 'local again'));
    assertSame(null, fileRow((int)$local['id'])['drive_id'], 'new files go back to the server');
});

test('an access token Google dropped early is renewed on download, and the link stays connected', function (TestClient $_c): void {
    [$client, $username] = driveAccount('stale');
    $link = linkDrive($username, 'stale@example.com');
    $up = $client->json($client->upload('/api/files/upload', 'file', 'stale.txt', 'still here'));

    // token ที่ Google ไม่รับแล้วแต่ยังไม่ถึงเวลาหมดอายุ ส่วน refresh token ยังใช้ได้
    DB::run('UPDATE drive_links SET access_token_enc = ?, access_expires_at = ? WHERE user_id = ?', [appEncrypt('at-dropped'), time() + 3000, $link['user_id']]);
    $download = $client->get('/api/files/' . $up['id'] . '/download');
    assertSame(200, $download['status'], $download['body']);
    assertSame('still here', $download['body']);
    assertSame('connected', DriveLink::find($link['user_id'])['state']);
});

test('after disconnecting, changing a file in Drive says so and keeps the link disconnected', function (TestClient $_c): void {
    [$client, $username] = driveAccount('closed');
    $link = linkDrive($username, 'closed@example.com');
    $up = $client->json($client->upload('/api/files/upload', 'file', 'in-drive.txt', 'x'));
    DriveLink::disconnect($link['user_id']);

    $delete = $client->request('DELETE', '/api/files/' . $up['id'], []);
    assertSame(409, $delete['status']);
    assertStringContains('ยกเลิกการเชื่อม', $delete['body']);
    assertSame('disconnected', DriveLink::find($link['user_id'])['state'], 'not turned into "connection dropped"');
});
