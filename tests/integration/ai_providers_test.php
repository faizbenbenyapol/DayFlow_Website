<?php
// =====================================================
// tests/integration/ai_providers_test.php
//
// ผู้ให้บริการ AI ที่เพิ่มในเฟส 5 และช่อง "OpenAI-compatible กำหนด URL เอง"
// ยิงผ่าน API จริงโดยชี้ไปที่ AI ปลอม (tests/fake-llm) จึงไม่ต้องใช้คีย์จริง
// =====================================================

declare(strict_types=1);

require_once __DIR__ . '/../fake-llm/helpers.php';

function aiProviderClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('aip_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

/** บันทึกผู้ให้บริการ custom ที่ชี้ AI ปลอม (คีย์ไม่จริง) */
function saveFakeCustomKey(TestClient $client, string $apiKey = 'sk-fake-custom-key'): void
{
    $response = $client->post('/api/ai/keys', [
        'provider' => 'custom',
        'api_key'  => $apiKey,
        'base_url' => FAKE_LLM_URL . '/v1',
        'model'    => 'fake-model',
    ]);
    assertSame(200, $response['status'], 'custom endpoint should be saved');
}

test('the provider list has all 13 providers and no Replicate among chat providers', function (TestClient $_c): void {
    $client = aiProviderClient('list');
    $data   = $client->json($client->get('/api/ai/keys'));

    $providers = $data['providers'] ?? [];
    assertSame(13, count($providers));
    foreach (['openai', 'gemini', 'anthropic', 'replicate', 'kimi', 'openrouter', 'deepseek', 'mistral', 'groq', 'xai', 'together', 'qwen', 'custom'] as $p) {
        assertContains($p, $providers);
    }
    assertNotContains('replicate', $data['chat_providers'] ?? []);
    assertSame('OpenAI-compatible (URL เอง)', $data['labels']['custom'] ?? null);
    assertSame('deepseek-chat', $data['default_models']['deepseek'] ?? null);
    assertSame('', $data['default_models']['custom'] ?? null, 'custom has no default model');
});

test('custom needs a base URL and a well-formed model name', function (TestClient $_c): void {
    $client = aiProviderClient('validate');
    $base   = ['provider' => 'custom', 'api_key' => 'fake-key-value', 'model' => 'fake-model'];

    assertSame(422, $client->post('/api/ai/keys', $base)['status'], 'no base_url');
    assertSame(422, $client->post('/api/ai/keys', ['model' => 'bad model!', 'base_url' => FAKE_LLM_URL . '/v1'] + $base)['status'], 'bad model');
});

test('a stored custom endpoint lists its URL and model but never the key', function (TestClient $_c): void {
    $client = aiProviderClient('custom');
    $secret = 'sk-test-custom-secret-key-value';

    saveFakeCustomKey($client, $secret);

    $listing = $client->get('/api/ai/keys');
    assertTrue(!str_contains($listing['body'], $secret), 'the raw key must not leave the server');
    $entry = $client->json($listing)['keys']['custom'] ?? [];
    assertSame(FAKE_LLM_URL . '/v1', $entry['base_url'] ?? null);
    assertSame('fake-model', $entry['model'] ?? null);
    assertTrue($entry['set'] ?? false);
});

test('testing a custom endpoint gives Thai errors naming the provider', function (TestClient $_c): void {
    $client = aiProviderClient('custom');
    saveFakeCustomKey($client);

    $ok = $client->post('/api/ai/keys/test', ['provider' => 'custom']);
    assertSame(200, $ok['status'], 'the stored endpoint should pass');

    $bad = $client->post('/api/ai/keys/test', [
        'provider' => 'custom', 'api_key' => 'bad-key',
        'base_url' => FAKE_LLM_URL . '/v1', 'model' => 'fake-model',
    ]);
    assertSame(400, $bad['status']);
    $badError = (string)($client->json($bad)['error'] ?? '');
    assertStringContains('OpenAI-compatible', $badError);
    assertStringContains('ไม่ถูกต้อง', $badError);

    $broke = $client->post('/api/ai/keys/test', [
        'provider' => 'custom', 'api_key' => 'broke-key',
        'base_url' => FAKE_LLM_URL . '/v1', 'model' => 'fake-model',
    ]);
    assertSame(400, $broke['status']);
    assertStringContains('เครดิต', (string)($client->json($broke)['error'] ?? ''));
});

test('script generation calls the stored custom endpoint', function (TestClient $_c): void {
    $client = aiProviderClient('custom');
    saveFakeCustomKey($client);

    fakeLlmReset();
    fakeLlmQueue([json_encode([
        'title'       => 'ทดสอบ',
        'hook'        => 'สามวินาทีแรก',
        'script'      => [['scene' => 1, 'time' => '0-6s', 'narration' => 'สวัสดี', 'visual' => 'cinematic']],
        'cta'         => 'กดติดตาม',
        'description' => 'คำอธิบายใต้คลิป',
        'hashtags'    => ['#ทดสอบ', '#dayflow'],
    ], JSON_UNESCAPED_UNICODE)]);

    $response = $client->post('/api/ai/script', [
        'keyword' => 'ทดสอบ', 'platform' => 'tiktok', 'style' => 'informative',
        'language' => 'th', 'duration_sec' => 30, 'provider' => 'custom',
    ]);
    assertSame(200, $response['status']);
    assertTrue($client->json($response)['ok'] ?? false);

    $sent = fakeLlmRequests();
    assertSame(1, count($sent));
    assertSame('fake-model', $sent[0]['model'] ?? null, 'the stored model should reach the provider');
});

test('keep_key changes only the model and the stored key still works', function (TestClient $_c): void {
    $client = aiProviderClient('keep');
    saveFakeCustomKey($client);

    $saved = $client->post('/api/ai/keys', [
        'provider' => 'custom',
        'api_key'  => '',
        'keep_key' => true,
        'base_url' => FAKE_LLM_URL . '/v1',
        'model'    => 'fake-model-2',
    ]);
    assertSame(200, $saved['status'], 'keeping the key should save the new model');

    $entry = $client->json($client->get('/api/ai/keys'))['keys']['custom'] ?? [];
    assertSame('fake-model-2', $entry['model'] ?? null);
    assertTrue($entry['set'] ?? false, 'the stored key must survive');

    $test = $client->post('/api/ai/keys/test', ['provider' => 'custom']);
    assertSame(200, $test['status'], 'the kept key should still pass the test endpoint');
});

test('keep_key without a stored key is rejected', function (TestClient $_c): void {
    $client = aiProviderClient('keepmissing');

    $response = $client->post('/api/ai/keys', [
        'provider' => 'custom',
        'api_key'  => '',
        'keep_key' => true,
        'base_url' => FAKE_LLM_URL . '/v1',
        'model'    => 'fake-model',
    ]);
    assertSame(422, $response['status']);
    assertSame('กรุณากรอก API key', $client->json($response)['error'] ?? null);
});

test('the chat tab is drawn only for an account with a chat key', function (TestClient $_c): void {
    $withoutKey = aiProviderClient('pagenokey');
    $page = $withoutKey->get('/ai');
    assertSame(200, $page['status']);
    assertTrue(!str_contains($page['body'], 'data-tab="chat"'), 'no key means no chat tab');

    $withKey = aiProviderClient('pagekey');
    saveFakeCustomKey($withKey);
    $page = $withKey->get('/ai');
    assertSame(200, $page['status']);
    assertTrue(str_contains($page['body'], 'data-tab="chat"'), 'a chat key adds the chat tab');
});
