<?php
// =====================================================
// tests/fake-google/router.php — Google ปลอมสำหรับทดสอบ Drive (เฟส 4) ห้ามใช้ใน production
//
// รันด้วย PHP built-in server: service fake-google ใน docker-compose.override.yml (พอร์ต 8090)
// จำลองเฉพาะที่ core/DriveClient.php ใช้: หน้าขออนุญาต, token, revoke, about,
// files (สร้าง แก้ ย้าย ลงถังขยะ อ่าน), resumable upload
// ประตูหลังสำหรับ test:
//   POST /_fake/reset                 ล้างทุกอย่าง
//   POST /_fake/quota?bytes=N         Drive ของทุกบัญชีจุได้ N ไบต์ (EDGE-12) 0 = ไม่จำกัด
//   POST /_fake/revoke?email=x        ผู้ใช้ยกเลิกสิทธิ์จากหน้าบัญชี Google (EDGE-11)
//   POST /_fake/truncate?bytes=N      ไฟล์ที่อัปโหลดเสร็จถัดไปเก็บขาดไป N ไบต์ (ครั้งเดียว) จำลองสำเนาไม่ครบ
//   GET  /_fake/files                 ไฟล์ทั้งหมด (ตรวจผลใน test)
// =====================================================

declare(strict_types=1);

const STATE_DIR = '/tmp/fake-google';
const CHUNK_ALIGN = 256 * 1024;   // Google ให้ส่งชิ้นของ resumable upload เป็นพหุคูณของ 256 KB

@mkdir(STATE_DIR . '/blobs', 0777, true);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

/** อ่าน แก้ แล้วเขียนสถานะทั้งหมดใต้ lock เดียว (built-in server รับทีละคำขออยู่แล้ว แต่ cron กับ test อาจยิงพร้อมกัน) */
function withState(callable $change): mixed
{
    $handle = fopen(STATE_DIR . '/state.json', 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '', true) ?: ['codes' => [], 'refresh' => [], 'access' => [], 'files' => [], 'sessions' => [], 'quota' => 0];
    $result = $change($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function reply(int $status, array $body = [], array $headers = []): never
{
    header('Content-Type: application/json');
    // ส่ง status ไปกับทุก header: PHP เปลี่ยนเป็น 302 เองเมื่อเห็น Location แต่ Google ตอบ 200 พร้อม Location
    foreach ($headers as $name => $value) header("{$name}: {$value}", true, $status);
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function driveError(int $status, string $reason, string $message): never
{
    reply($status, ['error' => ['code' => $status, 'message' => $message, 'errors' => [['reason' => $reason, 'message' => $message]]]]);
}

/** อีเมลเจ้าของ access token ใน Authorization หรือตอบ 401 */
function requireUser(): string
{
    $token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $email = withState(fn(array $s) => $s['access'][$token] ?? null);
    if ($email === null) driveError(401, 'authError', 'Invalid Credentials');
    return $email;
}

function usedBytes(array $state, string $email): int
{
    return array_sum(array_map(fn(array $f) => $f['owner'] === $email && !$f['trashed'] ? $f['size'] : 0, $state['files']));
}

function metadata(array $file): array
{
    return ['id' => $file['id'], 'name' => $file['name'], 'mimeType' => $file['mimeType'], 'parents' => $file['parents'],
            'size' => (string)$file['size'], 'trashed' => $file['trashed']];
}

// ---------- ประตูหลังสำหรับ test ----------
if ($path === '/_fake/reset' && $method === 'POST') {
    array_map('unlink', glob(STATE_DIR . '/blobs/*') ?: []);
    @unlink(STATE_DIR . '/state.json');
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/quota' && $method === 'POST') {
    withState(function (array &$s) use ($query) { $s['quota'] = (int)($query['bytes'] ?? 0); });
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/revoke' && $method === 'POST') {
    withState(function (array &$s) use ($query) {
        $s['refresh'] = array_filter($s['refresh'], fn($e) => $e !== ($query['email'] ?? ''));
        $s['access'] = array_filter($s['access'], fn($e) => $e !== ($query['email'] ?? ''));
    });
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/truncate' && $method === 'POST') {
    withState(function (array &$s) use ($query) { $s['truncate'] = (int)($query['bytes'] ?? 0); });
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/files') {
    reply(200, ['files' => array_values(withState(fn(array $s) => $s['files']))]);
}

// ---------- OAuth ----------
if ($path === '/auth') {
    // อนุญาตทันที อีเมลมาจาก login_hint (ค่าเริ่มต้น tester@example.com) ผู้ใช้กดปฏิเสธจำลองด้วย ?deny=1
    if (!empty($query['deny'])) {
        header('Location: ' . $query['redirect_uri'] . '?error=access_denied&state=' . rawurlencode((string)$query['state']));
        exit;
    }
    $code = 'code-' . bin2hex(random_bytes(8));
    withState(function (array &$s) use ($code, $query) { $s['codes'][$code] = $query['login_hint'] ?? 'tester@example.com'; });
    header('Location: ' . $query['redirect_uri'] . '?code=' . $code . '&state=' . rawurlencode((string)$query['state']));
    exit;
}
if ($path === '/token' && $method === 'POST') {
    $result = withState(function (array &$s) {
        if (($_POST['client_secret'] ?? '') === '') return ['error' => 'invalid_client'];
        if (($_POST['grant_type'] ?? '') === 'authorization_code') {
            $email = $s['codes'][$_POST['code'] ?? ''] ?? null;
            if ($email === null) return ['error' => 'invalid_grant'];
            unset($s['codes'][$_POST['code']]);
            $refresh = 'rt-' . bin2hex(random_bytes(8));
            $s['refresh'][$refresh] = $email;
        } else {
            $email = $s['refresh'][$_POST['refresh_token'] ?? ''] ?? null;
            if ($email === null) return ['error' => 'invalid_grant'];
        }
        $access = 'at-' . bin2hex(random_bytes(8));
        $s['access'][$access] = $email;
        return ['access_token' => $access, 'expires_in' => 3599, 'token_type' => 'Bearer']
            + (isset($refresh) ? ['refresh_token' => $refresh] : []);
    });
    reply(isset($result['error']) ? 400 : 200, $result);
}
if ($path === '/revoke' && $method === 'POST') {
    withState(function (array &$s) {
        $token = $_POST['token'] ?? '';
        $email = $s['refresh'][$token] ?? $s['access'][$token] ?? null;
        $s['refresh'] = array_filter($s['refresh'], fn($e) => $e !== $email);
        $s['access'] = array_filter($s['access'], fn($e) => $e !== $email);
    });
    reply(200);
}

// ---------- Drive ----------
if ($path === '/drive/v3/about') {
    $email = requireUser();
    reply(200, ['user' => ['emailAddress' => $email]]);
}

if ($path === '/drive/v3/files' && $method === 'POST') {
    $email = requireUser();
    $meta = json_decode(file_get_contents('php://input'), true) ?: [];
    $file = withState(function (array &$s) use ($email, $meta) {
        $id = 'f' . bin2hex(random_bytes(6));
        $s['files'][$id] = ['id' => $id, 'owner' => $email, 'name' => $meta['name'] ?? 'untitled', 'mimeType' => $meta['mimeType'] ?? 'application/octet-stream',
                            'parents' => $meta['parents'] ?? ['root'], 'size' => 0, 'trashed' => false];
        return $s['files'][$id];
    });
    reply(200, metadata($file));
}

if ($path === '/upload/drive/v3/files' && $method === 'POST' && ($query['uploadType'] ?? '') === 'resumable') {
    $email = requireUser();
    $meta = json_decode(file_get_contents('php://input'), true) ?: [];
    $total = (int)($_SERVER['HTTP_X_UPLOAD_CONTENT_LENGTH'] ?? 0);
    $session = withState(function (array &$s) use ($email, $meta, $total) {
        if ($s['quota'] > 0 && usedBytes($s, $email) + $total > $s['quota']) return null;
        $sid = 's' . bin2hex(random_bytes(8));
        $s['sessions'][$sid] = ['owner' => $email, 'meta' => $meta, 'total' => $total];
        return $sid;
    });
    if ($session === null) driveError(403, 'storageQuotaExceeded', 'The user\'s Drive storage quota has been exceeded.');
    $host = $_SERVER['HTTP_HOST'];
    reply(200, [], ['Location' => "http://{$host}/upload/session/{$session}"]);
}

if (preg_match('#^/upload/session/(s[0-9a-f]+)$#', $path, $m) && $method === 'PUT') {
    $sid = $m[1];
    $session = withState(fn(array $s) => $s['sessions'][$sid] ?? null);
    if ($session === null) driveError(404, 'notFound', 'Upload session not found');
    $blob = STATE_DIR . '/blobs/' . $sid;
    $have = is_file($blob) ? filesize($blob) : 0;
    $bytes = file_get_contents('php://input');
    if (!preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $_SERVER['HTTP_CONTENT_RANGE'] ?? '', $range) && $session['total'] > 0) {
        driveError(400, 'badRequest', 'Missing Content-Range');
    }
    $start = (int)($range[1] ?? 0);
    if ($start !== $have) driveError(400, 'badRequest', "Expected offset {$have}, got {$start}");
    $isLast = $session['total'] === 0 || (int)$range[2] + 1 === $session['total'];
    if (!$isLast && strlen($bytes) % CHUNK_ALIGN !== 0) driveError(400, 'badRequest', 'Chunk size must be a multiple of 256 KB');
    file_put_contents($blob, $bytes, FILE_APPEND);
    clearstatcache();
    if (!$isLast) {
        http_response_code(308);
        header('Range: bytes=0-' . (filesize($blob) - 1));
        exit;
    }
    $file = withState(function (array &$s) use ($sid, $session, $blob) {
        if (($s['truncate'] ?? 0) > 0) {   // ประตูหลัง /_fake/truncate
            $handle = fopen($blob, 'r+');
            ftruncate($handle, max(0, filesize($blob) - $s['truncate']));
            fclose($handle);
            clearstatcache();
            $s['truncate'] = 0;
        }
        $id = 'f' . bin2hex(random_bytes(6));
        rename($blob, STATE_DIR . '/blobs/' . $id);
        unset($s['sessions'][$sid]);
        $s['files'][$id] = ['id' => $id, 'owner' => $session['owner'], 'name' => $session['meta']['name'] ?? 'untitled',
                            'mimeType' => $session['meta']['mimeType'] ?? 'application/octet-stream', 'parents' => $session['meta']['parents'] ?? ['root'],
                            'size' => filesize(STATE_DIR . '/blobs/' . $id), 'trashed' => false];
        return $s['files'][$id];
    });
    reply(200, metadata($file));
}

if (preg_match('#^/drive/v3/files/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $email = requireUser();
    $id = $m[1];
    $file = withState(fn(array $s) => $s['files'][$id] ?? null);
    // drive.file เห็นเฉพาะไฟล์ที่แอปสร้าง ของคนอื่นหรือไม่มีอยู่ตอบ 404 เหมือนกัน
    if ($file === null || $file['owner'] !== $email) driveError(404, 'notFound', "File not found: {$id}.");

    if ($method === 'GET') {
        if (($query['alt'] ?? '') === 'media') {
            if ($file['trashed']) driveError(404, 'notFound', "File not found: {$id}.");
            header('Content-Type: ' . $file['mimeType']);
            header('Content-Length: ' . $file['size']);
            readfile(STATE_DIR . '/blobs/' . $id);
            exit;
        }
        reply(200, metadata($file));
    }
    if ($method === 'PATCH') {
        $patch = json_decode(file_get_contents('php://input'), true) ?: [];
        $file = withState(function (array &$s) use ($id, $patch, $query) {
            $f = &$s['files'][$id];
            if (isset($patch['name'])) $f['name'] = $patch['name'];
            if (isset($patch['trashed'])) $f['trashed'] = (bool)$patch['trashed'];
            if (!empty($query['addParents'])) {
                $f['parents'] = array_values(array_diff($f['parents'], explode(',', (string)($query['removeParents'] ?? ''))));
                $f['parents'][] = $query['addParents'];
            }
            return $f;
        });
        reply(200, metadata($file));
    }
}

driveError(404, 'notFound', "No fake route for {$method} {$path}");
