<?php
// =====================================================
// tests/fake-llm/helpers.php — ใช้ร่วมกันใน test ของผู้ให้บริการ AI (เฟส 5)
// เรียกประตูหลังของ AI ปลอม (router.php ข้าง ๆ) จากในคอนเทนเนอร์แอป
// =====================================================

declare(strict_types=1);

/** ที่อยู่ของ AI ปลอมจากในคอนเทนเนอร์ */
const FAKE_LLM_URL = 'http://fake-llm:8091';

/**
 * เรียกประตูหลังของ AI ปลอม
 *
 * @param array|null $body ส่งเป็น JSON เมื่อไม่ใช่ null
 * @return array{status: int, body: string}
 */
function fakeLlmCall(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(FAKE_LLM_URL . $path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $raw];
}

/** ล้างคิวคำตอบและคำขอที่บันทึกไว้ทั้งหมด */
function fakeLlmReset(): void
{
    fakeLlmCall('POST', '/_fake/reset');
}

/** ต่อท้ายคิวคำตอบที่จะถูกหยิบไปตอบ POST /v1/chat/completions ตามลำดับ */
function fakeLlmQueue(array $replies): void
{
    fakeLlmCall('POST', '/_fake/queue', ['replies' => array_values($replies)]);
}

/** body ของทุกคำขอที่ AI ปลอมได้รับตั้งแต่ reset ครั้งล่าสุด @return array */
function fakeLlmRequests(): array
{
    $data = json_decode(fakeLlmCall('GET', '/_fake/requests')['body'], true);
    return is_array($data['requests'] ?? null) ? $data['requests'] : [];
}
