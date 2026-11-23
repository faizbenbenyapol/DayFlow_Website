<?php
// =====================================================
// tests/fake-google/helpers.php — ใช้ร่วมกันใน test ของ Google Drive (เฟส 4)
// คุยกับ Google ปลอม (router.php ข้าง ๆ) และเชื่อม Drive ให้บัญชีทดสอบโดยไม่ต้องผ่านเบราว์เซอร์
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';
require_once ROOT . '/core/DriveClient.php';
require_once ROOT . '/models/DriveLink.php';

/** ที่อยู่ของ Google ปลอมจากในคอนเทนเนอร์ (หน้าขออนุญาตใน config ชี้ localhost สำหรับเบราว์เซอร์) */
const FAKE_GOOGLE = 'http://fake-google:8090';

/** เรียกประตูหลังของ Google ปลอม หรือหน้าขออนุญาต @return array{status: int, location: string, body: string} */
function fakeGoogle(string $method, string $path): array
{
    $ch = curl_init(FAKE_GOOGLE . $path);
    $location = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
            return strlen($line);
        },
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'location' => $location, 'body' => $body];
}

/** ไฟล์ทั้งหมดใน Google ปลอม เรียงตาม id @return array<string, array> */
function fakeDriveFiles(): array
{
    return array_column(json_decode(fakeGoogle('GET', '/_fake/files')['body'], true)['files'], null, 'id');
}

/** ผ่านหน้าขออนุญาตของ Google ปลอมในชื่อ $email @return array{refresh_token: string, access_token: string, expires_at: int} */
function fakeGrant(string $email): array
{
    $auth = fakeGoogle('GET', '/auth?' . http_build_query(['redirect_uri' => 'http://x/cb', 'state' => 's', 'login_hint' => $email]));
    parse_str((string)parse_url($auth['location'], PHP_URL_QUERY), $back);
    return DriveClient::exchangeCode($back['code']);
}

/** DriveClient ที่เชื่อมแล้วในชื่อ $email $refreshed ได้ [token, expiresAt] เมื่อมีการต่ออายุ access token */
function connectedClient(string $email, ?array &$refreshed = null): DriveClient
{
    $tokens = fakeGrant($email);
    return new DriveClient($tokens['refresh_token'], $tokens['access_token'], $tokens['expires_at'], function (string $token, int $expiresAt) use (&$refreshed): void {
        $refreshed = [$token, $expiresAt];
    });
}

/**
 * เชื่อม Drive ให้บัญชีทดสอบตรง ๆ (ข้ามหน้าตั้งค่า) พร้อมโฟลเดอร์ DayFlow ใน Drive
 * @return array{user_id: int, folder: string}
 */
function linkDrive(string $username, string $email, string $state = 'connected'): array
{
    $userId = (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetch()['id'];
    $tokens = fakeGrant($email);
    $folder = (new DriveClient($tokens['refresh_token'], $tokens['access_token'], $tokens['expires_at'], fn() => null))->createFolder('DayFlow', 'root');
    DriveLink::connect($userId, $email, $tokens, $folder, 'DayFlow', $state);
    return ['user_id' => $userId, 'folder' => $folder];
}
