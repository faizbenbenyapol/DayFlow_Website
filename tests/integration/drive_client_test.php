<?php
// =====================================================
// tests/integration/drive_client_test.php
//
// DriveClient คุยกับ Google ปลอม (tests/fake-google, service fake-google) ผ่าน HTTP จริง:
// OAuth, อัปโหลดหลายชิ้น, ดาวน์โหลดแบบ stream, เปลี่ยนชื่อ ย้าย ลงถังขยะ
// และ error ที่แอปต้องแยกแยะ (Drive เต็ม, สิทธิ์หลุด, ไฟล์ถูกลบจากฝั่ง Drive)
// =====================================================

declare(strict_types=1);

require_once ROOT . '/tests/fake-google/helpers.php';

/** เนื้อไฟล์ที่ streamTo ส่งออกมา */
function downloaded(DriveClient $client, string $id): string
{
    ob_start();
    $client->streamTo($id, fn() => null);
    return (string)ob_get_clean();
}

/** รันแล้วคืนชนิดของ DriveError ที่โยนออกมา หรือ 'none' */
function driveErrorKind(callable $action): string
{
    try {
        $action();
        return 'none';
    } catch (DriveError $e) {
        return $e->kind;
    }
}

test('the Drive client signs in, uploads in chunks and streams the file back unchanged', function (TestClient $_c): void {
    fakeGoogle('POST', '/_fake/reset');
    $client = connectedClient('owner@example.com');
    assertSame('owner@example.com', $client->email());

    $folder = $client->createFolder('DayFlow', 'root');
    $path = tempnam(sys_get_temp_dir(), 'drv');
    file_put_contents($path, random_bytes(9 * 1024 * 1024 + 7));   // สองชิ้นกับเศษ
    $uploaded = $client->upload($path, 'รายงาน.pdf', 'application/pdf', $folder);
    assertSame(filesize($path), $uploaded['size'], 'Google reports the size it stored');
    assertSame(md5_file($path), md5(downloaded($client, $uploaded['id'])), 'the bytes come back as they went');

    $empty = tempnam(sys_get_temp_dir(), 'drv');
    $none = $client->upload($empty, 'empty.txt', 'text/plain', $folder);
    assertSame(0, $none['size']);
    assertSame('', downloaded($client, $none['id']), 'an empty file streams as nothing');
    unlink($path);
    unlink($empty);
});

test('rename, move and trash reach Drive, and a trashed file reads as not found', function (TestClient $_c): void {
    $client = connectedClient('mover@example.com');
    $a = $client->createFolder('a', 'root');
    $b = $client->createFolder('b', 'root');
    $path = tempnam(sys_get_temp_dir(), 'drv');
    file_put_contents($path, 'hello');
    $file = $client->upload($path, 'x.txt', 'text/plain', $a)['id'];
    unlink($path);

    $client->rename($file, 'y.txt');
    $client->move($file, $a, $b);
    $stored = fakeDriveFiles()[$file];
    assertSame('y.txt', $stored['name']);
    assertSame([$b], $stored['parents']);

    $client->trash($file);
    assertSame(DriveError::NOT_FOUND, driveErrorKind(fn() => downloaded($client, $file)));
    assertSame('none', driveErrorKind(fn() => $client->trash('does-not-exist')), 'trashing what is already gone is not an error');
});

test('a full Drive, a revoked grant and a stale access token are each told apart', function (TestClient $_c): void {
    $client = connectedClient('full@example.com');
    $path = tempnam(sys_get_temp_dir(), 'drv');
    file_put_contents($path, str_repeat('x', 2000));
    fakeGoogle('POST', '/_fake/quota?bytes=1000');
    try {
        assertSame(DriveError::QUOTA, driveErrorKind(fn() => $client->upload($path, 'big.bin', 'application/octet-stream', 'root')));
    } finally {
        fakeGoogle('POST', '/_fake/quota?bytes=0');
    }
    unlink($path);

    // access token หมดอายุแล้ว: ขอใหม่ด้วย refresh token เอง แล้วแจ้งให้ผู้เรียกบันทึก
    $refreshed = null;
    $fresh = connectedClient('stale@example.com', $refreshed);
    $tokens = (new ReflectionProperty(DriveClient::class, 'refreshToken'))->getValue($fresh);
    $stale = new DriveClient($tokens, 'at-expired', time() - 10, function (string $token, int $expiresAt) use (&$refreshed): void {
        $refreshed = [$token, $expiresAt];
    });
    assertSame('stale@example.com', $stale->email());
    assertTrue(is_array($refreshed) && str_starts_with($refreshed[0], 'at-') && $refreshed[1] > time(), 'the new access token is handed back to be saved');

    // ผู้ใช้ยกเลิกสิทธิ์ที่หน้าบัญชี Google (EDGE-11)
    fakeGoogle('POST', '/_fake/revoke?email=' . rawurlencode('stale@example.com'));
    $revoked = new DriveClient($tokens, null, 0, fn() => null);
    assertSame(DriveError::AUTH, driveErrorKind(fn() => $revoked->email()));
});
