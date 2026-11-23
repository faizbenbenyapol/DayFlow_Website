<?php
// =====================================================
// tests/fake-llm/router.php — OpenAI ปลอมสำหรับทดสอบผู้ให้บริการ AI (เฟส 5) ห้ามใช้ใน production
//
// รันด้วย PHP built-in server: service fake-llm ใน docker-compose.override.yml (พอร์ต 8091)
// ตอบเฉพาะที่ core/LlmClient.php ใช้: GET /v1/models และ POST /v1/chat/completions
// ประตูหลังสำหรับ test:
//   POST /_fake/reset      ล้างคิว คำตอบ และคำขอที่บันทึกไว้
//   POST /_fake/queue      body {"replies":[string,...]} ต่อท้ายคิวคำตอบ
//   GET  /_fake/requests   body ที่ AI ปลอมได้รับทั้งหมด
// =====================================================

declare(strict_types=1);

const STATE_FILE = '/tmp/fake-llm/state.json';

@mkdir(dirname(STATE_FILE), 0777, true);
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body   = json_decode(file_get_contents('php://input') ?: '', true);
$body   = is_array($body) ? $body : [];

/** อ่าน แก้ แล้วเขียนสถานะทั้งหมดใต้ lock เดียว @return mixed ผลของ $change */
function withState(callable $change): mixed
{
    $handle = fopen(STATE_FILE, 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '', true) ?: ['queue' => [], 'requests' => []];
    $result = $change($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

/** ตอบ JSON แล้วจบคำขอ */
function reply(int $status, array $payload): never
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ---------- ประตูหลังสำหรับ test ----------
if ($path === '/_fake/reset' && $method === 'POST') {
    withState(function (array &$s): void { $s = ['queue' => [], 'requests' => []]; });
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/queue' && $method === 'POST') {
    withState(function (array &$s) use ($body): void {
        foreach (($body['replies'] ?? []) as $text) $s['queue'][] = (string)$text;
    });
    reply(200, ['ok' => true]);
}
if ($path === '/_fake/requests') {
    reply(200, ['requests' => withState(fn(array $s) => $s['requests'])]);
}

// ---------- ตรวจคีย์ปลอม ----------
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($token === 'bad-key')  reply(401, ['error' => ['message' => 'Incorrect API key provided']]);
if ($token === 'broke-key') reply(402, ['error' => ['message' => 'Insufficient balance']]);

// ---------- OpenAI-compatible ----------
if ($path === '/v1/models' && $method === 'GET') {
    reply(200, ['data' => [['id' => 'fake-model']]]);
}

if ($path === '/v1/chat/completions' && $method === 'POST') {
    $content = withState(function (array &$s) use ($body): string {
        $s['requests'][] = $body;
        return array_shift($s['queue']) ?? '{"reply":"คิวว่าง"}';
    });
    reply(200, ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]);
}

reply(404, ['error' => ['message' => "No fake route for {$method} {$path}"]]);
