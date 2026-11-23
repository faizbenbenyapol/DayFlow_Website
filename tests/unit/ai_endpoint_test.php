<?php
// =====================================================
// tests/unit/ai_endpoint_test.php
//
// AiEndpoint กัน SSRF: URL ที่ผู้ใช้ใส่เองต้องชี้ที่อยู่สาธารณะ เว้นแต่เจ้าของระบบเปิด
// AI_ALLOW_PRIVATE_URLS และ LlmClient::explain ต้องแปล error เป็นภาษาไทยพร้อมชื่อผู้ให้บริการ
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/AiEndpoint.php';
require_once ROOT . '/core/HttpJson.php';
require_once ROOT . '/core/LlmClient.php';

/** เรียก pin() แล้วคืนข้อความ error (throw TestFailure เมื่อไม่มี error) */
function pinRejection(string $url, bool $allowPrivate = false): string
{
    try {
        AiEndpoint::pin($url, $allowPrivate);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    throw new TestFailure($url . ' ต้องถูกปฏิเสธ');
}

test('pin refuses every private address and unsupported scheme', function (): void {
    $refused = [
        'http://127.0.0.1/v1',
        'http://localhost:11434/v1',
        'http://10.0.0.5/',
        'http://169.254.169.254/latest',
        'http://[::1]/',
        'http://[::ffff:127.0.0.1]/',
    ];
    foreach ($refused as $url) {
        assertStringContains('ที่อยู่ภายใน', pinRejection($url), $url);
    }

    assertStringContains('http://', pinRejection('ftp://1.1.1.1/'), 'ftp ต้องไม่ผ่าน');
    assertStringContains('ชื่อผู้ใช้', pinRejection('http://user:pass@1.1.1.1/'), 'user:pass ต้องไม่ผ่าน');
});

test('pin returns a public address in CURLOPT_RESOLVE form', function (): void {
    assertSame('1.1.1.1:443:1.1.1.1', AiEndpoint::pin('https://1.1.1.1/v1', false));
});

test('pin accepts a private address when the owner allows it', function (): void {
    assertSame('127.0.0.1:11434:127.0.0.1', AiEndpoint::pin('http://127.0.0.1:11434/v1', true));
});

test('explain names the provider and the kind of failure', function (): void {
    $cases = [
        ['deepseek', 401, 'HTTP 401: Incorrect API key provided', 'DeepSeek: API key ไม่ถูกต้องหรือไม่มีสิทธิ์'],
        ['deepseek', 403, 'HTTP 403: Forbidden', 'DeepSeek: API key ไม่ถูกต้องหรือไม่มีสิทธิ์'],
        ['deepseek', 402, 'HTTP 402: Payment required', 'DeepSeek: เครดิตหมดหรือเกินโควตา'],
        ['openai', 400, 'HTTP 400: Your credit balance is too low', 'OpenAI: เครดิตหมดหรือเกินโควตา'],
        ['groq', 429, 'HTTP 429: Too many requests', 'Groq: เรียกถี่เกินไป ลองใหม่อีกสักครู่'],
        ['mistral', 404, 'HTTP 404: Not found', 'Mistral: ไม่พบโมเดลหรือ URL นี้'],
        ['xai', 0, 'HTTP error: Could not resolve host', 'xAI Grok: ติดต่อผู้ให้บริการไม่ได้'],
        ['together', 503, 'HTTP 503: Unavailable', 'Together AI: ระบบของผู้ให้บริการขัดข้อง'],
        ['qwen', 418, 'HTTP 418: teapot', 'Qwen (Alibaba): เรียก AI ไม่สำเร็จ'],
    ];

    foreach ($cases as [$provider, $code, $message, $expected]) {
        assertSame($expected, LlmClient::explain($provider, new RuntimeException($message, $code)));
    }
});

test('explain passes an AiEndpoint message through under the provider name', function (): void {
    try {
        AiEndpoint::pin('http://127.0.0.1/v1', false);
    } catch (RuntimeException $e) {
        $message = LlmClient::explain('custom', $e);
    }

    assertStringContains('OpenAI-compatible', $message);
    assertStringContains('ที่อยู่ภายใน', $message);
});
