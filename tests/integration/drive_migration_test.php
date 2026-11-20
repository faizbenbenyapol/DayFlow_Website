<?php
// =====================================================
// tests/integration/drive_migration_test.php
//
// ย้ายไฟล์เดิมบนเซิร์ฟเวอร์ขึ้น Google Drive (เฟส 4 REQ-22, EDGE-12, EDGE-13, EDGE-14)
// ไฟล์บนเซิร์ฟเวอร์ถูกลบเฉพาะหลังขึ้น Drive สำเร็จและขนาดตรงกัน ถ้า Drive เต็มกลางทาง
// ไฟล์ที่ยังไม่ขึ้นต้องอยู่ครบ รันซ้ำไม่ย้ายซ้ำ และลิงก์แชร์เดิมยังเปิดได้
// =====================================================

declare(strict_types=1);

require_once ROOT . '/tests/fake-google/helpers.php';
require_once ROOT . '/core/DriveMigration.php';

/** บัญชีที่มีไฟล์บนเซิร์ฟเวอร์อยู่แล้ว (ก่อนเชื่อม Drive) @return array{0: TestClient, 1: string, 2: array<int, string>} [client, username, id → เนื้อไฟล์] */
function accountWithLocalFiles(string $label, int $fileCount, int $bytesEach): array
{
    $username = 'mig_' . $label . '_' . TEST_RUN_ID;
    $client = new TestClient(TEST_BASE_URL);
    $client->login($username, 'TestPass123!');
    $outer = $client->json($client->post('/api/files/folder', ['name' => 'ปี 2569']));
    $inner = $client->json($client->post('/api/files/folder', ['name' => 'ใบเสร็จ', 'parent_id' => $outer['id']]));
    $client->post('/api/files/folder', ['name' => 'ว่าง']);
    $files = [];
    for ($i = 0; $i < $fileCount; $i++) {
        $parent = [null, $outer['id'], $inner['id']][$i % 3];
        $bytes = random_bytes($bytesEach);
        $up = $client->json($client->upload('/api/files/upload' . ($parent ? '?parent_id=' . $parent : ''), 'file', "f{$i}.bin", $bytes));
        $files[(int)$up['id']] = $bytes;
    }
    return [$client, $username, $files];
}

/**
 * trigger ชั่วคราวบน files ที่ทำงานเมื่อแถว $fileId ได้ drive_id ครั้งแรก จำลองเหตุการณ์ที่แทรกเข้ามากลางการย้าย
 * รัน $run แล้วลบ trigger เสมอ (CREATE TRIGGER ใช้ prepared statement ไม่ได้ จึงใช้ exec)
 */
function withDriveIdTrigger(string $timing, int $fileId, string $action, callable $run): void
{
    $name = 'test_drive_' . bin2hex(random_bytes(4));
    DB::conn()->exec("CREATE TRIGGER {$name} {$timing} UPDATE ON files FOR EACH ROW
        IF OLD.drive_id IS NULL AND NEW.drive_id IS NOT NULL AND NEW.id = {$fileId} THEN {$action}; END IF");
    try {
        $run();
    } finally {
        DB::conn()->exec("DROP TRIGGER IF EXISTS {$name}");
    }
}

/** ไฟล์ของผู้ใช้ที่ยังอยู่บนเซิร์ฟเวอร์ (มีไฟล์บนดิสก์จริง) */
function localPaths(int $userId): array
{
    $rows = DB::run('SELECT file_path FROM files WHERE user_id = ? AND file_path IS NOT NULL', [$userId])->fetchAll();
    return array_filter(array_map(fn($r) => UPLOAD_DIR . $r['file_path'], $rows), 'is_file');
}

test('existing files move up with their folders, and leave the server only once they match in Drive', function (TestClient $_c): void {
    [$client, $username, $files] = accountWithLocalFiles('all', 6, 3000);
    $link = linkDrive($username, 'all@example.com', 'migrating');

    $status = DriveMigration::step($link['user_id'], 60);
    assertSame(0, $status['pending'], json_encode($status));
    assertSame('connected', DriveLink::find($link['user_id'])['state'], 'done moving');
    assertSame([], localPaths($link['user_id']), 'nothing left on the server');

    // โครงโฟลเดอร์เดิมใน Drive รวมโฟลเดอร์ว่าง
    $drive = fakeDriveFiles();
    $rows = DB::run('SELECT * FROM files WHERE user_id = ?', [$link['user_id']])->fetchAll();
    $byId = array_column($rows, null, 'id');
    foreach ($rows as $row) {
        assertTrue($row['drive_id'] !== null, "{$row['name']} has a place in Drive");
        $expectedParent = $row['parent_id'] === null ? $link['folder'] : $byId[$row['parent_id']]['drive_id'];
        assertSame([$expectedParent], $drive[$row['drive_id']]['parents'], "{$row['name']} keeps its folder");
    }
    foreach ($files as $id => $bytes) {
        assertSame(md5($bytes), md5($client->get('/api/files/' . $id . '/download')['body']), "file {$id} reads back from Drive");
    }
});

test('a full Drive stops the move, keeps every file not yet moved, and the move resumes', function (TestClient $_c): void {
    [$client, $username, $files] = accountWithLocalFiles('full', 3, 1000);
    // อีเมลใหม่ทุกรอบ: Google ปลอมนับโควตาจากไฟล์ทุกไฟล์ของอีเมลนั้น รวมของรอบก่อน
    $link = linkDrive($username, 'full' . TEST_RUN_ID . '@example.com', 'migrating');

    fakeGoogle('POST', '/_fake/quota?bytes=1500');   // ขึ้นได้ไฟล์เดียว
    try {
        $status = DriveMigration::step($link['user_id'], 60);
    } finally {
        fakeGoogle('POST', '/_fake/quota?bytes=0');   // คืนเสมอ แม้ step ล้ม ไม่งั้นค้างไปถึง test อื่น
    }
    assertSame('paused_full', DriveLink::find($link['user_id'])['state']);
    assertSame(2, $status['pending']);
    assertSame(2, count(localPaths($link['user_id'])), 'the two not moved are still on the server');

    $resumed = $client->json($client->post('/api/drive/resume', []));
    assertSame(0, $resumed['pending'], json_encode($resumed));
    assertSame('connected', $resumed['state']);
    foreach ($files as $id => $bytes) {
        assertSame(md5($bytes), md5($client->get('/api/files/' . $id . '/download')['body']));
    }
});

test('running again moves nothing twice, and a link shared before the move still opens', function (TestClient $_c): void {
    [$client, $username, $files] = accountWithLocalFiles('again', 2, 500);
    $firstId = array_key_first($files);
    $share = $client->json($client->post('/api/shares', ['file_id' => $firstId, 'permission' => 'download']));
    $token = $share['token'] ?? $share['share']['token'] ?? '';

    $link = linkDrive($username, 'again@example.com', 'migrating');
    DriveMigration::step($link['user_id'], 60);
    $countAfterFirst = count(fakeDriveFiles());
    DriveLink::setState($link['user_id'], ['connected'], 'migrating');   // เหมือนปิดเบราว์เซอร์แล้ว cron มาทำต่อ
    DriveMigration::step($link['user_id'], 60);
    assertSame($countAfterFirst, count(fakeDriveFiles()), 'a second run uploads nothing');

    $stranger = new TestClient(TEST_BASE_URL);
    assertSame($files[$firstId], $stranger->get('/share/' . $token . '/download/' . $firstId)['body'], 'the old share link opens the file now in Drive');
});

test('a copy in Drive that came out short is thrown away, and the file stays on the server', function (TestClient $_c): void {
    [$client, $username, $files] = accountWithLocalFiles('short', 1, 2000);
    $id = array_key_first($files);
    $link = linkDrive($username, 'short@example.com', 'migrating');

    fakeGoogle('POST', '/_fake/truncate?bytes=1');
    try {
        $status = DriveMigration::step($link['user_id'], 60);
    } finally {
        fakeGoogle('POST', '/_fake/truncate?bytes=0');
    }
    assertSame(1, $status['pending'], json_encode($status));
    assertSame(null, DB::run('SELECT drive_id FROM files WHERE id = ?', [$id])->fetchColumn(), 'not marked as in Drive');
    assertSame(1, count(localPaths($link['user_id'])), 'the file is still on the server');
    $copies = array_filter(fakeDriveFiles(), fn($f) => $f['name'] === 'f0.bin' && in_array($link['folder'], $f['parents'], true));
    assertSame([true], array_values(array_column($copies, 'trashed')), 'the short copy went to the Drive trash');

    assertSame(0, DriveMigration::step($link['user_id'], 60)['pending'], 'the next run moves it');
    assertSame(md5($files[$id]), md5($client->get('/api/files/' . $id . '/download')['body']));
});

test('if saving the Drive id fails after the upload, the file is still on the server', function (TestClient $_c): void {
    [, $username, $files] = accountWithLocalFiles('dbfail', 1, 500);
    $id = array_key_first($files);
    $link = linkDrive($username, 'dbfail@example.com', 'migrating');

    $failed = false;
    withDriveIdTrigger('BEFORE', $id, "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test: the database write failed'", function () use ($link, &$failed): void {
        try {
            DriveMigration::step($link['user_id'], 60);
        } catch (PDOException) {
            $failed = true;
        }
    });
    assertTrue($failed, 'the write really failed');
    assertSame(1, count(localPaths($link['user_id'])), 'the server copy is deleted only after the Drive id is saved');
});

test('disconnecting while files are moving stops at the next file and stays disconnected', function (TestClient $_c): void {
    [, $username, $files] = accountWithLocalFiles('cut', 3, 500);
    $firstId = array_key_first($files);
    $link = linkDrive($username, 'cut@example.com', 'migrating');

    // ผู้ใช้กดยกเลิกการเชื่อมทันทีที่ไฟล์แรกขึ้น Drive (ระหว่างที่ step ยังทำงานอยู่)
    withDriveIdTrigger('AFTER', $firstId, "UPDATE drive_links SET state = 'disconnected' WHERE user_id = NEW.user_id", function () use ($link): void {
        DriveMigration::step($link['user_id'], 60);
    });
    assertSame('disconnected', DriveLink::find($link['user_id'])['state'], 'the move did not write over the user choice');
    assertSame(2, count(localPaths($link['user_id'])), 'the files after the first stayed on the server');
});
